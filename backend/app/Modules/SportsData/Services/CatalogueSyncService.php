<?php

namespace App\Modules\SportsData\Services;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Data\EventData;
use App\Modules\SportsData\Data\MarketData;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Models\Competition;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Models\OddsSnapshot;
use App\Modules\SportsData\Models\Sport;
use App\Modules\SportsData\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Synchronise les matchs et cotes d'une compétition depuis le fournisseur (P3-03).
 *
 * - Un seul appel au fournisseur par compétition (coût : marchés × régions crédits).
 * - Idempotent : rejouer les mêmes données ne crée rien ; la version de cote n'augmente
 *   que si une cote change, avec un historique (odds_snapshots).
 * - Validation avant écriture ; un événement invalide est ignoré et journalisé.
 * - En cas d'échec du fournisseur, rien n'est modifié : les cotes vieillissent et le catalogue
 *   les masque (règle de fraîcheur, Market::scopeBettable).
 */
class CatalogueSyncService
{
    /** Motif de suspension posé par le système (levé automatiquement quand des cotes fraîches arrivent). */
    public const AUTO_SUSPENSION_PREFIX = 'auto:';

    private const SPORT_NAMES = [
        'soccer' => 'Football',
        'basketball' => 'Basketball',
        'americanfootball' => 'Football américain',
        'baseball' => 'Baseball',
        'icehockey' => 'Hockey sur glace',
        'tennis' => 'Tennis',
        'mma' => 'Arts martiaux mixtes',
        'boxing' => 'Boxe',
        'rugbyleague' => 'Rugby à XIII',
        'rugbyunion' => 'Rugby',
        'cricket' => 'Cricket',
        'handball' => 'Handball',
    ];

    /** Clés de sélection du marché 1X2 (h2h). */
    private const DRAW_NAMES = ['draw', 'tie', 'nul', 'match nul'];

    public function __construct(private readonly SportsDataProvider $provider) {}

    public function sync(string $competitionKey): SyncReport
    {
        $report = new SyncReport($competitionKey);

        try {
            $events = $this->provider->events($competitionKey);
        } catch (SportsDataException $e) {
            $report->error = "{$e->reason}: {$e->getMessage()}";
            Log::warning('sports_data.sync_failed', $report->toArray());

            return $report;
        }

        $horizon = now()->addDays((int) config('sports_data.horizon_days', 7));

        $selected = collect($events)
            ->filter(fn (EventData $e) => $e->startsAt->isFuture() && $e->startsAt->lte($horizon))
            ->sortBy(fn (EventData $e) => $e->startsAt->getTimestamp())
            ->take((int) config('sports_data.max_events_per_competition', 10));

        $report->skipped += count($events) - $selected->count();

        foreach ($selected as $data) {
            try {
                DB::transaction(fn () => $this->syncEvent($data, $report));
            } catch (Throwable $e) {
                $report->skipped++;
                Log::warning('sports_data.event_rejected', ['competition' => $competitionKey, 'id' => $data->externalId, 'error' => $e->getMessage()]);
            }
        }

        Log::info('sports_data.sync_done', $report->toArray());

        return $report;
    }

    private function syncEvent(EventData $data, SyncReport $report): void
    {
        if (mb_strtolower(trim($data->homeTeam)) === mb_strtolower(trim($data->awayTeam))) {
            throw new \InvalidArgumentException('Équipes identiques.');
        }

        $competition = $this->competition($data);
        $sportId = $competition->sport_id;

        $event = Event::firstOrNew(['provider' => $this->provider->name(), 'external_id' => $data->externalId]);
        $isNew = ! $event->exists;

        // Un événement terminé, annulé ou réglé n'est plus modifié par la synchro des cotes.
        if (! $isNew && ! in_array($event->status, [EventStatus::Scheduled, EventStatus::Postponed], true)) {
            $report->skipped++;

            return;
        }

        $event->fill([
            'competition_id' => $competition->id,
            'home_team_id' => Team::firstOrCreate(['sport_id' => $sportId, 'name' => trim($data->homeTeam)])->id,
            'away_team_id' => Team::firstOrCreate(['sport_id' => $sportId, 'name' => trim($data->awayTeam)])->id,
            'starts_at' => $data->startsAt,
            'status' => EventStatus::Scheduled,
            'last_synced_at' => now(),
        ])->save();

        $isNew ? $report->eventsCreated++ : $report->eventsUpdated++;

        $market = $this->pickMarket($data, 'h2h');
        if ($market !== null && $this->syncMarket($event, $data, $market)) {
            $report->oddsChanged++;
        }
    }

