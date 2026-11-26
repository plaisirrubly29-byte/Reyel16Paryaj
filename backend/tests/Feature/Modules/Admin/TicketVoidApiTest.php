<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Settlement\Enums\TicketVoidStatus;
use App\Modules\Settlement\Models\Settlement;
use App\Modules\Settlement\Models\TicketVoid;
use App\Modules\Settlement\Services\ResultImportService;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Models\Event;
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
 * Annulation d'un ticket à double validation (audit du 2026-11-22, A2) : demande, aperçu, validation par un autre
 * administrateur (sélections à la cote 1, mise rendue, gain compensé), refus, délai, alerte.
 *
 * Arsenal–Chelsea (fake-1) le 21/10 à 15:00 ; Arsenal 2.10, mise 100 HTG.
 */
class TicketVoidApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeSportsDataProvider $fake;

    private User $player;

    private User $operations;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        config(['sports_data.max_odds_age_minutes' => 300, 'sports_data.results_delay_minutes' => 105, 'wallet.simulated' => true]);
        $this->fake = new FakeSportsDataProvider;
        $this->fake->setStartsAt('fake-1', CarbonImmutable::parse('2026-10-21 15:00:00'));
        $this->app->instance(SportsDataProvider::class, $this->fake);

        $this->travelTo('2026-10-20 10:00:00');
        app(CatalogueSyncService::class)->sync('soccer_epl');
        $this->operations = User::factory()->withRole(RoleSlug::Operations)->create();
        $this->superAdmin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();

        $this->player = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($this->player), Money::ofMinor(1000_00, 'HTG'), 'test:'.Str::uuid());
    }

    private function bet(): BetTicket
    {
        $selection = Event::where('external_id', 'fake-1')->firstOrFail()
            ->markets()->where('key', 'h2h')->firstOrFail()->selections()->where('key', 'home')->firstOrFail();

        $id = $this->actingAs($this->player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => 100_00, 'currency' => 'HTG'],
            'selections' => [['selection_id' => $selection->id, 'odds' => (string) $selection->odds]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');

        return BetTicket::findOrFail($id);
    }

    private function balance(): int
    {
        $ledger = app(LedgerService::class);

        return $ledger->balance($ledger->walletFor($this->player))->minor;
    }

    private function finish(string $home, string $away): void
    {
        $this->travelTo('2026-10-21 17:30:00');
        $this->fake->setResult('fake-1', $home, $away);
        $this->assertTrue(app(ResultImportService::class)->import('soccer_epl')->succeeded());
    }

    private function adminPost(User $as, string $uri, string $reason = 'Cote manifestement erronée'): TestResponse
    {
        return $this->actingAsVerifiedAdmin($as)->postJson("/api/v1/admin/{$uri}", ['reason' => $reason], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_a_pending_ticket_is_voided_only_after_a_second_administrator_approves(): void
    {
        $ticket = $this->bet();
        $this->assertSame(900_00, $this->balance());

        $id = $this->adminPost($this->operations, "tickets/{$ticket->id}/void")->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.preview.to', ['status' => 'void', 'payout_minor' => 100_00])
            ->json('data.id');
        $this->assertSame(BetTicketStatus::Pending, $ticket->fresh()->status, 'Rien ne change avant la validation.');

        $this->adminPost($this->operations, "tickets/{$ticket->id}/void")->assertStatus(409)->assertJsonPath('error.code', 'void_already_pending');
        $this->adminPost($this->operations, "ticket-voids/{$id}/approve", 'Je valide ma demande')
            ->assertForbidden()->assertJsonPath('error.code', 'self_approval_forbidden');

        $this->adminPost($this->superAdmin, "ticket-voids/{$id}/approve", 'Vérifié avec le bookmaker')->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.impact.ledger_delta_minor', 100_00);

        $ticket->refresh();
        $this->assertSame(BetTicketStatus::Void, $ticket->status);
        $this->assertSame(BetSelectionStatus::Void, $ticket->selections()->first()->status);
        $this->assertSame(1000_00, $this->balance(), 'La mise est rendue.');
        $this->assertSame(1, AuditLog::where('action', AuditAction::TicketVoidRequested)->count());
        $this->assertSame(1, AuditLog::where('action', AuditAction::TicketVoidApproved)->count());

        // Le résultat arrive ensuite : le ticket annulé n'est plus touché.
        $this->finish('2', '1');
        $this->assertSame(BetTicketStatus::Void, $ticket->fresh()->status);
        $this->assertSame(1000_00, $this->balance());
    }

    public function test_voiding_a_won_ticket_reverses_the_payout_and_refunds_the_stake(): void
    {
        $ticket = $this->bet();
        $this->finish('2', '1');
        $this->assertSame(BetTicketStatus::Won, $ticket->fresh()->status);
        $this->assertSame(900_00 + 210_00, $this->balance());

        $id = $this->adminPost($this->operations, "tickets/{$ticket->id}/void")->assertCreated()
            ->assertJsonPath('data.preview.from', ['status' => 'won', 'payout_minor' => 210_00])
            ->assertJsonPath('data.preview.ledger_delta_minor', -110_00)
            ->json('data.id');
        $this->adminPost($this->superAdmin, "ticket-voids/{$id}/approve", 'Fraude confirmée')->assertOk();

        $this->assertSame(BetTicketStatus::Void, $ticket->fresh()->status);
        $this->assertSame(1000_00, $this->balance(), 'Gain repris, mise rendue.');
        $this->assertSame([1, 2], Settlement::where('bet_ticket_id', $ticket->id)->orderBy('revision')->pluck('revision')->all());
    }

    public function test_a_request_can_be_withdrawn_or_rejected_and_the_window_is_enforced(): void
    {
        $ticket = $this->bet();
        $id = $this->adminPost($this->operations, "tickets/{$ticket->id}/void")->json('data.id');

        // Support : ni demandeur ni validateur.
        $this->adminPost(User::factory()->withRole(RoleSlug::Support)->create(), "ticket-voids/{$id}/reject")->assertForbidden();
        $this->adminPost($this->operations, "ticket-voids/{$id}/reject", 'Je retire ma demande')->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->adminPost($this->superAdmin, "ticket-voids/{$id}/approve")->assertStatus(409)->assertJsonPath('error.code', 'void_not_pending');
        $this->assertSame(BetTicketStatus::Pending, $ticket->fresh()->status);

        // Réglé depuis plus de 72 h (D-05 §5) : passer par une correction financière.
        $this->finish('0', '1');
        $this->travelTo('2026-10-25 12:00:00');
        $this->adminPost($this->operations, "tickets/{$ticket->id}/void")->assertStatus(409)->assertJsonPath('error.code', 'correction_window_closed');
    }

    public function test_approval_is_refused_when_the_ticket_changed_since_the_request(): void
    {
        $ticket = $this->bet();
        $id = $this->adminPost($this->operations, "tickets/{$ticket->id}/void")->json('data.id');
        $this->finish('0', '1'); // perdu entre la demande et la validation

        $this->adminPost($this->superAdmin, "ticket-voids/{$id}/approve")->assertStatus(409)->assertJsonPath('error.code', 'ticket_changed');
        $this->assertSame(BetTicketStatus::Lost, $ticket->fresh()->status);
    }

    public function test_pending_requests_are_signalled_and_listed(): void
    {
        $ticket = $this->bet();
        $id = $this->adminPost($this->operations, "tickets/{$ticket->id}/void")->json('data.id');

        $keys = collect($this->actingAsVerifiedAdmin($this->superAdmin)->getJson('/api/v1/admin/alerts')->assertOk()->json('data'))->pluck('key');
        $this->assertContains('ticket_voids', $keys);

        $this->actingAsVerifiedAdmin($this->superAdmin)->getJson('/api/v1/admin/tickets?void_status=pending')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $ticket->id);
        $this->actingAsVerifiedAdmin($this->superAdmin)->getJson("/api/v1/admin/tickets/{$ticket->id}")->assertOk()
            ->assertJsonPath('data.voids.0.id', $id)
            ->assertJsonPath('data.voids.0.requested_by.name', $this->operations->name);
        $this->actingAsVerifiedAdmin($this->superAdmin)->getJson('/api/v1/admin/ticket-voids?status=pending')->assertOk()
            ->assertJsonCount(1, 'data');
        $this->assertSame(TicketVoidStatus::Pending, TicketVoid::findOrFail($id)->status);
    }
}
