<?php

namespace App\Modules\Settlement\Providers;

use App\Modules\Settlement\Contracts\MatchStatisticsProvider;
use App\Modules\Settlement\Data\FixtureData;
use App\Modules\Settlement\Data\StatisticsData;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Providers\SportMonksProvider;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Statistiques des matchs chez SportMonks (STATISTICS_PROVIDER=sportmonks, même clé que SPORTMONKS_API_KEY) : score à
 * la mi-temps, corners et cartons, pour régler les paris sur la 1re mi-temps, les corners et les cartons (V2-D09).
 *
 * - /football/fixtures/date/{jour} : matchs du jour de l'abonnement, score final et à la mi-temps (1 requête par jour).
 * - /football/fixtures/{id}?include=statistics : corners (type 34), cartons jaunes (84) et rouges (83) par équipe.
 * Seuls les matchs des ligues de l'abonnement sont reconnus : un pari de ce type sur un autre match est annulé
 * (remboursé) après STATISTICS_VOID_AFTER_HOURS, comme avec tout fournisseur de statistiques.
 */
class SportMonksStatisticsProvider implements MatchStatisticsProvider
{
    private const CORNERS = 34;

    private const YELLOW_CARDS = 84;

    private const RED_CARDS = 83;

    public function __construct(private readonly SportMonksProvider $sportMonks) {}

    public function name(): string
    {
        return SportMonksProvider::NAME;
    }

    public function fixtures(CarbonImmutable $date): array
    {
        $fixtures = [];
        foreach ($this->sportMonks->fixturesOn($date) as $row) {
            try {
                $teams = [];
                foreach ($row['participants'] ?? [] as $participant) {
                    $teams[$participant['meta']['location'] ?? ''] = $participant;
                }
                $goals = fn (string $description, string $side) => $this->goals($row['scores'] ?? [], $description, $side);
                // Terminé à la fin du temps réglementaire seulement (ni prolongation ni tirs au but).
                $finished = ($row['state']['developer_name'] ?? null) === 'FT';

                $fixtures[] = new FixtureData(
                    externalId: (string) $row['id'],
                    homeTeam: (string) $teams['home']['name'],
                    awayTeam: (string) $teams['away']['name'],
                    startsAt: CarbonImmutable::createFromTimestampUTC((int) $row['starting_at_timestamp']),
                    finished: $finished,
                    homeGoals: $goals('CURRENT', 'home'),
                    awayGoals: $goals('CURRENT', 'away'),
                    firstHalfHome: $goals('1ST_HALF', 'home') ?? ($finished ? 0 : null),
                    firstHalfAway: $goals('1ST_HALF', 'away') ?? ($finished ? 0 : null),
                    homeTeamId: isset($teams['home']['id']) ? (string) $teams['home']['id'] : null,
                    awayTeamId: isset($teams['away']['id']) ? (string) $teams['away']['id'] : null,
                );
            } catch (Throwable) {
                continue; // match illisible : ignoré, les autres restent utilisables
            }
        }

        return $fixtures;
    }

    public function statistics(FixtureData $fixture): StatisticsData
    {
        $statistics = $this->sportMonks->fixtureWithStatistics($fixture->externalId)['statistics'] ?? [];
        if (! is_array($statistics)) {
            throw new SportsDataException(SportsDataException::INVALID_RESPONSE, "Statistiques illisibles pour le match {$fixture->externalId}.");
        }

        $values = [];
        foreach ($statistics as $stat) {
            $side = $stat['location'] ?? match ((string) ($stat['participant_id'] ?? '')) {
                (string) $fixture->homeTeamId => 'home',
                (string) $fixture->awayTeamId => 'away',
                default => null,
            };
            if (in_array($side, ['home', 'away'], true) && is_numeric($stat['data']['value'] ?? null)) {
                $values[$side][(int) $stat['type_id']] = (int) $stat['data']['value'];
            }
        }

        return new StatisticsData(
            externalId: $fixture->externalId,
            firstHalfHome: $fixture->firstHalfHome,
            firstHalfAway: $fixture->firstHalfAway,
            cornersHome: $this->count($values, 'home', [self::CORNERS]),
            cornersAway: $this->count($values, 'away', [self::CORNERS]),
            cardsHome: $this->count($values, 'home', [self::YELLOW_CARDS, self::RED_CARDS]),
            cardsAway: $this->count($values, 'away', [self::YELLOW_CARDS, self::RED_CARDS]),
        );
    }

    /**
     * Somme de statistiques d'une équipe : un type absent vaut 0 (SportMonks n'écrit pas les zéros), mais une équipe
     * sans aucune statistique donne null (pas encore publiées : le pari attend).
     *
     * @param  array<string, array<int, int>>  $values
     * @param  list<int>  $types
     */
    private function count(array $values, string $side, array $types): ?int
    {
        if (empty($values[$side])) {
            return null;
        }

        return array_sum(array_map(fn (int $type) => $values[$side][$type] ?? 0, $types));
    }

    /** @param list<array<string, mixed>> $scores */
    private function goals(array $scores, string $description, string $side): ?int
    {
        foreach ($scores as $score) {
            if (($score['description'] ?? null) === $description && ($score['score']['participant'] ?? null) === $side) {
                return (int) ($score['score']['goals'] ?? 0);
            }
        }

        return null;
    }
}
