<?php

namespace Tests\Feature\Modules\Lottery;

use App\Models\User;
use App\Modules\Compliance\Services\ResponsibleGamingService;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Lottery\Contracts\LotteryProvider;
use App\Modules\Lottery\Models\LotteryPlay;
use App\Modules\Lottery\Models\LotteryResult;
use App\Modules\Lottery\Models\LotteryTicket;
use App\Modules\Lottery\Providers\FakeLotteryProvider;
use App\Modules\Lottery\Services\LotterySettlementService;
use App\Modules\Lottery\Services\LotterySyncService;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Vente des fiches de borlette, règlement et administration (V2-D09), du choix du tirage au paiement des gains. */
class LotteryTicketTest extends TestCase
{
    use RefreshDatabase;

    private FakeLotteryProvider $fake;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lottery.enabled' => true, 'lottery.sales.enabled' => true, 'wallet.simulated' => true]);
        // 4 novembre 2026, 14:00 à New York : New York Midi (14:30) ferme à 14:25.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 19:00:00', 'UTC'));

        $this->fake = (new FakeLotteryProvider)->withoutResults();
        $this->app->instance(LotteryProvider::class, $this->fake);
        app(LotterySyncService::class)->sync(1);

        $this->player = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($this->player), Money::ofMinor(1000_00, 'HTG'), 'test:'.Str::uuid());
    }

    /** @param  list<array<string, mixed>>  $plays */
    private function sell(array $plays, string $draw = 'ny_midday', ?string $date = null, ?string $key = null, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->player)->postJson('/api/v1/lottery/tickets', array_filter([
            'draw' => $draw,
            'date' => $date,
            'plays' => $plays,
        ], fn ($v) => $v !== null), ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function play(string $game, array $numbers, int $stake = 10_00, ?int $option = null): array
    {
        return ['game' => $game, 'option' => $option, 'numbers' => $numbers, 'stake' => ['minor' => $stake, 'currency' => 'HTG']];
    }

    private function balance(): int
    {
        $ledger = app(LedgerService::class);

        return $ledger->balance($ledger->walletFor($this->player))->minor;
    }

    /** Publie le résultat de New York Midi du 4 novembre, après le tirage, puis synchronise (et règle). */
    private function publish(string $lot1, string $lot2, string $lot3, ?string $pick3 = null): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 20:00:00', 'UTC'));
        $this->fake->setResult('ny_midday', '2026-11-04', $lot1, $lot2, $lot3, $pick3, null);
        $this->artisan('lottery:sync', ['--days' => 1])->assertSuccessful();
    }

    public function test_games_list_the_scale_while_sales_are_open_and_nothing_when_closed(): void
    {
        $games = $this->getJson('/api/v1/lottery/games')->assertOk();
        $games->assertJsonPath('data.sales_open', true)
            ->assertJsonPath('data.close_before_minutes', 5)
            ->assertJsonPath('data.games.0.key', 'bolet')
            ->assertJsonPath('data.games.0.payouts.0', ['lot' => 1, 'multiplier' => '50'])
            ->assertJsonPath('data.games.1.payouts.0', ['lot' => null, 'multiplier' => '1000'])
            ->assertJsonPath('data.games.3.options', [1, 2, 3]);

        config(['lottery.sales.enabled' => false]);
        $this->getJson('/api/v1/lottery/games')->assertOk()->assertJsonPath('data.sales_open', false)->assertJsonPath('data.games', []);
        $this->sell([$this->play('bolet', ['25'])])->assertForbidden()->assertJsonPath('error.code', 'lottery_sales_closed');

        config(['lottery.enabled' => false]);
        $this->getJson('/api/v1/lottery/games')->assertNotFound();
    }

    public function test_a_ticket_is_sold_for_the_next_open_draw_and_the_stake_debited(): void
    {
        $response = $this->sell([
            $this->play('bolet', ['25'], 50_00),
            $this->play('maryaj', ['25', '07'], 20_00),
            $this->play('loto4', ['2507'], 10_00, 1),
        ])->assertCreated();

        $response->assertJsonPath('data.draw.key', 'ny_midday')
            ->assertJsonPath('data.draw.date', '2026-11-04')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.stake', ['minor' => 80_00, 'currency' => 'HTG'])
            // 50 × 50 + 20 × 1000 + 10 × 5000
            ->assertJsonPath('data.max_payout.minor', 2500_00 + 20000_00 + 50000_00)
            ->assertJsonPath('data.plays.1.numbers', ['07', '25'])
            ->assertJsonPath('data.result', null);
        $this->assertMatchesRegularExpression('/^B16-[0-9A-Z]{10}$/', $response->json('data.reference'));
        $this->assertSame(920_00, $this->balance());

        $entry = LedgerEntry::where('related_type', 'lottery_ticket')->sole();
        $this->assertSame(-80_00, $entry->amount_minor);

        // Fiche visible dans « Mes paris », et seulement pour son joueur.
        $this->actingAs($this->player)->getJson('/api/v1/lottery/tickets')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs(User::factory()->create())->getJson('/api/v1/lottery/tickets/'.$response->json('data.id'))->assertNotFound();
    }

    public function test_a_replay_returns_the_same_ticket_and_debits_once(): void
    {
        $key = (string) Str::uuid();
        $first = $this->sell([$this->play('bolet', ['25'])], key: $key)->assertCreated();
        $this->sell([$this->play('bolet', ['25'])], key: $key)->assertSuccessful()->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, LotteryTicket::count());
        $this->assertSame(990_00, $this->balance());
    }

    public function test_sales_close_before_the_draw_and_never_silently_move_to_another_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 19:26:00', 'UTC')); // 14:26 à New York, fin des ventes 14:25

        $this->sell([$this->play('bolet', ['25'])], date: '2026-11-04')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'lottery_draw_closed')
            ->assertJsonPath('error.details.next_date', '2026-11-05');

        // Sans date : la prochaine occurrence ouverte.
        $this->sell([$this->play('bolet', ['25'])])->assertCreated()->assertJsonPath('data.draw.date', '2026-11-05');
    }

    public function test_invalid_lines_are_refused_line_by_line_and_nothing_is_debited(): void
    {
        $this->sell([
            $this->play('bolet', ['5']),
            $this->play('maryaj', ['25', '25']),
            $this->play('loto4', ['2507']),
            $this->play('bolet', ['25'], 1_00),
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        $this->sell([$this->play('bolet', ['25'])], draw: 'unknown')->assertUnprocessable()->assertJsonPath('error.code', 'lottery_draw_unknown');
        $this->sell([$this->play('bolet', ['25'], 2000_00)])->assertUnprocessable()->assertJsonPath('error.code', 'insufficient_funds');
        $this->sell([$this->play('bolet', ['25'], 5001_00)])->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame(0, LotteryTicket::count());
        $this->assertSame(1000_00, $this->balance());
    }

    public function test_insufficient_funds_refuse_the_whole_ticket(): void
    {
        $this->sell([$this->play('bolet', ['25'], 4000_00), $this->play('bolet', ['26'], 4000_00)])
            ->assertUnprocessable()->assertJsonPath('error.code', 'insufficient_funds');

        $this->assertSame(0, LotteryTicket::count());
        $this->assertSame(0, LotteryPlay::count());
    }

    public function test_exposure_per_number_is_capped_and_the_remaining_stake_given(): void
    {
        // Plafond 250 000 HTG de gain possible ; bolèt à 50 × : 5 000 HTG de mise au plus sur une boule.
        config(['lottery.sales.max_exposure_per_number_minor' => 25_000_00]);

        $this->sell([$this->play('bolet', ['25'], 300_00)])->assertCreated();
        $this->sell([$this->play('bolet', ['25'], 300_00)])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'lottery_number_limit')
            ->assertJsonPath('error.details.remaining_stake.minor', 200_00);
        $this->sell([$this->play('bolet', ['26'], 300_00)])->assertCreated();
    }

    public function test_blocked_numbers_are_refused_and_admins_manage_them(): void
    {
        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();

        $block = $this->actingAsVerifiedAdmin($admin)->postJson('/api/v1/admin/lottery/blocked-numbers',
            ['number' => '25', 'draw' => 'ny_midday', 'reason' => 'Trop joué ce matin'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.number', '25');
        $this->actingAsVerifiedAdmin($admin)->postJson('/api/v1/admin/lottery/blocked-numbers',
            ['number' => '25', 'draw' => 'ny_midday', 'reason' => 'Encore une fois'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('error.code', 'lottery_number_already_blocked');

        // La boule bloque aussi le loto 4 qui la contient.
        $this->sell([$this->play('loto4', ['0725'], 10_00, 1)])
            ->assertUnprocessable()->assertJsonPath('error.code', 'lottery_number_blocked')->assertJsonPath('error.details.numbers', ['25']);
        $this->sell([$this->play('bolet', ['25'])], draw: 'ny_evening')->assertCreated(); // autre tirage

        $this->actingAsVerifiedAdmin($admin)->deleteJson('/api/v1/admin/lottery/blocked-numbers/'.$block->json('data.id'),
            ['reason' => 'Fin du blocage'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.lift_reason', 'Fin du blocage');
        $this->sell([$this->play('bolet', ['25'])])->assertCreated();

        $this->actingAsVerifiedAdmin($admin)->getJson('/api/v1/admin/lottery/tickets?draw=ny_midday')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.id', (string) $this->player->id);
        $this->actingAsVerifiedAdmin($admin)->getJson('/api/v1/admin/lottery/exposure?draw=ny_midday&date=2026-11-04')
            ->assertOk()->assertJsonPath('data.tickets_count', 1)
            ->assertJsonPath('data.numbers.0.numbers', ['25'])
            ->assertJsonPath('data.numbers.0.max_payout.minor', 500_00);
    }

    public function test_winning_lines_are_paid_when_the_result_is_published(): void
    {
        $ticket = $this->sell([
            $this->play('bolet', ['45'], 10_00),          // 2e lot : 20 ×
            $this->play('maryaj', ['12', '07'], 10_00),   // 1er et 3e lots : 1000 ×
            $this->play('loto3', ['407'], 10_00),         // pick 3 : 500 ×
            $this->play('loto4', ['4512'], 10_00, 2),     // lot 2 + lot 3 : 5000 ×
            $this->play('loto5', ['40745'], 10_00, 1),    // pick 3 + lot 2 : 25 000 ×
            $this->play('bolet', ['99'], 10_00),          // perdue
        ])->assertCreated()->json('data.id');
        $this->assertSame(940_00, $this->balance());

        $this->publish('07', '45', '12', '407');

        $won = 200_00 + 10000_00 + 5000_00 + 50000_00 + 250000_00;
        $this->actingAs($this->player)->getJson("/api/v1/lottery/tickets/{$ticket}")
            ->assertOk()
            ->assertJsonPath('data.status', 'won')
            ->assertJsonPath('data.payout.minor', $won)
            ->assertJsonPath('data.plays.0.winning_lot', 2)
            ->assertJsonPath('data.plays.5.status', 'lost')
            ->assertJsonPath('data.result.lots', ['07', '45', '12']);
        $this->assertSame(940_00 + $won, $this->balance());

        // Idempotent : un second passage ne paie rien de plus.
        app(LotterySettlementService::class)->settleDue();
        $this->assertSame(940_00 + $won, $this->balance());
    }

    public function test_a_losing_ticket_pays_nothing_and_a_missing_pick3_refunds_loto3(): void
    {
        $lost = $this->sell([$this->play('bolet', ['99'])])->json('data.id');
        $mixed = $this->sell([$this->play('loto3', ['407']), $this->play('bolet', ['98'])])->json('data.id');

        $this->publish('07', '45', '12'); // sans pick 3

        $this->assertSame('lost', LotteryTicket::find($lost)->status->value);
        $ticket = LotteryTicket::find($mixed);
        $this->assertSame('lost', $ticket->status->value);
        $this->assertSame(10_00, $ticket->payout_minor); // ligne loto 3 remboursée
        $this->assertSame(1000_00 - 30_00 + 10_00, $this->balance());
    }

    public function test_a_draw_without_result_is_refunded_after_the_delay(): void
    {
        $this->sell([$this->play('bolet', ['25'], 50_00)])->assertCreated();

        $this->travelTo(CarbonImmutable::parse('2026-11-05 20:00:00', 'UTC'));
        $this->artisan('lottery:settle')->assertSuccessful();
        $this->assertSame('pending', LotteryTicket::sole()->status->value);

        $this->travelTo(CarbonImmutable::parse('2026-11-06 20:00:00', 'UTC'));
        $this->artisan('lottery:settle')->assertSuccessful();
        $this->assertSame('void', LotteryTicket::sole()->status->value);
        $this->assertSame(1000_00, $this->balance());
        $this->assertSame(0, LotteryResult::count());
    }

    public function test_responsible_gaming_pause_refuses_the_ticket(): void
    {
        app(ResponsibleGamingService::class)->pause($this->player, '24h');

        $this->sell([$this->play('bolet', ['25'])])->assertUnprocessable()->assertJsonPath('error.code', 'betting_paused');
        $this->assertSame(0, LotteryTicket::count());
        $this->assertSame(1000_00, $this->balance());
    }

    public function test_admins_cannot_buy_tickets(): void
    {
        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();

        $this->sell([$this->play('bolet', ['25'])], as: $admin)->assertUnprocessable()->assertJsonPath('error.code', 'not_a_player');
    }
}
