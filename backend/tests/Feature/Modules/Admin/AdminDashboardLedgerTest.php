<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Payments\Enums\PaymentDirection;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V2-F05 : chiffres du tableau de bord tirés du ledger — dépôts et retraits (prestataire et saisies manuelles, crédits
 * fictifs exclus, retraits échoués rendus), premiers dépôts, produit brut par produit (paris, borlette) avec les
 * corrections, joueurs actifs, retraits en attente.
 */
class AdminDashboardLedgerTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        config(['app.display_timezone' => 'America/Port-au-Prince']);
        // 15 h UTC = 11 h à Port-au-Prince (UTC-5 en novembre).
        $this->travelTo(CarbonImmutable::parse('2026-11-20 15:00:00', 'UTC'));
        $this->ledger = app(LedgerService::class);
        $this->admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();
    }

    private function htg(int $minor): Money
    {
        return Money::ofMinor($minor, 'HTG');
    }

    private function dashboard(string $period = 'today'): array
    {
        return $this->actingAsVerifiedAdmin($this->admin)->getJson("/api/v1/admin/dashboard?period={$period}")->assertOk()->json('data');
    }

    public function test_payments_and_gaming_figures_come_from_the_ledger(): void
    {
        config([
            'payments.providers.fake.enabled' => true,
            'payments.withdrawals.enabled' => true,
        ]);
        $alice = User::factory()->create();
        $bruno = User::factory()->create();
        $carla = User::factory()->create();
        $a = $this->ledger->walletFor($alice);
        $b = $this->ledger->walletFor($bruno);

        // Avant la période : premier dépôt d'Alice (ne compte pas comme premier dépôt aujourd'hui).
        $this->travel(-10)->days();
        $this->ledger->deposit($a, $this->htg(100_00), 'old:deposit', 'Dépôt au guichet', $this->admin);
        $this->travelBack();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 15:00:00', 'UTC'));

        // Crédits fictifs : exclus des dépôts.
        $this->ledger->creditSimulated($a, $this->htg(10_000_00), 'sim:a');
        $this->ledger->creditSimulated($b, $this->htg(10_000_00), 'sim:b');

        // Dépôts : prestataire (Alice) et saisie manuelle (Bruno, son premier dépôt).
        $this->ledger->providerDeposit($a, $this->htg(500_00), 'payment:pay_a', '1', 'MonCash');
        $this->ledger->deposit($b, $this->htg(300_00), 'manual:b', 'Dépôt au guichet', $this->admin);

        // Retraits : celui d'Alice échoue et lui est rendu ; celui de Bruno est saisi à la main.
        $failed = $this->ledger->providerWithdrawal($a, $this->htg(200_00), 'payout:pay_w', '2', 'MonCash');
        $this->ledger->reverse($failed, 'payout:pay_w:reversal', 'withdrawal_failed');
        $this->ledger->withdraw($b, $this->htg(100_00), 'manual:w', 'Retrait au guichet', $this->admin);

        // Paris sportifs : Alice mise 100 et gagne 250, puis une correction reprend le gain ; Bruno mise 50, remboursé.
        $this->ledger->reserveStake($a, $this->htg(100_00), 'bet:1:stake', '1');
        $payout = $this->ledger->payout($a, $this->htg(250_00), 'bet:1:payout', '1');
        $this->ledger->reverse($payout, 'bet:1:reversal', 'Correction de résultat', $this->admin);
        $this->ledger->reserveStake($b, $this->htg(50_00), 'bet:2:stake', '2');
        $this->ledger->refund($b, $this->htg(50_00), 'bet:2:refund', '2');

        // Borlette : Bruno mise 20 et gagne 40.
        $this->ledger->reserveStake($b, $this->htg(20_00), 'lottery:1:stake', '1', 'lottery_ticket');
        $this->ledger->payout($b, $this->htg(40_00), 'lottery:1:payout', '1', 'lottery_ticket');

        // Retrait demandé, pas encore versé ; Carla n'a pas joué (pas active).
        PaymentTransaction::create([
            'user_id' => $carla->id, 'provider' => 'fake', 'direction' => PaymentDirection::Payout, 'status' => PaymentStatus::Pending,
            'amount_minor' => 150_00, 'currency' => 'HTG', 'reference' => 'pay_pending',
        ]);

        $data = $this->dashboard();

        $this->assertSame([
            'deposits_count' => 2,
            'deposits_minor' => 800_00,
            'depositors' => 2,
            'first_deposits' => 1, // Bruno ; Alice avait déjà déposé
            'withdrawals_count' => 2,
            'withdrawals_minor' => 100_00, // 200 rendus à Alice
            'net_deposits_minor' => 700_00,
            'pending_withdrawals_count' => 1,
            'pending_withdrawals_minor' => 150_00,
        ], array_diff_key($data['finance']['payments'], ['deposits_daily_minor' => true]));
        $this->assertSame(800_00, collect($data['finance']['payments']['deposits_daily_minor'])->firstWhere('date', '2026-11-20')['value']);

        $this->assertSame([
            'stakes_minor' => 170_00,
            'returns_minor' => 90_00,
            'gross_gaming_revenue_minor' => 80_00,
            'by_product' => [
                // Mises 150 ; retours 250 - 250 (correction) + 50 (remboursement) = 50.
                ['product' => 'sports', 'stakes_minor' => 150_00, 'returns_minor' => 50_00, 'gross_gaming_revenue_minor' => 100_00],
                ['product' => 'lottery', 'stakes_minor' => 20_00, 'returns_minor' => 40_00, 'gross_gaming_revenue_minor' => -20_00],
            ],
        ], $data['finance']['ledger']);

        $this->assertTrue($data['finance']['payments_available']);
        $this->assertTrue($data['finance']['withdrawals_available']);
        $this->assertSame(2, $data['users']['active_players']);
        $this->assertSame(40_00, $data['betting']['lottery']['payouts_minor']);
    }

    public function test_the_ledger_figures_follow_the_period(): void
    {
        $player = User::factory()->create();
        $wallet = $this->ledger->walletFor($player);

        $this->travel(-3)->days();
        $this->ledger->deposit($wallet, $this->htg(400_00), 'd:1', 'Dépôt', $this->admin);
        $this->ledger->reserveStake($wallet, $this->htg(100_00), 'bet:9:stake', '9');
        $this->travelBack();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 15:00:00', 'UTC'));

        $today = $this->dashboard('today');
        $this->assertSame(0, $today['finance']['payments']['deposits_minor']);
        $this->assertSame(0, $today['finance']['ledger']['stakes_minor']);
        $this->assertSame(0, $today['users']['active_players']);

        $week = $this->dashboard('7d');
        $this->assertSame(400_00, $week['finance']['payments']['deposits_minor']);
        $this->assertSame(1, $week['finance']['payments']['first_deposits']);
        $this->assertSame(100_00, $week['finance']['ledger']['gross_gaming_revenue_minor']);
        $this->assertSame(1, $week['users']['active_players']);
    }

    public function test_payments_follow_the_real_configuration_and_stay_hidden_without_ledger_access(): void
    {
        $this->assertFalse($this->dashboard()['finance']['payments_available']);

        config(['payments.providers.plopplop.enabled' => true]);
        $this->assertTrue($this->dashboard()['finance']['payments_available']);

        // Support : ni finance ni paiements (pas ledger.view).
        $support = User::factory()->withRole(RoleSlug::Support)->create();
        $this->actingAsVerifiedAdmin($support)->getJson('/api/v1/admin/dashboard')
            ->assertOk()->assertJsonMissingPath('data.finance');
    }
}
