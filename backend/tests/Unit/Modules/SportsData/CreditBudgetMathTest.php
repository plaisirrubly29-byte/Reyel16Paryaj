<?php

namespace Tests\Unit\Modules\SportsData;

use App\Modules\SportsData\Services\CreditBudget;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class CreditBudgetMathTest extends TestCase
{
    private function at(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'UTC');
    }

    public function test_period_starts_on_the_reset_day(): void
    {
        $this->assertSame('2026-10-01 00:00:00', CreditBudget::periodStartFor($this->at('2026-10-16 10:00'), 1)->toDateTimeString());
        $this->assertSame('2026-11-01 00:00:00', CreditBudget::nextResetFor($this->at('2026-10-16 10:00'), 1)->toDateTimeString());

        // Avant le jour de remise à zéro : la période a commencé le mois précédent.
        $this->assertSame('2026-09-20 00:00:00', CreditBudget::periodStartFor($this->at('2026-10-16 10:00'), 20)->toDateTimeString());
        $this->assertSame('2026-10-20 00:00:00', CreditBudget::nextResetFor($this->at('2026-10-16 10:00'), 20)->toDateTimeString());

        // Le jour même, la nouvelle période a commencé.
        $this->assertSame('2026-10-20 00:00:00', CreditBudget::periodStartFor($this->at('2026-10-20 00:00'), 20)->toDateTimeString());
    }

    public function test_reset_day_is_clamped_to_short_months(): void
    {
        // Remise à zéro le 31 : le 28 février (2027 n'est pas bissextile), le 30 avril.
        $this->assertSame('2027-01-31 00:00:00', CreditBudget::periodStartFor($this->at('2027-02-15'), 31)->toDateTimeString());
        $this->assertSame('2027-02-28 00:00:00', CreditBudget::nextResetFor($this->at('2027-02-15'), 31)->toDateTimeString());
        $this->assertSame('2027-02-28 00:00:00', CreditBudget::periodStartFor($this->at('2027-03-10'), 31)->toDateTimeString());
        $this->assertSame('2027-03-31 00:00:00', CreditBudget::nextResetFor($this->at('2027-03-10'), 31)->toDateTimeString());
        $this->assertSame('2027-04-30 00:00:00', CreditBudget::nextResetFor($this->at('2027-04-01'), 31)->toDateTimeString());
        $this->assertSame('2028-02-29 00:00:00', CreditBudget::nextResetFor($this->at('2028-02-01'), 30)->toDateTimeString());
    }

    public function test_runs_are_counted_until_the_reset(): void
    {
        $reset = $this->at('2026-11-01 00:00');

        // 16/10 à 10 h : 12 h, 16 h, 20 h puis 15 jours × 6 synchros.
        $this->assertSame(93, CreditBudget::runsBetween('0 */4 * * *', $this->at('2026-10-16 10:00'), $reset));
        // La synchro de la minute en cours compte.
        $this->assertSame(93, CreditBudget::runsBetween('0 */4 * * *', $this->at('2026-10-16 12:00:40'), $reset));
        $this->assertSame(92, CreditBudget::runsBetween('0 */4 * * *', $this->at('2026-10-16 12:01'), $reset));
        // La remise à zéro elle-même est exclue.
        $this->assertSame(1, CreditBudget::runsBetween('0 */4 * * *', $this->at('2026-10-31 20:00'), $reset));
        $this->assertSame(0, CreditBudget::runsBetween('0 */4 * * *', $this->at('2026-10-31 20:01'), $reset));
    }

    public function test_run_count_is_capped(): void
    {
        $this->assertSame(500, CreditBudget::runsBetween('* * * * *', $this->at('2026-10-16 10:00'), $this->at('2026-11-01'), 500));
    }
}
