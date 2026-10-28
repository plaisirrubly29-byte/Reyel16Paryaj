<?php

namespace Tests\Feature\Modules\Wallet;

use App\Models\User;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

class LedgerSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function entry(Wallet $wallet, string $reference, int $amount = 1000): LedgerEntry
    {
        return LedgerEntry::create([
            'wallet_id' => $wallet->id,
            'type' => LedgerEntryType::SimulatedCredit,
            'amount_minor' => $amount,
            'currency' => 'HTG',
            'balance_after_minor' => $amount,
            'reference' => $reference,
        ]);
    }

    private function wallet(): Wallet
    {
        return Wallet::create(['user_id' => User::factory()->create()->id, 'currency' => 'HTG']);
    }

    public function test_business_reference_is_unique(): void
    {
        $wallet = $this->wallet();
        $this->entry($wallet, 'bonus:1');

        $this->expectException(QueryException::class);
        $this->entry($wallet, 'bonus:1');
    }

    public function test_one_wallet_per_user_and_currency(): void
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'currency' => 'HTG']);

        $this->expectException(QueryException::class);
        Wallet::create(['user_id' => $user->id, 'currency' => 'HTG']);
    }

    public function test_entries_are_append_only(): void
    {
        $entry = $this->entry($this->wallet(), 'bonus:1');

        $this->expectException(LogicException::class);
        $entry->update(['amount_minor' => 999999]);
    }

    public function test_entries_cannot_be_deleted(): void
    {
        $entry = $this->entry($this->wallet(), 'bonus:1');

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_user_with_a_wallet_cannot_be_hard_deleted(): void
    {
        $wallet = $this->wallet();
        $this->entry($wallet, 'bonus:1');

        $this->expectException(QueryException::class);
        $wallet->user->delete();
    }

    public function test_no_api_route_can_modify_a_balance(): void
    {
        $writeRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/wallet'))
            ->filter(fn ($route) => array_diff($route->methods(), ['GET', 'HEAD']) !== []);

        $this->assertCount(0, $writeRoutes, 'Une route du portefeuille accepte une écriture : '.$writeRoutes->map->uri()->implode(', '));
    }
}
