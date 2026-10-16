<?php

namespace App\Modules\SportsData\Providers;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Data\EventData;
use App\Modules\SportsData\Data\MarketData;
use App\Modules\SportsData\Data\QuotaData;
use App\Modules\SportsData\Data\ResultData;
use App\Modules\SportsData\Data\SelectionData;
use App\Modules\SportsData\Data\SportData;
use App\Modules\SportsData\Exceptions\SportsDataException;
use Carbon\CarbonImmutable;

/**
 * Faux fournisseur déterministe pour le développement et les tests (aucun appel réseau, aucun quota).
 * Les dates sont relatives à "maintenant" pour toujours avoir des matchs à venir.
 *
 * En test : $fake->failWith(SportsDataException::UNAVAILABLE) simule une panne ;
 * $fake->setOdds('fake-1', 'h2h', ['Arsenal' => '1.85']) simule un changement de cote.
 */
class FakeSportsDataProvider implements SportsDataProvider
{
    private ?string $failure = null;

    /** @var array<string, array<string, array<string, string>>> */
    private array $oddsOverrides = [];

    private ?CarbonImmutable $updatedAtOverride = null;

    public function name(): string
    {
        return 'fake';
    }

    public function failWith(?string $reason): static
    {
        $this->failure = $reason;

        return $this;
    }

    /** @param array<string, string> $odds */
    public function setOdds(string $eventId, string $market, array $odds): static
    {
        $this->oddsOverrides[$eventId][$market] = $odds;

        return $this;
    }

    /** Fige l'horodatage des cotes (ex. pour simuler des cotes périmées). */
    public function setOddsUpdatedAt(?CarbonImmutable $at): static
    {
        $this->updatedAtOverride = $at;

        return $this;
    }

    public function sports(): array
    {
        $this->guard();

        return [
            new SportData('soccer_epl', 'Soccer', 'EPL', true, false),
            new SportData('basketball_nba', 'Basketball', 'NBA', true, false),
            new SportData('soccer_fifa_world_cup_winner', 'Soccer', 'FIFA World Cup Winner', true, true),
        ];
    }

    public function events(string $sportKey): array
    {
        $this->guard();

        $now = CarbonImmutable::now()->startOfMinute();
        $updatedAt = $this->updatedAtOverride ?? $now->subMinutes(2);

        $fixtures = [
            'soccer_epl' => [
                ['fake-1', 'Arsenal', 'Chelsea', $now->addDay()->setTime(15, 0), ['Arsenal' => '2.10', 'Chelsea' => '3.40', 'Draw' => '3.25']],
                ['fake-2', 'Liverpool', 'Everton', $now->addDays(2)->setTime(17, 30), ['Liverpool' => '1.55', 'Everton' => '5.75', 'Draw' => '4.20']],
            ],
            'basketball_nba' => [
                ['fake-3', 'Boston Celtics', 'Miami Heat', $now->addDay()->setTime(23, 30), ['Boston Celtics' => '1.45', 'Miami Heat' => '2.80']],
            ],
        ];

        return array_map(function (array $f) use ($sportKey, $updatedAt) {
            [$id, $home, $away, $startsAt, $odds] = $f;
            $odds = $this->oddsOverrides[$id]['h2h'] ?? $odds;

            return new EventData(
                externalId: $id,
                sportKey: $sportKey,
                competition: $sportKey === 'soccer_epl' ? 'EPL' : 'NBA',
                homeTeam: $home,
                awayTeam: $away,
                startsAt: $startsAt,
                markets: [new MarketData(
                    key: 'h2h',
                    bookmaker: 'fakebook',
                    updatedAt: $updatedAt,
                    selections: array_map(fn ($name, $price) => new SelectionData($name, $price), array_keys($odds), $odds),
                )],
            );
        }, $fixtures[$sportKey] ?? []);
    }

    public function upcomingStarts(string $sportKey): array
    {
        return array_map(fn (EventData $e) => $e->startsAt, $this->events($sportKey));
    }

    public function results(string $sportKey, int $daysFrom = 1): array
    {
        $this->guard();

        if ($sportKey !== 'soccer_epl') {
            return [];
        }

        $yesterday = CarbonImmutable::now()->subDay()->setTime(15, 0);

        return [
            new ResultData('fake-0', 'soccer_epl', 'Manchester City', 'Tottenham', $yesterday, true, '2', '1', $yesterday->addHours(2)),
        ];
    }

    public function quota(): ?QuotaData
    {
        return null;
    }

    private function guard(): void
    {
        if ($this->failure !== null) {
            throw new SportsDataException($this->failure, "Panne simulée du faux fournisseur ({$this->failure}).");
        }
    }
}
