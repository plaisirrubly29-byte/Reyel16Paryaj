<?php

namespace Tests\Feature\Modules\Lottery;

use App\Modules\Lottery\Contracts\LotteryProvider;
use App\Modules\Lottery\Exceptions\LotteryException;
use App\Modules\Lottery\Models\LotteryDraw;
use App\Modules\Lottery\Models\LotteryResult;
use App\Modules\Lottery\Providers\FakeLotteryProvider;
use App\Modules\Lottery\Services\LotterySyncReport;
use App\Modules\Lottery\Services\LotterySyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LotterySyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeLotteryProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        // 4 novembre 2026, 23:00 à New York (5 novembre en UTC) : les quatre tirages du jour sont passés.
        $this->travelTo(CarbonImmutable::parse('2026-11-05 04:00:00', 'UTC'));
        $this->fake = new FakeLotteryProvider;
        $this->app->instance(LotteryProvider::class, $this->fake);
    }

    private function sync(): LotterySyncReport
    {
        return app(LotterySyncService::class)->sync(1);
    }

    public function test_draws_and_published_results_are_imported(): void
    {
        $report = $this->sync();

        $this->assertSame(4, $report->draws);
        $this->assertSame(4, LotteryDraw::where('is_active', true)->count());
        // Jours relus : 4 et 5 novembre (dates UTC) ; seuls les 4 tirages du 4 ont eu lieu.
        $this->assertSame(4, $report->created);
        $this->assertSame(4, LotteryResult::count());

        $result = LotteryResult::whereHas('draw', fn ($q) => $q->where('external_key', 'ny_midday'))
            ->where('draw_date', '2026-11-04')->firstOrFail();
        $this->assertMatchesRegularExpression('/^\d{2}$/', $result->lot1);
        $this->assertMatchesRegularExpression('/^\d{3}$/', (string) $result->pick3);
        $this->assertSame('fake', $result->source);
    }

    public function test_a_second_sync_changes_nothing(): void
    {
        $this->sync();
        $report = $this->sync();

        $this->assertSame(0, $report->created);
        $this->assertSame(4, $report->unchanged);
        $this->assertSame(4, LotteryResult::count());
    }

    public function test_draws_missing_from_the_provider_list_are_deactivated(): void
    {
        $this->sync();
        LotteryDraw::create(['external_key' => 'old_draw', 'name' => 'Ancien', 'draw_time' => '12:00', 'timezone' => 'UTC', 'source' => 'fake']);

        $this->sync();

        $this->assertFalse(LotteryDraw::where('external_key', 'old_draw')->value('is_active'));
        $this->assertSame(4, LotteryDraw::where('is_active', true)->count());
    }

    public function test_no_result_before_the_draw_time(): void
    {
        // 4 novembre, 14:00 à New York : seul le tirage de Floride midi (13:30) est passé aujourd'hui.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 19:00:00', 'UTC'));

        $this->sync();

        $this->assertSame(['fl_midday'], LotteryResult::where('draw_date', '2026-11-04')->with('draw')->get()
            ->map(fn ($r) => $r->draw->external_key)->all());
    }

    public function test_a_result_published_before_the_draw_time_is_rejected(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 19:00:00', 'UTC'));
        $this->fake->withoutResults()->setResult('ny_evening', '2026-11-04', '11', '22', '33');

        $report = $this->sync();

        $this->assertSame(1, $report->rejected);
        $this->assertSame(0, LotteryResult::count());
    }

    public function test_invalid_numbers_and_unknown_draws_are_rejected(): void
    {
        $this->fake->withoutResults()
            ->setResult('ny_midday', '2026-11-04', '7', '45', '12')        // lot à un chiffre
            ->setResult('fl_midday', '2026-11-04', '07', '45', '12', '40') // pick 3 à deux chiffres
            ->setResult('fl_evening', '2026-11-04', "07\n", '45', '12')    // retour à la ligne final
            ->setResult('xx_unknown', '2026-11-04', '07', '45', '12')      // tirage inconnu
            ->setResult('ny_evening', '2026-11-04', '07', '45', '12');     // valide

        $report = $this->sync();

        $this->assertSame(4, $report->rejected);
        $this->assertSame(1, $report->created);
        $this->assertSame(['07', '45', '12'], LotteryResult::firstOrFail()->lots());
    }

    public function test_a_different_result_never_overwrites_the_imported_one(): void
    {
        $this->fake->withoutResults()->setResult('ny_midday', '2026-11-04', '07', '45', '12');
        $this->sync();

        $this->fake->setResult('ny_midday', '2026-11-04', '99', '45', '12');
        $report = $this->sync();

        $this->assertSame(1, $report->conflicts);
        $this->assertSame(['07', '45', '12'], LotteryResult::firstOrFail()->lots());
    }

    public function test_provider_failure_imports_nothing_and_the_command_fails(): void
    {
        $this->fake->failWith(LotteryException::UNAVAILABLE);

        $this->artisan('lottery:sync')->assertFailed();
        $this->assertSame(0, LotteryDraw::count());
    }

    public function test_the_command_reports_counts(): void
    {
        $this->artisan('lottery:sync', ['--days' => 1])
            ->expectsOutputToContain('4 tirage(s)')
            ->expectsOutputToContain('4 nouveau(x)')
            ->assertSuccessful();

        $this->artisan('lottery:sync', ['--days' => 'abc'])->assertExitCode(2);
    }

    public function test_the_fake_provider_is_refused_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('lottery:sync')->assertFailed();
        $this->assertSame(0, LotteryDraw::count());
    }

    public function test_next_draw_time_follows_the_draw_timezone_and_days(): void
    {
        $this->sync();
        $draw = LotteryDraw::where('external_key', 'ny_evening')->firstOrFail();

        // Mercredi 4 novembre, 23:00 à New York : prochain tirage le 5 à 22:30 (heure d'hiver, UTC−5).
        $this->assertSame('2026-11-06T03:30:00+00:00', $draw->nextDrawAt()->toIso8601String());

        // Seulement le samedi (6) : prochain tirage le samedi 7 novembre.
        $draw->days = [6];
        $this->assertSame('2026-11-08T03:30:00+00:00', $draw->nextDrawAt()->toIso8601String());
    }
}
