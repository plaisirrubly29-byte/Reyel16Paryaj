<?php

namespace App\Modules\Settlement\Providers;

use App\Modules\Settlement\Contracts\MatchStatisticsProvider;
use App\Modules\Settlement\Data\FixtureData;
use App\Modules\Settlement\Data\StatisticsData;
use App\Modules\SportsData\Exceptions\SportsDataException;
use Carbon\CarbonImmutable;

/**
 * Fournisseur de statistiques en mémoire (tests, développement) : les matchs et statistiques sont posés par le test.
 * Compte les appels, comme une API facturée à la requête.
 */
class FakeMatchStatisticsProvider implements MatchStatisticsProvider
{
    /** @var list<FixtureData> */
    public array $fixtures = [];

    /** @var array<string, StatisticsData> par identifiant du match */
    public array $statistics = [];

    public int $calls = 0;

    public ?SportsDataException $failure = null;

    public function name(): string
    {
        return 'fake';
    }

    public function fixtures(CarbonImmutable $date): array
    {
        $this->call();

        return array_values(array_filter($this->fixtures, fn (FixtureData $f) => $f->startsAt->isSameDay($date)));
    }

    public function statistics(FixtureData $fixture): StatisticsData
    {
        $this->call();

        return $this->statistics[$fixture->externalId]
            ?? new StatisticsData($fixture->externalId, $fixture->firstHalfHome, $fixture->firstHalfAway, null, null, null, null);
    }

    private function call(): void
    {
        $this->calls++;
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
