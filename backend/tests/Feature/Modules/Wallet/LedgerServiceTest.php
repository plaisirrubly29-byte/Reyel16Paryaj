<?php

namespace Tests\Feature\Modules\Wallet;

use App\Models\User;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Http\ApiException;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        config(['wallet.currency' => 'HTG']);
        $this->ledger = app(LedgerService::class);
        $this->wallet = $this->ledger->walletFor(User::factory()->create());
    }

    private function htg(int $minor): Money
    {
        return Money::ofMinor($minor, 'HTG');
    }

    public function test_wallet_is_created_once_per_user_in_the_configured_currency(): void
    {
        $again = $this->ledger->walletFor($this->wallet->user);

        $this->assertSame($this->wallet->id, $again->id);
        $this->assertSame('HTG', $again->currency);
        $this->assertTrue($this->ledger->balance($this->wallet)->equals($this->htg(0)));
    }

    public function test_balance_is_the_sum_of_entries(): void
    {
        $this->ledger->creditSimulated($this->wallet, $this->htg(100_00), 'demo:1');
        $this->ledger->reserveStake($this->wallet, $this->htg(25_00), 'bet:1:stake', '1');
        $this->ledger->payout($this->wallet, $this->htg(60_00), 'bet:1:payout', '1');
        $this->ledger->reserveStake($this->wallet, $this->htg(10_00), 'bet:2:stake', '2');
        $this->ledger->refund($this->wallet, $this->htg(10_00), 'bet:2:refund', '2');

        $sum = (int) LedgerEntry::where('wallet_id', $this->wallet->id)->sum('amount_minor');
        $last = LedgerEntry::where('wallet_id', $this->wallet->id)->latest('id')->first();

        $this->assertSame(135_00, $this->ledger->balance($this->wallet)->minor);
        $this->assertSame(135_00, $sum);
        $this->assertSame(135_00, $last->balance_after_minor);
    }

    public function test_entry_sign_is_decided_by_its_type(): void
    {
        $this->ledger->creditSimulated($this->wallet, $this->htg(50_00), 'demo:1');
        $stake = $this->ledger->reserveStake($this->wallet, $this->htg(20_00), 'bet:1:stake');

        $this->assertSame(LedgerEntryType::Stake, $stake->type);
        $this->assertSame(-20_00, $stake->amount_minor);
    }

    public function test_amount_must_be_positive_and_in_wallet_currency(): void
    {
        try {
            $this->ledger->creditSimulated($this->wallet, $this->htg(-5), 'demo:neg');
            $this->fail('Montant négatif accepté.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $this->ledger->creditSimulated($this->wallet, Money::ofMinor(100, 'USD'), 'demo:usd');
    }

    public function test_stake_is_refused_when_funds_are_insufficient(): void
    {
        $this->ledger->creditSimulated($this->wallet, $this->htg(10_00), 'demo:1');

        try {
            $this->ledger->reserveStake($this->wallet, $this->htg(10_01), 'bet:1:stake');
            $this->fail('Mise acceptée sans fonds suffisants.');
        } catch (ApiException $e) {
            $this->assertSame('insufficient_funds', $e->errorCode);
        }

        $this->assertSame(10_00, $this->ledger->balance($this->wallet)->minor);
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_replaying_a_reference_never_writes_twice(): void
    {
        $this->ledger->creditSimulated($this->wallet, $this->htg(100_00), 'demo:1');

        $first = $this->ledger->payout($this->wallet, $this->htg(30_00), 'bet:9:payout', '9');
        $replay = $this->ledger->payout($this->wallet, $this->htg(30_00), 'bet:9:payout', '9');

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(130_00, $this->ledger->balance($this->wallet)->minor);
    }

    public function test_reusing_a_reference_for_another_operation_is_a_bug(): void
    {
        $this->ledger->creditSimulated($this->wallet, $this->htg(100_00), 'demo:1');
        $this->ledger->payout($this->wallet, $this->htg(30_00), 'bet:9:payout');

        $this->expectException(LogicException::class);
        $this->ledger->payout($this->wallet, $this->htg(99_00), 'bet:9:payout');
    }

    public function test_corrections_are_compensating_entries(): void
    {
        $this->ledger->creditSimulated($this->wallet, $this->htg(100_00), 'demo:1');
        $wrongPayout = $this->ledger->payout($this->wallet, $this->htg(40_00), 'bet:5:payout', '5');

        $reversal = $this->ledger->reverse($wrongPayout, 'bet:5:payout:reversal', 'Résultat corrigé');

        $this->assertSame(LedgerEntryType::Reversal, $reversal->type);
        $this->assertSame(-40_00, $reversal->amount_minor);
        $this->assertSame($wrongPayout->id, $reversal->reversal_of_id);
        $this->assertSame('bet', $reversal->related_type);
        $this->assertSame(100_00, $this->ledger->balance($this->wallet)->minor);

        // L'écriture d'origine est intacte.
        $this->assertSame(40_00, $wrongPayout->fresh()->amount_minor);
    }

    public function test_an_entry_is_reversed_at_most_once(): void
    {
        $this->ledger->creditSimulated($this->wallet, $this->htg(100_00), 'demo:1');
        $payout = $this->ledger->payout($this->wallet, $this->htg(40_00), 'bet:5:payout');

        $first = $this->ledger->reverse($payout, 'bet:5:payout:reversal', 'Correction');
        $again = $this->ledger->reverse($payout, 'bet:5:payout:reversal', 'Correction');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(100_00, $this->ledger->balance($this->wallet)->minor);
    }

    public function test_a_reversal_cannot_be_reversed(): void
    {
        $this->ledger->creditSimulated($this->wallet, $this->htg(100_00), 'demo:1');
        $reversal = $this->ledger->reverse(LedgerEntry::firstOrFail(), 'demo:1:reversal', 'Erreur');

        $this->expectException(LogicException::class);
        $this->ledger->reverse($reversal, 'demo:1:reversal:reversal', 'Erreur');
    }

    public function test_frozen_wallet_cannot_be_debited_but_can_be_credited(): void
    {
        $this->ledger->creditSimulated($this->wallet, $this->htg(100_00), 'demo:1');
        $this->wallet->update(['status' => Wallet::STATUS_FROZEN]);

        $this->ledger->payout($this->wallet, $this->htg(5_00), 'bet:1:payout');

        $this->expectException(ApiException::class);
        $this->ledger->reserveStake($this->wallet, $this->htg(5_00), 'bet:2:stake');
    }

    public function test_simulated_credit_is_refused_outside_simulation_mode(): void
    {
        config(['wallet.simulated' => false]);

        $this->expectException(LogicException::class);
        $this->ledger->creditSimulated($this->wallet, $this->htg(100_00), 'demo:1');
    }
}
