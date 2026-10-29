<?php

namespace Tests\Feature\Modules\IdentityAccess;

use App\Models\User;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WelcomeCreditTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $phone = '34123456')
    {
        return $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/auth/register', [
            'name' => 'Nouveau Joueur',
            'phone' => $phone,
            'password' => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
            'birth_date' => now()->subYears(25)->toDateString(),
        ]);
    }

    public function test_a_new_player_receives_the_welcome_funds_and_can_see_them(): void
    {
        config(['wallet.welcome_credit_minor' => 500_000]);

        $this->register()->assertCreated();

        $this->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.balance', ['minor' => 500_000, 'currency' => 'HTG']);

        $entry = LedgerEntry::sole();
        $this->assertSame(LedgerEntryType::SimulatedCredit, $entry->type);
        $this->assertSame('welcome:user:'.User::sole()->id, $entry->reference);
    }

    public function test_the_welcome_credit_can_be_disabled(): void
    {
        config(['wallet.welcome_credit_minor' => 0]);

        $this->register()->assertCreated();

        $this->assertSame(0, LedgerEntry::count());
        $this->getJson('/api/v1/wallet')->assertJsonPath('data.balance.minor', 0);
    }

    public function test_a_refused_registration_credits_nothing(): void
    {
        User::factory()->create(['phone' => '34123456']);

        $this->register('34123456')->assertUnprocessable();

        $this->assertSame(0, LedgerEntry::count());
    }
}