    private function competition(EventData $data): Competition
    {
        $slug = explode('_', $data->sportKey)[0];

        $sport = Sport::firstOrCreate(
            ['slug' => $slug],
            ['name' => self::SPORT_NAMES[$slug] ?? ucfirst($slug)],
        );

        $label = config("sports_data.competition_labels.{$data->sportKey}");

        $competition = Competition::firstOrCreate(
            ['provider' => $this->provider->name(), 'external_key' => $data->sportKey],
            ['sport_id' => $sport->id, 'name' => $label['name'] ?? $data->competition, 'country' => $label['country'] ?? null],
        );

        // Un libellé configuré l'emporte sur le code du fournisseur (ex. "EPL" → "Premier League").
        if ($label !== null && ($competition->name !== $label['name'] || $competition->country !== ($label['country'] ?? null))) {
            $competition->update(['name' => $label['name'], 'country' => $label['country'] ?? null]);
        }

        return $competition;
    }

    /** Marché du bookmaker de référence (déjà filtré par le fournisseur s'il est configuré), sinon le premier. */
    private function pickMarket(EventData $data, string $key): ?MarketData
    {
        foreach ($data->markets as $market) {
            if ($market->key === $key && count($market->selections) >= 2) {
                return $market;
            }
        }

        return null;
    }

    /** @return bool true si les cotes ont changé (nouvelle version) */
    private function syncMarket(Event $event, EventData $data, MarketData $source): bool
    {
        $odds = [];
        foreach ($source->selections as $selection) {
            $key = $this->selectionKey($selection->name, $data);
            $odds[$key] = ['name' => $key === 'draw' ? 'Match nul' : $selection->name, 'odds' => $selection->odds];
        }

        if (! isset($odds['home'], $odds['away'])) {
            throw new \InvalidArgumentException('Marché 1X2 incomplet (équipe à domicile ou à l\'extérieur absente).');
        }

        /** @var Market $market */
        $market = $event->markets()->firstOrNew(['key' => $source->key]);

        // Cote plus ancienne que celle déjà enregistrée (réponses désordonnées) : ignorée.
        if ($market->exists && $market->odds_updated_at !== null && $source->updatedAt->lt($market->odds_updated_at)) {
            return false;
        }

        $current = $market->exists
            ? $market->selections()->pluck('odds', 'key')->map(fn ($o) => $this->normalize((string) $o))->all()
            : [];
        $incoming = array_map(fn ($s) => $this->normalize($s['odds']), $odds);
        ksort($current);
        ksort($incoming);
        $changed = $current !== $incoming;

        if (! $market->exists) {
            $market->odds_version = 1;
            $market->status = MarketStatus::Open;
        } elseif ($changed) {
            $market->odds_version++;
        }

        // Des cotes fraîches lèvent une suspension automatique, jamais une suspension posée par un administrateur.
        if ($market->status === MarketStatus::Suspended && str_starts_with((string) $market->suspension_reason, self::AUTO_SUSPENSION_PREFIX)) {
            $market->status = MarketStatus::Open;
            $market->suspension_reason = null;
        }

        $market->fill([
            'source_bookmaker' => $source->bookmaker,
            'odds_updated_at' => $source->updatedAt,
        ])->save();

        if (! $changed) {
            return false;
        }

        foreach ($odds as $key => $selection) {
            $model = $market->selections()->updateOrCreate(['key' => $key], $selection);

            OddsSnapshot::create([
                'selection_id' => $model->id,
                'odds_version' => $market->odds_version,
                'odds' => $selection['odds'],
                'provider_updated_at' => $source->updatedAt,
            ]);
        }

        // Une sélection disparue du fournisseur ne doit plus être proposée.
        $market->selections()->whereNotIn('key', array_keys($odds))->delete();

        return true;
    }

    private function selectionKey(string $name, EventData $data): string
    {
        $normalized = mb_strtolower(trim($name));

        return match (true) {
            $normalized === mb_strtolower(trim($data->homeTeam)) => 'home',
            $normalized === mb_strtolower(trim($data->awayTeam)) => 'away',
            in_array($normalized, self::DRAW_NAMES, true) => 'draw',
            default => throw new \InvalidArgumentException("Sélection inconnue : {$name}"),
        };
    }

    private function normalize(string $odds): string
    {
        return bcadd($odds, '0', 3);
    }
}
