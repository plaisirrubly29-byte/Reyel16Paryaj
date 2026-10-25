<?php

namespace Tests\Feature\Modules\Settlement;

use App\Modules\Settlement\Enums\ResultStatus;
use App\Modules\Settlement\Models\Result;
use App\Modules\Settlement\Services\ResultImportService;
use App\Modules\Settlement\Services\ResultRecorder;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

class ResultImportTest extends TestCase
{
    use RefreshDatabase;

    private const SYNCED_AT = '2026-10-20 10:00:00';

    private FakeSportsDataProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sports_data.max_events_per_competition' => 10, 'sports_data.horizon_days' => 7, 'sports_data.results_delay_minutes' => 105]);
        $this->fake = new FakeSportsDataProvider;
        $this->app->instance(SportsDataProvider::class, $this->fake);

        // Matchs du faux catalogue synchronisés le 20 à 10 h (fake-1 le 21 à 15 h, fake-2 le 22 à 17 h 30,
        // fake-3 en NBA le 21 à 23 h 30), puis trois jours plus tard : les trois matchs sont terminés.
        $this->travelTo(self::SYNCED_AT);
        app(CatalogueSyncService::class)->sync('soccer_epl');
        app(CatalogueSyncService::class)->sync('basketball_nba');
        $this->travel(3)->days();
    }

    private function import(string $key = 'soccer_epl')
    {
        return app(ResultImportService::class)->import($key);
    }

    private function event(string $externalId): Event
    {
        return Event::where('external_id', $externalId)->firstOrFail();
    }

    public function test_test_results_are_importable_from_the_fake_provider(): void
    {
        $this->fake->setResult('fake-1', '2', '1');

        $report = $this->import();

        $this->assertTrue($report->succeeded());
        $this->assertSame(1, $report->recorded);
        $this->assertSame(1, $report->unknown); // fake-0 : absent du catalogue

        $event = $this->event('fake-1');
        $this->assertSame(EventStatus::Finished, $event->status);
        $this->assertSame(['2', '1'], [$event->home_score, $event->away_score]);
        $this->assertNotNull($event->result_received_at);

        $result = Result::currentFor($event);
        $this->assertSame(1, $result->version);
        $this->assertSame(ResultStatus::Final, $result->status);
        $this->assertSame('fake', $result->source);
        $this->assertSame('fake-1', $result->external_id);
        $this->assertNotNull($result->provider_updated_at);
        $this->assertNull($result->supersedes_id);

        // Plus aucun pari sur un match terminé ; les autres matchs ne bougent pas.
        $this->assertSame([MarketStatus::Closed], $event->markets()->get()->pluck('status')->unique()->values()->all());
        $this->assertSame(EventStatus::Scheduled, $this->event('fake-2')->status);
    }

    public function test_reimporting_the_same_result_changes_nothing(): void
    {
        $this->fake->setResult('fake-1', '2', '1');
        $this->import();

        $report = $this->import();

        $this->assertSame(0, $report->recorded);
        $this->assertSame(1, $report->unchanged);
        $this->assertSame(1, Result::count());
    }

    public function test_a_different_score_is_a_new_version_with_its_history(): void
    {
        Log::spy();
        $this->fake->setResult('fake-1', '2', '1');
        $this->import();

        $this->fake->setResult('fake-1', '2', '2');
        $report = $this->import();

        $this->assertSame(1, $report->corrected);
        $versions = Result::where('event_id', $this->event('fake-1')->id)->orderBy('version')->get();
        $this->assertSame([1, 2], $versions->pluck('version')->all());
        $this->assertSame($versions[0]->id, $versions[1]->supersedes_id);
        $this->assertNotNull($versions[1]->correction_reason);
        $this->assertSame(['2', '1'], [$versions[0]->home_score, $versions[0]->away_score]); // l'ancienne version est intacte
        $this->assertSame(['2', '2'], [$this->event('fake-1')->home_score, $this->event('fake-1')->away_score]);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'settlement.result_corrected')->once();
    }

    public function test_a_match_still_in_progress_goes_live_without_a_result(): void
    {
        $this->fake->setResult('fake-1', '1', '0', completed: false);

        $report = $this->import();

        $this->assertSame(1, $report->inProgress);
        $this->assertSame(EventStatus::Live, $this->event('fake-1')->status);
        $this->assertSame(0, Result::count());
    }

    public function test_an_invalid_result_is_rejected_without_blocking_the_others(): void
    {
        $this->fake->setResult('fake-1', '-1', '2')->setResult('fake-2', '0', '0');

        $report = $this->import();

        $this->assertSame(1, $report->rejected);
        $this->assertSame(1, $report->recorded);
        $this->assertSame(EventStatus::Scheduled, $this->event('fake-1')->status);
        $this->assertSame(EventStatus::Finished, $this->event('fake-2')->status);
    }

    public function test_a_result_for_a_match_that_has_not_started_is_rejected(): void
    {
        $this->travelTo(self::SYNCED_AT);
        $this->fake->setResult('fake-1', '2', '1');

        $report = $this->import();

        $this->assertSame(1, $report->rejected);
        $this->assertSame(0, Result::count());
    }

    public function test_a_provider_failure_records_nothing(): void
    {
        $this->fake->setResult('fake-1', '2', '1')->failWith(SportsDataException::UNAVAILABLE);

        $report = $this->import();

        $this->assertFalse($report->succeeded());
        $this->assertSame(0, Result::count());
        $this->assertSame(EventStatus::Scheduled, $this->event('fake-1')->status);
    }

    public function test_only_competitions_waiting_for_a_result_are_queried(): void
    {
        $import = app(ResultImportService::class);

        // fake-1 (≈ 2 jours) et fake-2 (≈ 1 jour) en EPL, fake-3 (≈ 2 jours) en NBA.
        $this->assertSame(['basketball_nba' => 2, 'soccer_epl' => 2], $import->pendingCompetitions());

        $this->fake->setResult('fake-3', '101', '99');
        $import->import('basketball_nba');
        $this->assertSame(['soccer_epl' => 2], $import->pendingCompetitions());

        // Match commencé il y a moins de results_delay_minutes : pas encore demandé.
        $this->travelTo($this->event('fake-1')->starts_at->addMinutes(60));
        $this->assertSame([], $import->pendingCompetitions());
    }

    public function test_cancelled_match_has_no_score(): void
    {
        $recorder = app(ResultRecorder::class);
        $event = $this->event('fake-1');

        $result = $recorder->record($event, ResultStatus::Cancelled, null, null, source: Result::SOURCE_MANUAL);

        $this->assertSame(ResultStatus::Cancelled, $result->status);
        $this->assertSame(EventStatus::Cancelled, $event->fresh()->status);

        $this->expectException(InvalidArgumentException::class);
        $recorder->record($event, ResultStatus::Cancelled, '1', '0', source: Result::SOURCE_MANUAL);
    }

    public function test_scores_are_normalized(): void
    {
        $result = app(ResultRecorder::class)->record($this->event('fake-1'), ResultStatus::Final, ' 02', '0', source: 'fake');

        $this->assertSame(['2', '0'], [$result->home_score, $result->away_score]);
    }

    public function test_command_imports_only_pending_competitions(): void
    {
        $this->fake->setResult('fake-1', '3', '0');

        $this->artisan('settlement:import-results')->assertSuccessful();

        $this->assertSame(EventStatus::Finished, $this->event('fake-1')->status);
    }

    public function test_command_does_nothing_when_no_match_is_waiting(): void
    {
        $this->travelTo(self::SYNCED_AT);

        $this->artisan('settlement:import-results')
            ->expectsOutput('Aucun match en attente de résultat.')
            ->assertSuccessful();
    }

    public function test_import_is_scheduled_with_the_configured_frequency(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'settlement:import-results'));

        $this->assertNotNull($event);
        $this->assertSame(config('sports_data.results_cron'), $event->expression);
    }
}
