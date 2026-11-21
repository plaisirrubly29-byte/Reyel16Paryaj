<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Data\EventData;
use App\Modules\SportsData\Data\MarketData;
use App\Modules\SportsData\Data\SelectionData;
use App\Modules\SportsData\Enums\OddsOverrideStatus;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Models\OddsOverride;
use App\Modules\SportsData\Models\OddsSnapshot;
use App\Modules\SportsData\Models\Selection;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * V2-D03 (décision D-18) : marge de l'opérateur à la synchronisation, et cotes fixées à la main (Opérations, Super
 * Admin), double validation au-delà de 20 % d'écart, fin, expiration, mention « ajustée » au catalogue.
 *
 * Arsenal–Chelsea (fake-1), 1X2 : Arsenal 2.10, nul 3.25, Chelsea 3.40.
 */
class OddsOverrideApiTest extends TestCase
{
    use RefreshDatabase;

    private Market $market;

    private Selection $home;

    private User $operations;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->app->instance(SportsDataProvider::class, new FakeSportsDataProvider);
        app(CatalogueSyncService::class)->sync('soccer_epl');

        $this->market = Market::where('key', 'h2h')->whereHas('event', fn ($q) => $q->where('external_id', 'fake-1'))->firstOrFail();
        $this->home = $this->market->selections()->where('key', 'home')->firstOrFail();
        $this->operations = User::factory()->withRole(RoleSlug::Operations)->create();
        $this->superAdmin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();
    }

    private function adminPost(User $admin, string $uri, array $data = []): TestResponse
    {
        return $this->actingAsVerifiedAdmin($admin)->postJson("/api/v1/admin/{$uri}", $data, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function override(User $admin, string $odds, array $extra = []): TestResponse
    {
        return $this->adminPost($admin, "markets/{$this->market->id}/odds-overrides",
            ['selection_id' => (string) $this->home->id, 'odds' => $odds, 'reason' => 'Information sur un blessé', ...$extra]);
    }

    /** Fournisseur à deux bookmakers : le second cote chaque issue 0,10 de plus. */
    private function twoBookmakers(): void
    {
        $this->app->instance(SportsDataProvider::class, new class extends FakeSportsDataProvider
        {
            public function events(string $sportKey): array
            {
                return array_map(fn (EventData $e) => new EventData($e->externalId, $e->sportKey, $e->competition, $e->homeTeam,
                    $e->awayTeam, $e->startsAt, [...$e->markets, ...array_map(fn (MarketData $m) => new MarketData($m->key, 'otherbook',
                        $m->updatedAt, array_map(fn (SelectionData $s) => new SelectionData($s->name, bcadd($s->odds, '0.10', 3), $s->point,
                            $s->description), $m->selections)), $e->markets)]), parent::events($sportKey));
            }
        });
    }

    public function test_in_fictitious_funds_the_provider_odds_are_kept_as_computed_odds(): void
    {
        $this->assertSame('2.100', (string) $this->home->odds);
        $this->assertSame('2.100', (string) $this->home->computed_odds);
        $this->assertSame('2.100', (string) $this->home->provider_odds);
        $this->assertSame('2.100', (string) $this->home->best_odds);
        $this->assertFalse($this->home->adjusted);
    }

    /** La marge de l'opérateur (OddsMargin, #283) donne la cote calculée ; la cote du bookmaker et la meilleure sont gardées. */
    public function test_with_real_money_the_margin_gives_the_computed_odds_and_the_references_are_kept(): void
    {
        config(['wallet.simulated' => false]);
        $this->twoBookmakers();

        app(CatalogueSyncService::class)->sync('soccer_epl');

        $market = $this->market->fresh();
        $home = $this->home->fresh();
        // Deux bookmakers (2,10 / 3,25 / 3,40 et 2,20 / 3,35 / 3,50), marge de chacun retirée puis moyenne, puis 6 % :
        // 2,14 / 3,29 / 3,44.
        $this->assertSame(['away' => '3.440', 'draw' => '3.290', 'home' => '2.140'],
            $market->selections()->orderBy('key')->pluck('odds', 'key')->map(fn ($o) => (string) $o)->all());
        $this->assertSame('2.140', (string) $home->computed_odds);
        $this->assertSame('2.100', (string) $home->provider_odds);
        $this->assertSame('2.200', (string) $home->best_odds);
        $this->assertSame(2, $market->odds_version);
        $snapshot = OddsSnapshot::where('selection_id', $home->id)->where('odds_version', 2)->firstOrFail();
        $this->assertSame(['2.140', '2.100', false], [(string) $snapshot->computed_odds, (string) $snapshot->provider_odds, $snapshot->adjusted]);
    }

    public function test_with_the_margin_an_active_override_is_kept_while_the_computed_odds_follow_the_market(): void
    {
        $this->override($this->operations, '2.00')->assertCreated();
        config(['wallet.simulated' => false]);
        $this->twoBookmakers();

        app(CatalogueSyncService::class)->sync('soccer_epl');

        $home = $this->home->fresh();
        $this->assertSame('2.000', (string) $home->odds, 'La cote fixée à la main reste proposée.');
        $this->assertTrue($home->adjusted);
        $this->assertSame('2.140', (string) $home->computed_odds, 'La cote calculée suit le marché, pour le retour.');

        $this->adminPost($this->operations, 'odds-overrides/'.OddsOverride::firstOrFail()->id.'/end', ['reason' => 'Information démentie'])->assertOk();
        $this->assertSame('2.140', (string) $this->home->fresh()->odds);
    }

    public function test_a_small_change_takes_effect_at_once_and_is_shown_as_adjusted(): void
    {
        $response = $this->override($this->operations, '2.00')->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.requires_approval', false)
            ->assertJsonPath('data.computed_odds', '2.10')
            ->assertJsonPath('data.deviation_percent', '-4.76');

        $home = $this->home->fresh();
        $this->assertSame('2.000', (string) $home->odds);
        $this->assertTrue($home->adjusted);
        $this->assertSame(2, $this->market->fresh()->odds_version);

        $this->getJson("/api/v1/events/{$this->market->event_id}/markets")->assertOk()
            ->assertJsonFragment(['key' => 'home', 'odds' => '2.00', 'adjusted' => true]);

        $this->actingAsVerifiedAdmin($this->operations)->getJson("/api/v1/admin/markets/{$this->market->id}")->assertOk()
            ->assertJsonPath('data.selections.0.override.id', $response->json('data.id'))
            ->assertJsonPath('data.selections.0.computed_odds', '2.10');

        $this->assertSame(1, AuditLog::where('action', AuditAction::OddsOverrideRequested)->count());
        $this->assertSame(1, AuditLog::where('action', AuditAction::OddsOverrideApproved)->count());
    }

    public function test_the_sync_does_not_overwrite_an_active_override(): void
    {
        $this->override($this->operations, '2.00')->assertCreated();

        app(CatalogueSyncService::class)->sync('soccer_epl');

        $this->assertSame('2.000', (string) $this->home->fresh()->odds);
        $this->assertSame(2, $this->market->fresh()->odds_version, 'Rien n\'a changé : pas de nouvelle version.');
    }

    public function test_a_large_change_waits_for_a_second_administrator(): void
    {
        $id = $this->override($this->operations, '1.50')->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requires_approval', true)
            ->json('data.id');
        $this->assertSame('2.100', (string) $this->home->fresh()->odds, 'En attente : la cote ne change pas.');

        $this->adminPost($this->operations, "odds-overrides/{$id}/approve", ['reason' => 'Je valide moi-même'])
            ->assertForbidden()->assertJsonPath('error.code', 'self_approval_forbidden');

        $this->adminPost($this->superAdmin, "odds-overrides/{$id}/approve", ['reason' => 'Vérifié avec le service des risques'])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.decided_by.id', (string) $this->superAdmin->id);
        $this->assertSame('1.500', (string) $this->home->fresh()->odds);
    }

    public function test_a_pending_change_can_be_rejected(): void
    {
        $id = $this->override($this->operations, '3.00')->json('data.id');

        $this->adminPost($this->superAdmin, "odds-overrides/{$id}/reject", ['reason' => 'Écart injustifié'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('2.100', (string) $this->home->fresh()->odds);
        $this->adminPost($this->superAdmin, "odds-overrides/{$id}/approve", ['reason' => 'Trop tard'])
            ->assertStatus(409)->assertJsonPath('error.code', 'override_not_pending');
    }

    public function test_ending_an_override_brings_back_the_computed_odds(): void
    {
        $id = $this->override($this->operations, '2.00')->json('data.id');

        $this->adminPost($this->operations, "odds-overrides/{$id}/end", ['reason' => 'Information démentie'])
            ->assertOk()->assertJsonPath('data.status', 'ended')->assertJsonPath('data.ended_by.id', (string) $this->operations->id);

        $home = $this->home->fresh();
        $this->assertSame('2.100', (string) $home->odds);
        $this->assertFalse($home->adjusted);
        $this->assertSame(3, $this->market->fresh()->odds_version);
        $this->assertSame(1, AuditLog::where('action', AuditAction::OddsOverrideEnded)->count());
    }

    public function test_overrides_expire_at_their_deadline_or_at_kick_off(): void
    {
        $active = $this->override($this->operations, '2.00', ['expires_at' => now()->addHour()->toIso8601String()])->json('data.id');
        $away = $this->market->selections()->where('key', 'away')->firstOrFail();
        $pending = $this->adminPost($this->operations, "markets/{$this->market->id}/odds-overrides",
            ['selection_id' => (string) $away->id, 'odds' => '5.00', 'reason' => 'Équipe remaniée'])->json('data.id');

        $this->travel(61)->minutes();
        $this->artisan('odds:expire-overrides')->assertSuccessful();

        $this->assertSame(OddsOverrideStatus::Expired, OddsOverride::find($active)->status);
        $this->assertSame('2.100', (string) $this->home->fresh()->odds);
        $this->assertFalse($this->home->fresh()->adjusted);

        // Au début du match, la demande en attente expire aussi, sans jamais entrer en vigueur.
        $this->travelTo($this->market->event->starts_at->addMinute());
        $this->artisan('odds:expire-overrides')->assertSuccessful();
        $this->assertSame(OddsOverrideStatus::Expired, OddsOverride::find($pending)->status);
        $this->assertSame(2, AuditLog::where('action', AuditAction::OddsOverrideExpired)->count());
    }

    public function test_one_override_per_selection_and_only_before_kick_off(): void
    {
        $id = $this->override($this->operations, '2.00')->json('data.id');
        $this->override($this->superAdmin, '1.95')->assertStatus(409)
            ->assertJsonPath('error.code', 'override_already_exists')->assertJsonPath('error.details.override_id', $id);

        $this->override($this->operations, '0.90')->assertStatus(422);
        $this->override($this->operations, '2.00', ['expires_at' => $this->market->event->starts_at->addHour()->toIso8601String()])
            ->assertStatus(422);

        $this->travelTo($this->market->event->starts_at->addMinute());
        $other = $this->market->selections()->where('key', 'draw')->firstOrFail();
        $this->adminPost($this->operations, "markets/{$this->market->id}/odds-overrides",
            ['selection_id' => (string) $other->id, 'odds' => '3.00', 'reason' => 'Trop tard pour modifier'])
            ->assertStatus(409)->assertJsonPath('error.code', 'event_started');
    }

    public function test_the_queue_lists_pending_requests_first(): void
    {
        $this->override($this->operations, '2.00');
        $away = $this->market->selections()->where('key', 'away')->firstOrFail();
        $pending = $this->adminPost($this->operations, "markets/{$this->market->id}/odds-overrides",
            ['selection_id' => (string) $away->id, 'odds' => '5.00', 'reason' => 'Équipe remaniée'])->json('data.id');

        $this->actingAsVerifiedAdmin($this->superAdmin)->getJson('/api/v1/admin/odds-overrides')->assertOk()
            ->assertJsonPath('data.0.id', $pending)
            ->assertJsonPath('data.0.event.home_team', 'Arsenal')
            ->assertJsonCount(2, 'data');
        $this->actingAsVerifiedAdmin($this->superAdmin)->getJson('/api/v1/admin/odds-overrides?status=active')->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
