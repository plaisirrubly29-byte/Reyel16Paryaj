<?php

namespace Tests\Feature\Modules\Wallet;

use App\Models\User;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        config(['wallet.currency' => 'HTG']);
        $this->ledger = app(LedgerService::class);
    }

    private function fund(User $user, int $minor, string $reference): void
    {
        $this->ledger->creditSimulated($this->ledger->walletFor($user), Money::ofMinor($minor, 'HTG'), $reference);
    }

    public function test_wallet_requires_authentication(): void
    {
        $this->getJson('/api/v1/wallet')->assertUnauthorized();
        $this->getJson('/api/v1/wallet/transactions')->assertUnauthorized();
    }

    public function test_new_player_sees_an_empty_wallet(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertExactJson(['data' => [
                'currency' => 'HTG',
                'balance' => ['minor' => 0, 'currency' => 'HTG'],
                'status' => 'active',
                'simulated' => true,
            ]]);
    }

    public function test_balance_is_provided_by_the_server_in_minor_units(): void
    {
        $player = User::factory()->create();
        $this->fund($player, 150_75, 'demo:1');
        $this->ledger->reserveStake($this->ledger->walletFor($player), Money::ofMinor(50_00, 'HTG'), 'bet:1:stake', '1');

        $this->actingAs($player)->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.balance', ['minor' => 100_75, 'currency' => 'HTG']);
    }

    public function test_transactions_are_paginated_newest_first_and_filterable(): void
    {
        $player = User::factory()->create();
        $this->fund($player, 100_00, 'demo:1');
        $this->ledger->reserveStake($this->ledger->walletFor($player), Money::ofMinor(10_00, 'HTG'), 'bet:1:stake', '1');
        $this->ledger->reserveStake($this->ledger->walletFor($player), Money::ofMinor(20_00, 'HTG'), 'bet:2:stake', '2');

        $this->actingAs($player)->getJson('/api/v1/wallet/transactions?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.amount', ['minor' => -20_00, 'currency' => 'HTG'])
            ->assertJsonPath('data.0.balance_after.minor', 70_00)
            ->assertJsonPath('data.0.related', ['type' => 'bet', 'id' => '2'])
            ->assertJsonPath('meta.total', 3);

        $this->actingAs($player)->getJson('/api/v1/wallet/transactions?type=simulated_credit')
            ->assertJsonCount(1, 'data');
    }

    public function test_a_player_only_ever_sees_their_own_wallet(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->fund($alice, 500_00, 'demo:alice');
        $this->fund($bob, 1_00, 'demo:bob');

        $this->actingAs($bob)->getJson('/api/v1/wallet')->assertJsonPath('data.balance.minor', 1_00);
        $this->actingAs($bob)->getJson('/api/v1/wallet/transactions')
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['minor' => 500_00]);

        // Les paramètres d'URL ne permettent pas de lire un autre portefeuille.
        $this->actingAs($bob)->getJson("/api/v1/wallet?user_id={$alice->id}")->assertJsonPath('data.balance.minor', 1_00);
    }

    public function test_internal_fields_are_not_exposed(): void
    {
        $player = User::factory()->create();
        $this->fund($player, 100_00, 'demo:1');

        $this->actingAs($player)->getJson('/api/v1/wallet/transactions')
            ->assertJsonMissingPath('data.0.reference')
            ->assertJsonMissingPath('data.0.created_by')
            ->assertJsonMissingPath('data.0.metadata');
    }

    public function test_suspended_player_cannot_read_the_wallet(): void
    {
        $player = User::factory()->withStatus(AccountStatus::Suspended)->create();

        $this->actingAs($player)->getJson('/api/v1/wallet')->assertStatus(423);
    }

    public function test_write_methods_are_not_allowed(): void
    {
        $player = User::factory()->create();

        $this->actingAs($player)->postJson('/api/v1/wallet', ['balance' => 999999])->assertStatus(405);
        $this->actingAs($player)->patchJson('/api/v1/wallet', ['balance' => 999999])->assertStatus(405);
    }
}
