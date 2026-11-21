<?php

namespace App\Modules\Settlement\Services;

use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\Betting\Models\BetSelection;
use App\Modules\Settlement\Contracts\MatchStatisticsProvider;
use App\Modules\Settlement\Data\FixtureData;
use App\Modules\Settlement\Enums\ResultStatus;
use App\Modules\Settlement\Jobs\SettleEventJob;
use App\Modules\Settlement\Models\MatchStatistics;
use App\Modules\Settlement\Models\Result;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Services\MarketKeys;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Import des statistiques des matchs terminés (V2-D09), pour régler 1re mi-temps, corners et cartons.
 *
 * - Seulement les matchs qui ont un pari en attente sur ces marchés, un résultat final et pas encore de
 *   statistiques, dans le délai d'annulation : aucune requête pour rien (API facturée à la requête).
 * - Une requête par jour de match (liste des matchs), puis une par match reconnu (corners, cartons).
 * - Match reconnu chez le fournisseur par l'heure du coup d'envoi (± kickoff_tolerance_minutes), le nom des deux
 *   équipes (approché : « Paris Saint Germain » = « Paris Saint-Germain FC ») ET le même score final : un doute
 *   ne règle rien (le pari attend, puis est annulé passé le délai).
 * - Statistiques enregistrées une seule fois, puis règlement relancé (SettleEventJob).
 */
class StatisticsImportService
{
    /** Mots sans valeur pour reconnaître une équipe (« FC Barcelona » = « Barcelona »). */
    private const NOISE = ['fc', 'cf', 'afc', 'sc', 'ac', 'cd', 'fk', 'sk', 'ss', 'us', 'as', 'sv', 'club', 'de', 'the', 'calcio', 'clube', 'futebol', 'football'];

    /** Ressemblance minimale du nom de chaque équipe (0 à 1). */
    private const MIN_SIMILARITY = 0.6;

    public function __construct(private readonly MatchStatisticsProvider $provider) {}

    public static function enabled(): bool
    {
        return (string) config('sports_data.statistics.provider') !== '';
    }

    /** @return array{candidates: int, recorded: int, not_found: int, requests: int, error: ?string} */
    public function import(): array
    {
        $report = ['candidates' => 0, 'recorded' => 0, 'not_found' => 0, 'requests' => 0, 'error' => null];

        $events = $this->candidates();
        $report['candidates'] = $events->count();

        /** @var array<string, list<FixtureData>> $days */
        $days = [];
        foreach ($events as $event) {
            $result = Result::currentFor($event);
            if ($result === null || $result->status !== ResultStatus::Final) {
                continue;
            }

            try {
                $day = $event->starts_at->copy()->utc()->toDateString();
                if (! isset($days[$day])) {
                    $report['requests']++;
                    $days[$day] = $this->provider->fixtures(CarbonImmutable::parse($day, 'UTC'));
                }

                $fixture = $this->find($event, $result, $days[$day]);
                if ($fixture === null) {
                    $report['not_found']++;
                    Log::info('settlement.statistics_not_found', ['event_id' => $event->id]);

                    continue;
                }

                $report['requests']++;
                $stats = $this->provider->statistics($fixture);
            } catch (SportsDataException $e) {
                $report['error'] = "{$e->reason}: {$e->getMessage()}";
                Log::warning('settlement.statistics_import_failed', ['event_id' => $event->id, 'error' => $report['error']]);

                break; // clé invalide, limite atteinte, panne : inutile d'insister jusqu'au prochain passage
            }

            try {
                MatchStatistics::create([
                    'event_id' => $event->id,
                    'source' => $this->provider->name(),
                    'external_id' => $fixture->externalId,
                    'first_half_home' => $stats->firstHalfHome,
                    'first_half_away' => $stats->firstHalfAway,
                    'corners_home' => $stats->cornersHome,
                    'corners_away' => $stats->cornersAway,
                    'cards_home' => $stats->cardsHome,
                    'cards_away' => $stats->cardsAway,
                ]);
            } catch (UniqueConstraintViolationException) {
                continue; // enregistrées entre-temps par un autre passage
            }

            $report['recorded']++;
            SettleEventJob::dispatch($event->id);
        }

        Log::info('settlement.statistics_imported', $report);

        return $report;
    }

    /**
     * Matchs terminés avec un pari en attente sur un marché à statistiques, sans statistiques, commencés depuis
     * plus de 2 h et dans le délai d'annulation.
     *
     * @return Collection<int, Event>
     */
    public function candidates()
    {
        $hours = (int) config('sports_data.statistics.void_after_hours', 48);

        return Event::where('status', EventStatus::Finished)
            ->whereIn('id', BetSelection::where('status', BetSelectionStatus::Pending)
                ->whereIn('market_key', MarketKeys::STATISTICS)
                ->select('event_id'))
            ->whereNotIn('id', MatchStatistics::select('event_id'))
            ->where('starts_at', '>=', now()->subHours($hours))
            ->where('starts_at', '<=', now()->subMinutes(120))
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Match du fournisseur correspondant : terminé, coup d'envoi proche, même score final, noms d'équipes ressemblants.
     *
     * @param  list<FixtureData>  $fixtures
     */
    public function find(Event $event, Result $result, array $fixtures): ?FixtureData
    {
        $tolerance = (int) config('sports_data.statistics.kickoff_tolerance_minutes', 20);
        $home = (int) $result->home_score;
        $away = (int) $result->away_score;

        $best = null;
        $bestScore = 0.0;
        foreach ($fixtures as $fixture) {
            if (! $fixture->finished || $fixture->homeGoals !== $home || $fixture->awayGoals !== $away
                || abs($fixture->startsAt->diffInMinutes($event->starts_at, false)) > $tolerance) {
                continue;
            }

            $h = $this->similarity((string) $event->homeTeam?->name, $fixture->homeTeam);
            $a = $this->similarity((string) $event->awayTeam?->name, $fixture->awayTeam);
            if ($h >= self::MIN_SIMILARITY && $a >= self::MIN_SIMILARITY && $h + $a > $bestScore) {
                $best = $fixture;
                $bestScore = $h + $a;
            }
        }

        return $best;
    }

    /** Ressemblance de deux noms d'équipe, de 0 à 1 (accents, ponctuation et « FC », « Club »… ignorés). */
    public function similarity(string $a, string $b): float
    {
        $a = $this->normalize($a);
        $b = $this->normalize($b);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        if (str_contains($a, $b) || str_contains($b, $a)) {
            return 0.9;
        }

        similar_text($a, $b, $percent);

        return $percent / 100;
    }

    private function normalize(string $name): string
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_diff($words, self::NOISE));
    }
}
