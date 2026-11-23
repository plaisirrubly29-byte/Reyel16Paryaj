<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Settlement\Enums\ResultCorrectionStatus;
use App\Modules\Settlement\Models\ResultCorrection;
use App\Modules\Settlement\Services\SettlementService;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Selection;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Gestion d'un match par l'administration (audit du 2026-11-22, A1, A3 et A4) : heure, report, annulation (D-05),
 * masquage, suspension de tous les marchés, alerte des sélections bloquées.
 *
 * Arsenal–Chelsea (fake-1) le 21/10 à 15:00 ; Arsenal 2.10.
 */
class EventManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeSportsDataProvider $fake;

    private Event $event;

    private User $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        config(['sports_data.max_odds_age_minutes' => 300, 'wallet.simulated' => true]);
        $this->fake = new FakeSportsDataProvider;
        $this->fake->setStartsAt('fake-1', CarbonImmutable::parse('2026-10-21 15:00:00'));
        $this->app->instance(SportsDataProvider::class, $this->fake);

        $this->travelTo('2026-10-20 10:00:00');
        app(CatalogueSyncService::class)->sync('soccer_epl');
        $this->event = Event::where('external_id', 'fake-1')->firstOrFail();
        $this->operations = User::factory()->withRole(RoleSlug::Operations)->create();
    }

    private function bet(int $stake = 100_00): BetTicket
    {
        $player = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($player), Money::ofMinor(1000_00, 'HTG'), 'test:'.Str::uuid());
        /** @var Selection $selection */
        $selection = $this->event->markets()->where('key', 'h2h')->firstOrFail()->selections()->where('key', 'home')->firstOrFail();

        $id = $this->actingAs($player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => $stake, 'currency' => 'HTG'],
            'selections' => [['selection_id' => $selection->id, 'odds' => (string) $selection->odds]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');

        return BetTicket::findOrFail($id);
    }

    /** @param  array<string, mixed>  $body */
    private function update(array $body, ?User $as = null): TestResponse
    {
        return $this->actingAsVerifiedAdmin($as ?? $this->operations)
            ->patchJson("/api/v1/admin/events/{$this->event->id}", $body + ['reason' => 'Communiqué officiel de la ligue'], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function eventAction(string $action, ?User $as = null): TestResponse
    {
        return $this->actingAsVerifiedAdmin($as ?? $this->operations)
            ->postJson("/api/v1/admin/events/{$this->event->id}/{$action}", ['reason' => 'Incident de sécurité au stade'], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_finance_and_support_cannot_update_a_match(): void
    {
        foreach ([RoleSlug::Finance, RoleSlug::Support, RoleSlug::Compliance] as $role) {
            $this->update(['status' => 'postponed'], User::factory()->withRole($role)->create())->assertForbidden();
        }
        $this->assertSame(EventStatus::Scheduled, $this->event->fresh()->status);
    }

    public function test_reason_and_at_least_one_change_are_required(): void
    {
        $this->actingAsVerifiedAdmin($this->operations)
            ->patchJson("/api/v1/admin/events/{$this->event->id}", ['status' => 'postponed'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('reason', 'error.details');
        $this->update([])->assertUnprocessable()->assertJsonValidationErrors('status', 'error.details');
        $this->update(['status' => 'finished'])->assertUnprocessable()->assertJsonValidationErrors('status', 'error.details');
    }

    public function test_new_time_is_kept_by_the_sync_and_audited(): void
    {
        $this->update(['starts_at' => '2026-10-22T18:00:00Z'])->assertOk()
            ->assertJsonPath('data.starts_at', '2026-10-22T18:00:00+00:00')
            ->assertJsonPath('data.status', 'scheduled');

        $this->assertNotNull($this->event->fresh()->admin_locked_at);
        app(CatalogueSyncService::class)->sync('soccer_epl');
        $this->assertSame('2026-10-22 18:00:00', $this->event->fresh()->starts_at->toDateTimeString(), 'La synchro ne remplace pas l\'heure fixée par l\'administration.');

        $log = AuditLog::where('action', AuditAction::EventUpdated)->firstOrFail();
        $this->assertSame($this->operations->id, $log->actor_id);
        $this->assertSame('2026-10-21T15:00:00+00:00', $log->before['starts_at']);
        $this->assertSame('Communiqué officiel de la ligue', $log->reason);

        $this->update(['starts_at' => '2026-10-22T18:00:00Z'])->assertStatus(409)->assertJsonPath('error.code', 'event_unchanged');
    }

    public function test_a_postponed_match_leaves_the_site_and_its_bets_are_voided_after_48_hours(): void
    {
        $ticket = $this->bet();

        $this->update(['status' => 'postponed'])->assertOk()->assertJsonPath('data.status', 'postponed');
        $this->getJson('/api/v1/events/'.$this->event->id.'/markets')->assertNotFound();

        // La synchro ne remet pas le match « programmé ».
        app(CatalogueSyncService::class)->sync('soccer_epl');
        $this->assertSame(EventStatus::Postponed, $this->event->fresh()->status);

        // D-05 §3 : 48 h après l'heure figée sur le ticket, la sélection est annulée et la mise rendue.
        $this->travelTo('2026-10-23 15:01:00');
        app(SettlementService::class)->sweep();
        $this->assertSame(BetTicketStatus::Void, $ticket->fresh()->status);
        $this->assertSame(100_00, $ticket->fresh()->payout_minor);
    }

    public function test_cancelling_a_match_not_yet_played_voids_its_selections_at_once(): void
    {
        $ticket = $this->bet();

        $this->update(['status' => 'cancelled'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('meta.result_correction_id', null);

        $this->assertSame(BetTicketStatus::Void, $ticket->fresh()->status);
        $this->assertSame(BetSelectionStatus::Void, $ticket->selections()->first()->status);
        $this->assertSame(0, $this->event->markets()->whereIn('status', [MarketStatus::Open, MarketStatus::Suspended])->count());
        $this->assertSame(1, AuditLog::where('action', AuditAction::EventUpdated)->count());

        $this->update(['status' => 'cancelled'])->assertStatus(409)->assertJsonPath('error.code', 'event_not_editable');
    }

    public function test_cancelling_a_started_match_needs_a_second_administrator(): void
    {
        $ticket = $this->bet();
        $this->travelTo('2026-10-21 15:30:00');

        $id = $this->update(['status' => 'cancelled'])->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->json('meta.result_correction_id');

        $correction = ResultCorrection::findOrFail($id);
        $this->assertSame(ResultCorrectionStatus::Pending, $correction->status);
        $this->assertSame(BetTicketStatus::Pending, $ticket->fresh()->status, 'Rien ne change avant la validation.');
        $this->assertSame(0, $this->event->markets()->where('status', MarketStatus::Open)->count(), 'Les marchés sont suspendus en attendant.');

        $this->actingAsVerifiedAdmin(User::factory()->withRole(RoleSlug::SuperAdmin)->create())
            ->postJson("/api/v1/admin/result-corrections/{$id}/approve", ['reason' => 'Match arrêté, vérifié'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk();

        $this->assertSame(EventStatus::Cancelled, $this->event->fresh()->status);
        $this->assertSame(BetTicketStatus::Void, $ticket->fresh()->status);
    }

    public function test_cancellation_cannot_be_combined_with_another_change(): void
    {
        $this->update(['status' => 'cancelled', 'hidden' => true])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_change');
    }

    public function test_a_hidden_match_leaves_the_site_and_cannot_be_bet_on(): void
    {
        $this->update(['hidden' => true])->assertOk()->assertJsonPath('data.hidden.reason', 'Communiqué officiel de la ligue');

        $this->getJson('/api/v1/events/'.$this->event->id.'/markets')->assertNotFound();
        $player = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($player), Money::ofMinor(1000_00, 'HTG'), 'test:hidden');
        $selection = $this->event->markets()->where('key', 'h2h')->firstOrFail()->selections()->firstOrFail();
        $this->actingAs($player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => 100_00, 'currency' => 'HTG'],
            'selections' => [['selection_id' => $selection->id, 'odds' => (string) $selection->odds]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422)->assertJsonPath('error.code', 'selection_unavailable');

        $this->update(['hidden' => false])->assertOk()->assertJsonPath('data.hidden', null);
        $this->getJson('/api/v1/events/'.$this->event->id.'/markets')->assertOk();
    }

    public function test_suspend_and_reopen_act_on_every_market_of_the_match(): void
    {
        $count = $this->event->markets()->where('status', MarketStatus::Open)->count();
        $this->assertGreaterThan(1, $count);

        $this->eventAction('suspend')->assertOk()->assertJsonPath('meta.markets_changed', $count);
        $this->assertSame(0, $this->event->markets()->where('status', MarketStatus::Open)->count());
        $this->assertSame($count, AuditLog::where('action', AuditAction::MarketSuspended)->count());
        $this->assertSame(1, AuditLog::where('action', AuditAction::EventSuspended)->count());

        $this->eventAction('suspend')->assertStatus(409)->assertJsonPath('error.code', 'event_unchanged');

        $this->eventAction('reopen')->assertOk()->assertJsonPath('meta.markets_changed', $count);
        $this->assertSame($count, $this->event->markets()->where('status', MarketStatus::Open)->count());
        $this->assertSame(1, AuditLog::where('action', AuditAction::EventReopened)->count());
    }

    public function test_stuck_selections_raise_an_alert_and_are_listed(): void
    {
        $this->bet();
        $this->travelTo('2026-10-21 22:00:00'); // 7 h après le début, aucun résultat

        $alert = collect($this->actingAsVerifiedAdmin($this->operations)->getJson('/api/v1/admin/alerts')->assertOk()->json('data'))
            ->firstWhere('key', 'selections_stuck');
        $this->assertNotNull($alert);
        $this->assertSame('1 sélection(s) bloquée(s) sur 1 match(s) sans résultat', $alert['title']);

        $this->actingAsVerifiedAdmin($this->operations)->getJson('/api/v1/admin/events?stuck=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $this->event->id);
        $this->actingAsVerifiedAdmin($this->operations)->getJson("/api/v1/admin/tickets?event_id={$this->event->id}")->assertOk()
            ->assertJsonCount(1, 'data');
        $this->actingAsVerifiedAdmin($this->operations)->getJson('/api/v1/admin/tickets?event_id=999999')->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
