<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** P6-02 : API admin des événements et marchés — consulter, suspendre / rouvrir un marché, origine et fraîcheur. */
class AdminEventsMarketsApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeSportsDataProvider $fake;

    private Event $event;

    private Market $market;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        config(['sports_data.max_odds_age_minutes' => 300, 'wallet.simulated' => true]);
        $this->fake = new FakeSportsDataProvider;
        $this->app->instance(SportsDataProvider::class, $this->fake);
        $this->sync(); // fake-1 Arsenal–Chelsea, fake-2 Liverpool–Everton

        $this->event = Event::where('external_id', 'fake-1')->firstOrFail();
        $this->market = $this->event->markets()->where('key', 'h2h')->firstOrFail();
    }

    private function sync(): void
    {
        app(CatalogueSyncService::class)->sync('soccer_epl');
    }

    private function admin(RoleSlug $role): User
    {
        return User::factory()->withRole($role)->create();
    }

    private function setStatus(string $status, string $reason = 'Blessure du gardien titulaire', ?User $as = null): TestResponse
    {
        return $this->actingAsVerifiedAdmin($as ?? $this->admin(RoleSlug::Operations))
            ->patchJson("/api/v1/admin/markets/{$this->market->id}", ['status' => $status, 'reason' => $reason], [
                'Idempotency-Key' => (string) Str::uuid(),
            ]);
    }

    public function test_every_admin_role_can_view_events_and_markets(): void
    {
        foreach (RoleSlug::cases() as $role) {
            $admin = $this->admin($role);

            $this->actingAsVerifiedAdmin($admin)->getJson('/api/v1/admin/events')->assertOk();
            $this->actingAsVerifiedAdmin($admin)->getJson("/api/v1/admin/events/{$this->event->id}")->assertOk();
            $this->actingAsVerifiedAdmin($admin)->getJson("/api/v1/admin/markets/{$this->market->id}")->assertOk();
        }
    }

    public function test_only_super_admin_and_operations_can_suspend_a_market(): void
    {
        foreach ([RoleSlug::Finance, RoleSlug::Support, RoleSlug::Compliance] as $role) {
            $this->setStatus('suspended', as: $this->admin($role))->assertForbidden();
        }
        $this->assertSame(MarketStatus::Open, $this->market->fresh()->status);

        $this->setStatus('suspended', as: $this->admin(RoleSlug::SuperAdmin))->assertOk();
        $this->setStatus('open', as: $this->admin(RoleSlug::Operations))->assertOk();
    }

    public function test_players_and_guests_have_no_access(): void
    {
        $this->getJson('/api/v1/admin/events')->assertUnauthorized();

        $player = User::factory()->create();
        $this->actingAs($player)->getJson('/api/v1/admin/events')->assertForbidden();
        $this->actingAs($player)->patchJson("/api/v1/admin/markets/{$this->market->id}", [
            'status' => 'suspended', 'reason' => 'Tentative de joueur',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_an_admin_without_verified_mfa_has_no_access(): void
    {
        $this->actingAs($this->admin(RoleSlug::Operations))
            ->getJson('/api/v1/admin/events')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'mfa_setup_required');
    }

    public function test_the_list_shows_origin_sync_and_market_counts(): void
    {
        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Operations))
            ->getJson('/api/v1/admin/events?search=arsenal')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $this->event->id)
            ->assertJsonPath('data.0.home_team', 'Arsenal')
            ->assertJsonPath('data.0.origin', ['provider' => 'fake', 'external_id' => 'fake-1'])
            ->assertJsonPath('data.0.markets_count.open', $this->event->markets()->count())
            ->assertJsonPath('data.0.markets_count.suspended', 0)
            ->assertJsonMissingPath('data.0.markets')
            ->assertJsonStructure(['data' => [['last_synced_at', 'starts_at', 'status']], 'links', 'meta']);
    }

    public function test_the_list_can_be_filtered_by_market_status(): void
    {
        $this->setStatus('suspended')->assertOk();

        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Operations))
            ->getJson('/api/v1/admin/events?market_status=suspended')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.markets_count.suspended', 1);

        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Operations))
            ->getJson('/api/v1/admin/events?status=finished')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Operations))
            ->getJson('/api/v1/admin/events?status=unknown&date=28-10-2026')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_the_detail_shows_every_market_with_origin_and_freshness(): void
    {
        $response = $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Support))
            ->getJson("/api/v1/admin/events/{$this->event->id}")
            ->assertOk();

        $h2h = collect($response->json('data.markets'))->firstWhere('key', 'h2h');
        $this->assertSame('open', $h2h['status']);
        $this->assertNull($h2h['suspension']);
        $this->assertSame(['provider' => 'fake', 'external_id' => 'fake-1', 'bookmaker' => $this->market->source_bookmaker], $h2h['origin']);
        $this->assertFalse($h2h['freshness']['stale']);
        $this->assertSame(300, $h2h['freshness']['max_age_minutes']);
        $this->assertTrue($h2h['bettable']);
        $this->assertCount(3, $h2h['selections']);
    }

    public function test_stale_odds_are_flagged(): void
    {
        $this->travel(301)->minutes();

        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Operations))
            ->getJson("/api/v1/admin/markets/{$this->market->id}")
            ->assertOk()
            ->assertJsonPath('data.freshness.stale', true)
            ->assertJsonPath('data.bettable', false);
    }

    public function test_suspending_a_market_is_audited_and_removes_it_from_the_catalogue_and_from_betting(): void
    {
        $operations = $this->admin(RoleSlug::Operations);

        $this->setStatus('suspended', 'Blessure du gardien titulaire', $operations)
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspension', ['automatic' => false, 'reason' => 'Blessure du gardien titulaire'])
            ->assertJsonPath('data.bettable', false);

        $log = AuditLog::where('action', AuditAction::MarketSuspended)->sole();
        $this->assertSame($operations->id, $log->actor_id);
        $this->assertSame((string) $this->market->id, $log->subject_id);
        $this->assertSame('Blessure du gardien titulaire', $log->reason);
        $this->assertSame(['status' => 'open', 'suspension_reason' => null], $log->before);
        $this->assertSame(['status' => 'suspended', 'suspension_reason' => 'Blessure du gardien titulaire'], $log->after);

        // Catalogue public : le marché n'est plus proposé.
        $keys = collect($this->getJson("/api/v1/events/{$this->event->id}/markets")->json('data.markets'))->pluck('key');
        $this->assertNotContains('h2h', $keys);

        // Pari : refusé côté serveur.
        $player = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($player), Money::ofMinor(1000_00, 'HTG'), 'test:'.Str::uuid());
        $selection = $this->market->selections()->firstOrFail();
        $this->actingAs($player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => 100_00, 'currency' => 'HTG'],
            'selections' => [['selection_id' => $selection->id, 'odds' => (string) $selection->odds]],
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'selection_unavailable');
    }

    public function test_the_provider_sync_never_reopens_an_admin_suspension(): void
    {
        $this->setStatus('suspended')->assertOk();

        $this->fake->setOdds('fake-1', 'h2h', ['Arsenal' => '1.95']);
        $this->sync();

        $this->assertSame(MarketStatus::Suspended, $this->market->fresh()->status);
    }

    public function test_an_automatic_suspension_becomes_an_admin_one(): void
    {
        $this->market->forceFill(['status' => MarketStatus::Suspended, 'suspension_reason' => CatalogueSyncService::AUTO_SUSPENSION_PREFIX.'stale'])->save();

        $this->setStatus('suspended', 'Match sous enquête')
            ->assertOk()
            ->assertJsonPath('data.suspension', ['automatic' => false, 'reason' => 'Match sous enquête']);

        $this->sync();
        $this->assertSame(MarketStatus::Suspended, $this->market->fresh()->status);
    }

    public function test_reopening_a_market_is_audited_and_puts_it_back_in_the_catalogue(): void
    {
        $this->setStatus('suspended')->assertOk();

        $this->setStatus('open', 'Gardien remplaçant confirmé')
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.suspension', null)
            ->assertJsonPath('data.bettable', true);

        $log = AuditLog::where('action', AuditAction::MarketReopened)->sole();
        $this->assertSame(['status' => 'open', 'suspension_reason' => null], $log->after);

        $keys = collect($this->getJson("/api/v1/events/{$this->event->id}/markets")->json('data.markets'))->pluck('key');
        $this->assertContains('h2h', $keys);
    }

    public function test_repeating_the_current_status_changes_and_audits_nothing(): void
    {
        $this->setStatus('open')->assertOk()->assertJsonPath('data.status', 'open');
        $this->setStatus('suspended')->assertOk();
        $this->setStatus('suspended', 'Autre motif identique')->assertOk();

        $this->assertSame(1, AuditLog::count());
        $this->assertSame('Blessure du gardien titulaire', $this->market->fresh()->suspension_reason);
    }

    public function test_a_closed_or_settled_market_cannot_be_changed(): void
    {
        foreach ([MarketStatus::Closed, MarketStatus::Settled] as $final) {
            $this->market->forceFill(['status' => $final])->save();

            $this->setStatus('suspended')->assertStatus(409)->assertJsonPath('error.code', 'invalid_status_transition');
            $this->setStatus('open')->assertStatus(409);
        }

        $this->assertSame(0, AuditLog::count());
    }

    public function test_the_reason_is_required_and_cannot_mimic_an_automatic_suspension(): void
    {
        foreach (['', 'abcd', str_repeat('a', 256), 'auto: cotes périmées', '  AUTO:stale'] as $reason) {
            $this->setStatus('suspended', $reason)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }

        $this->setStatus('closed')->assertStatus(422);
        $this->assertSame(MarketStatus::Open, $this->market->fresh()->status);
    }

    public function test_changing_a_market_requires_an_idempotency_key(): void
    {
        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Operations))
            ->patchJson("/api/v1/admin/markets/{$this->market->id}", ['status' => 'suspended', 'reason' => 'Blessure du gardien'])
            ->assertStatus(428);
    }

    public function test_unknown_ids_return_404(): void
    {
        $admin = $this->admin(RoleSlug::Operations);

        $this->actingAsVerifiedAdmin($admin)->getJson('/api/v1/admin/events/999999')->assertNotFound();
        $this->actingAsVerifiedAdmin($admin)->getJson('/api/v1/admin/markets/999999')->assertNotFound();
    }
}
