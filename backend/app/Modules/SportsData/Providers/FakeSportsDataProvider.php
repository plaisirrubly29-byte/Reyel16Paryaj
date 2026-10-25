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
 * $fake->setOdds('fake-1', 'h2h', ['Arsenal' => '1.85']) simule un changement de cote ;
 * $fake->setOdds('fake-1', 'totals', ['Over' => '1.70', 'Under' => '2.10'], '3.5') un changement de ligne ;
 * $fake->setResult('fake-1', '2', '1') publie un score final (completed: false = match en cours).
 * Les matchs de football ont un 1X2 (h2h) et un plus/moins 2.5 buts (totals) ; la NBA n'a que le h2h.
 */
class FakeSportsDataProvider implements SportsDataProvider
{
    /** Ligne par défaut du marché totals. */
    private const TOTALS_LINE = '2.5';

    private ?string $failure = null;

    /** @var array<string, array<string, array{odds: array<string, string>, point: ?string}>> */
    private array $oddsOverrides = [];

    /** @var array<string, array{home: ?string, away: ?string, completed: bool}> */
    private array $results = [];

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

    /**
     * @param  array<string, string>  $odds  cotes par nom de sélection
     * @param  ?string  $point  ligne (totals) ; null = ligne par défaut
     */
    public function setOdds(string $eventId, string $market, array $odds, ?string $point = null): static
    {
        $this->oddsOverrides[$eventId][$market] = ['odds' => $odds, 'point' => $point];

        return $this;
    }

    /** Score publié pour un match du faux catalogue (fake-1, fake-2, fake-3), renvoyé par results(). */
    public function setResult(string $eventId, ?string $homeScore, ?string $awayScore, bool $completed = true): static
    {
        $this->results[$eventId] = ['home' => $homeScore, 'away' => $awayScore, 'completed' => $completed];

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

        $updatedAt = $this->updatedAtOverride ?? CarbonImmutable::now()->startOfMinute()->subMinutes(2);

        return array_map(function (array $f) use ($sportKey, $updatedAt) {
            [$id, $home, $away, $startsAt, $markets] = $f;

            return new EventData(
                externalId: $id,
                sportKey: $sportKey,
                competition: $sportKey === 'soccer_epl' ? 'EPL' : 'NBA',
                homeTeam: $home,
                awayTeam: $away,
                startsAt: $startsAt,
                markets: array_map(fn (string $key, array $odds) => $this->market($id, $key, $odds, $updatedAt), array_keys($markets), $markets),
            );
        }, $this->fixtures($sportKey));
    }

    /**
     * Matchs du faux catalogue : [id, domicile, extérieur, début, marchés (clé => cotes par nom de sélection)].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: CarbonImmutable, 4: array<string, array<string, string>>}>
     */
    private function fixtures(string $sportKey): array
    {
        $now = CarbonImmutable::now()->startOfMinute();

        $fixtures = [
            'soccer_epl' => [
                ['fake-1', 'Arsenal', 'Chelsea', $now->addDay()->setTime(15, 0), [
                    'h2h' => ['Arsenal' => '2.10', 'Chelsea' => '3.40', 'Draw' => '3.25'],
                    'double_chance' => ['Arsenal/Draw' => '1.27', 'Arsenal/Chelsea' => '1.30', 'Draw/Chelsea' => '1.66'],
                    'totals' => ['Over' => '1.80', 'Under' => '2.00'],
                    'btts' => ['Yes' => '1.70', 'No' => '2.05'],
                ]],
                ['fake-2', 'Liverpool', 'Everton', $now->addDays(2)->setTime(17, 30), [
                    'h2h' => ['Liverpool' => '1.55', 'Everton' => '5.75', 'Draw' => '4.20'],
                    'double_chance' => ['Liverpool or Draw' => '1.13', 'Liverpool or Everton' => '1.22', 'Draw or Everton' => '2.43'],
                    'totals' => ['Over' => '1.65', 'Under' => '2.20'],
                    'btts' => ['Yes' => '1.75', 'No' => '1.95'],
                ]],
            ],
            'basketball_nba' => [
                ['fake-3', 'Boston Celtics', 'Miami Heat', $now->addDay()->setTime(23, 30), [
                    'h2h' => ['Boston Celtics' => '1.45', 'Miami Heat' => '2.80'],
                ]],
            ],
        ];

        return $fixtures[$sportKey] ?? [];
    }

    /** @param array<string, string> $odds */
    private function market(string $eventId, string $key, array $odds, CarbonImmutable $updatedAt): MarketData
    {
        $override = $this->oddsOverrides[$eventId][$key] ?? null;
        $odds = $override['odds'] ?? $odds;
        $point = $key === 'totals' ? ($override['point'] ?? self::TOTALS_LINE) : null;

        return new MarketData(
            key: $key,
            bookmaker: 'fakebook',
            updatedAt: $updatedAt,
            selections: array_map(fn ($name, $price) => new SelectionData($name, $price, $point), array_keys($odds), $odds),
        );
    }

    public function upcomingStarts(string $sportKey): array
    {
        return array_map(fn (EventData $e) => $e->startsAt, $this->events($sportKey));
    }

    public function results(string $sportKey, int $daysFrom = 1): array
    {
        $this->guard();

        $now = CarbonImmutable::now()->startOfMinute();
        $results = [];

        // Match absent du catalogue : l'import doit l'ignorer.
        if ($sportKey === 'soccer_epl') {
            $yesterday = $now->subDay()->setTime(15, 0);
            $results[] = new ResultData('fake-0', 'soccer_epl', 'Manchester City', 'Tottenham', $yesterday, true, '2', '1', $yesterday->addHours(2));
        }

        foreach ($this->fixtures($sportKey) as [$id, $home, $away, $startsAt]) {
            if (isset($this->results[$id])) {
                $r = $this->results[$id];
                $results[] = new ResultData($id, $sportKey, $home, $away, $startsAt, $r['completed'], $r['home'], $r['away'], $now);
            }
        }

        return $results;
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
