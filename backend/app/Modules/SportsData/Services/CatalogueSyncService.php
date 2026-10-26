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
 *   que si une cote ou la ligne (totals) change, avec un historique (odds_snapshots).
 * - Validation avant écriture ; un événement invalide est ignoré et journalisé (un marché
 *   facultatif invalide n'est qu'ignoré, l'événement est gardé).
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
        'aussierules' => 'Football australien',
    ];

    /** Pays des titres du fournisseur (« Ligue 1 - France », « La Liga - Spain »), affichés en français. */
    private const COUNTRY_NAMES = [
        'Argentina' => 'Argentine', 'Australia' => 'Australie', 'Austria' => 'Autriche', 'Belgium' => 'Belgique',
        'Brazil' => 'Brésil', 'Chile' => 'Chili', 'China' => 'Chine', 'Denmark' => 'Danemark', 'England' => 'Angleterre',
        'Finland' => 'Finlande', 'France' => 'France', 'Germany' => 'Allemagne', 'Greece' => 'Grèce', 'Ireland' => 'Irlande',
        'Italy' => 'Italie', 'Japan' => 'Japon', 'Korea' => 'Corée du Sud', 'Mexico' => 'Mexique', 'Netherlands' => 'Pays-Bas',
        'Norway' => 'Norvège', 'Poland' => 'Pologne', 'Portugal' => 'Portugal', 'Russia' => 'Russie', 'Saudi Arabia' => 'Arabie saoudite',
        'Scotland' => 'Écosse', 'Spain' => 'Espagne', 'Sweden' => 'Suède', 'Switzerland' => 'Suisse', 'Turkey' => 'Turquie',
        'USA' => 'États-Unis', 'Uruguay' => 'Uruguay',
    ];

    /**
     * Marchés synchronisés, dans l'ordre. Le 1X2 (h2h) est obligatoire : invalide, il fait rejeter l'événement.
     * Les autres sont facultatifs (invalides, ils sont ignorés) : double chance (1X, 12, X2),
     * totals (plus/moins de N buts ou points) et btts (les deux équipes marquent : oui / non).
     */
    private const MARKETS = ['h2h', 'double_chance', 'totals', 'btts'];

    /** Clés de sélection du marché 1X2 (h2h). */
    private const DRAW_NAMES = ['draw', 'tie', 'nul', 'match nul'];

    /** Clés de sélection du marché totals. */
    private const TOTALS_NAMES = ['over' => 'over', 'under' => 'under'];

    /** Clés de sélection du marché btts. */
    private const BTTS_NAMES = ['yes' => 'yes', 'oui' => 'yes', 'no' => 'no', 'non' => 'no'];

    /** Sélections de la double chance : paire d'issues (dans l'ordre domicile, nul, extérieur) => clé. */
    private const DOUBLE_CHANCE_KEYS = ['home+draw' => 'home_draw', 'home+away' => 'home_away', 'draw+away' => 'draw_away'];

    public function __construct(private readonly SportsDataProvider $provider) {}

    /** Matchs à venir (dans l'horizon configuré). */
    public function sync(string $competitionKey): SyncReport
    {
        return $this->run($competitionKey, live: false);
    }

    /**
     * Matchs en cours (commencés depuis moins de live_window_minutes) : cotes en direct, statut "live".
     * Le fournisseur renvoie les matchs en cours dans le même appel que les matchs à venir.
     */
    public function syncLive(string $competitionKey): SyncReport
    {
        return $this->run($competitionKey, live: true);
    }

    private function run(string $competitionKey, bool $live): SyncReport
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
        $liveSince = now()->subMinutes((int) config('sports_data.live_window_minutes', 180));

        $selected = collect($events)
            ->filter(fn (EventData $e) => $live
                ? $e->startsAt->lte(now()) && $e->startsAt->gt($liveSince)
                : $e->startsAt->isFuture() && $e->startsAt->lte($horizon))
            ->sortBy(fn (EventData $e) => $e->startsAt->getTimestamp())
            ->take($live ? PHP_INT_MAX : (int) config('sports_data.max_events_per_competition', 10));

        $report->skipped += count($events) - $selected->count();

        foreach ($selected as $data) {
            try {
                DB::transaction(fn () => $this->syncEvent($data, $report, $live));
            } catch (Throwable $e) {
                $report->skipped++;
                Log::warning('sports_data.event_rejected', ['competition' => $competitionKey, 'id' => $data->externalId, 'error' => $e->getMessage()]);
            }
        }

        Log::info('sports_data.sync_done', $report->toArray());

        return $report;
    }

    private function syncEvent(EventData $data, SyncReport $report, bool $live = false): void
    {
        if (mb_strtolower(trim($data->homeTeam)) === mb_strtolower(trim($data->awayTeam))) {
            throw new \InvalidArgumentException('Équipes identiques.');
        }

        $competition = $this->competition($data);
        $sportId = $competition->sport_id;

        $event = Event::firstOrNew(['provider' => $this->provider->name(), 'external_id' => $data->externalId]);
        $isNew = ! $event->exists;

        // Un événement terminé, annulé ou réglé n'est plus modifié par la synchro des cotes ;
        // un match en cours ne l'est que par la synchro en direct.
        $updatable = $live ? [EventStatus::Scheduled, EventStatus::Live] : [EventStatus::Scheduled, EventStatus::Postponed];
        if (! $isNew && ! in_array($event->status, $updatable, true)) {
            $report->skipped++;

            return;
        }

        $event->fill([
            'competition_id' => $competition->id,
            'home_team_id' => Team::firstOrCreate(['sport_id' => $sportId, 'name' => trim($data->homeTeam)])->id,
            'away_team_id' => Team::firstOrCreate(['sport_id' => $sportId, 'name' => trim($data->awayTeam)])->id,
            'starts_at' => $data->startsAt,
            'status' => $live ? EventStatus::Live : EventStatus::Scheduled,
            'last_synced_at' => now(),
        ])->save();

        $isNew ? $report->eventsCreated++ : $report->eventsUpdated++;

        foreach (self::MARKETS as $key) {
            $market = $this->pickMarket($data, $key);
            if ($market === null) {
                continue;
            }

            try {
                [$selections, $line] = $this->selections($data, $market);
            } catch (\InvalidArgumentException $e) {
                if ($key === 'h2h') {
                    throw $e;
                }

                Log::warning('sports_data.market_rejected', ['id' => $data->externalId, 'market' => $key, 'error' => $e->getMessage()]);

                continue;
            }

            if ($this->syncMarket($event, $market, $selections, $line)) {
                $report->oddsChanged++;
            }
        }
    }

    private function competition(EventData $data): Competition
    {
        $slug = explode('_', $data->sportKey)[0];

        $sport = Sport::firstOrCreate(
            ['slug' => $slug],
            ['name' => self::SPORT_NAMES[$slug] ?? ucfirst($slug)],
        );

        // Un libellé configuré l'emporte sur le titre du fournisseur (ex. "EPL" → "Premier League").
        $label = config("sports_data.competition_labels.{$data->sportKey}") ?? $this->providerLabel($data->competition);

        $competition = Competition::firstOrCreate(
            ['provider' => $this->provider->name(), 'external_key' => $data->sportKey],
            ['sport_id' => $sport->id, 'name' => $label['name'], 'country' => $label['country'] ?? null],
        );

        if ($competition->name !== $label['name'] || $competition->country !== ($label['country'] ?? null)) {
            $competition->update(['name' => $label['name'], 'country' => $label['country'] ?? null]);
        }

        return $competition;
    }

    /**
     * Nom et pays tirés du titre du fournisseur : « Ligue 1 - France » → Ligue 1 / France,
     * « La Liga - Spain » → La Liga / Espagne ; sans pays reconnaissable, le titre entier.
     *
     * @return array{name: string, country: ?string}
     */
    private function providerLabel(string $title): array
    {
        $title = trim($title);

        if (preg_match('/^(.+?)\s+-\s+([^-]+)$/u', $title, $m) && isset(self::COUNTRY_NAMES[trim($m[2])])) {
            return ['name' => trim($m[1]), 'country' => self::COUNTRY_NAMES[trim($m[2])]];
        }

        return ['name' => $title, 'country' => null];
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

    /**
     * Sélections validées d'un marché, par clé, et sa ligne (null pour le 1X2).
     *
     * @return array{0: array<string, array{name: string, odds: string}>, 1: ?string}
     *
     * @throws \InvalidArgumentException marché invalide
     */
    private function selections(EventData $data, MarketData $source): array
    {
        return match ($source->key) {
            'h2h' => [$this->h2hSelections($data, $source), null],
            'double_chance' => [$this->doubleChanceSelections($data, $source), null],
            'totals' => $this->totalsSelections($source),
            'btts' => [$this->bttsSelections($source), null],
            default => throw new \InvalidArgumentException("Marché non pris en charge : {$source->key}"),
        };
    }

    /** Issue du match désignée par un nom de sélection : "home", "away" ou "draw". */
    private function outcome(EventData $data, string $name): string
    {
        $normalized = mb_strtolower(trim($name));

        return match (true) {
            $normalized === mb_strtolower(trim($data->homeTeam)) => 'home',
            $normalized === mb_strtolower(trim($data->awayTeam)) => 'away',
            in_array($normalized, self::DRAW_NAMES, true) => 'draw',
            default => throw new \InvalidArgumentException("Sélection inconnue : {$name}"),
        };
    }

    /** @return array<string, array{name: string, odds: string}> */
    private function h2hSelections(EventData $data, MarketData $source): array
    {
        $odds = [];
        foreach ($source->selections as $selection) {
            $key = $this->outcome($data, $selection->name);
            $odds[$key] = ['name' => $key === 'draw' ? 'Match nul' : $selection->name, 'odds' => $selection->odds];
        }

        if (! isset($odds['home'], $odds['away'])) {
            throw new \InvalidArgumentException('Marché 1X2 incomplet (équipe à domicile ou à l\'extérieur absente).');
        }

        return $odds;
    }

    /**
     * Double chance : chaque sélection couvre deux issues, nommées « Arsenal/Draw », « Arsenal or Chelsea »…
     * Au moins deux des trois paires (1X, 12, X2), sans doublon.
     *
     * @return array<string, array{name: string, odds: string}>
     */
    private function doubleChanceSelections(EventData $data, MarketData $source): array
    {
        $order = ['home' => 0, 'draw' => 1, 'away' => 2];
        $names = [
            'home_draw' => "{$data->homeTeam} ou nul",
            'home_away' => "{$data->homeTeam} ou {$data->awayTeam}",
            'draw_away' => "Nul ou {$data->awayTeam}",
        ];

        $odds = [];
        foreach ($source->selections as $selection) {
            $parts = preg_split('/\s*(?:\/|\s(?:or|ou)\s)\s*/iu', trim($selection->name)) ?: [];
            if (count($parts) !== 2) {
                throw new \InvalidArgumentException("Sélection inconnue : {$selection->name}");
            }

            $pair = array_map(fn ($part) => $this->outcome($data, $part), $parts);
            usort($pair, fn ($a, $b) => $order[$a] <=> $order[$b]);
            $key = self::DOUBLE_CHANCE_KEYS[implode('+', $pair)]
                ?? throw new \InvalidArgumentException("Sélection inconnue : {$selection->name}");

            if (isset($odds[$key])) {
                throw new \InvalidArgumentException("Sélection en double : {$selection->name}");
            }
            $odds[$key] = ['name' => $names[$key], 'odds' => $selection->odds];
        }

        if (count($odds) < 2) {
            throw new \InvalidArgumentException('Marché double chance incomplet.');
        }

        return $odds;
    }

    /**
     * Les deux équipes marquent : exactement un « Oui » et un « Non ».
     *
     * @return array<string, array{name: string, odds: string}>
     */
    private function bttsSelections(MarketData $source): array
    {
        $odds = [];
        foreach ($source->selections as $selection) {
            $key = self::BTTS_NAMES[mb_strtolower(trim($selection->name))]
                ?? throw new \InvalidArgumentException("Sélection inconnue : {$selection->name}");

            if (isset($odds[$key])) {
                throw new \InvalidArgumentException("Sélection en double : {$selection->name}");
            }
            $odds[$key] = ['name' => $key === 'yes' ? 'Oui' : 'Non', 'odds' => $selection->odds];
        }

        if (! isset($odds['yes'], $odds['no'])) {
            throw new \InvalidArgumentException('Marché btts incomplet (oui ou non absent).');
        }

        return $odds;
    }

    /**
     * Plus/moins : exactement un "Over" et un "Under" sur la même ligne.
     *
     * @return array{0: array<string, array{name: string, odds: string}>, 1: string}
     */
    private function totalsSelections(MarketData $source): array
    {
        $byKey = [];
        foreach ($source->selections as $selection) {
            $key = self::TOTALS_NAMES[mb_strtolower(trim($selection->name))]
                ?? throw new \InvalidArgumentException("Sélection inconnue : {$selection->name}");

            if (isset($byKey[$key])) {
                throw new \InvalidArgumentException("Sélection en double : {$selection->name}");
            }
            $byKey[$key] = $selection;
        }

        if (! isset($byKey['over'], $byKey['under'])) {
            throw new \InvalidArgumentException('Marché totals incomplet (plus ou moins absent).');
        }

        $line = $this->line($byKey['over']->point);
        if ($line === null || $line !== $this->line($byKey['under']->point)) {
            throw new \InvalidArgumentException('Ligne du marché totals absente, invalide ou différente entre plus et moins.');
        }

        return [[
            'over' => ['name' => "Plus de {$line}", 'odds' => $byKey['over']->odds],
            'under' => ['name' => "Moins de {$line}", 'odds' => $byKey['under']->odds],
        ], $line];
    }

    /** Ligne en chaîne décimale positive sans zéros inutiles ("2.50" → "2.5", "3.0" → "3") ; null si invalide. */
    private function line(?string $point): ?string
    {
        if ($point === null || ! preg_match('/^\d{1,4}(\.\d{1,2})?$/', $point) || bccomp($point, '0', 2) <= 0) {
            return null;
        }

        return rtrim(rtrim(bcadd($point, '0', 2), '0'), '.');
    }

    /**
     * @param  array<string, array{name: string, odds: string}>  $odds
     * @return bool true si les cotes ou la ligne ont changé (nouvelle version)
     */
    private function syncMarket(Event $event, MarketData $source, array $odds, ?string $line): bool
    {
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
        // Un changement de ligne (ex. 2.5 → 3.5) change le pari même à cotes égales : nouvelle version.
        $changed = $current !== $incoming || ($market->exists && $market->line !== $line);

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
            'line' => $line,
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
                'line' => $line,
                'provider_updated_at' => $source->updatedAt,
            ]);
        }

        // Une sélection disparue du fournisseur ne doit plus être proposée.
        $market->selections()->whereNotIn('key', array_keys($odds))->delete();

        return true;
    }

    private function normalize(string $odds): string
    {
        return bcadd($odds, '0', 3);
    }
}
