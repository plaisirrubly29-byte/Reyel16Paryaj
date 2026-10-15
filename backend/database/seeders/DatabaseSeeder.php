<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\IdentityAccess\Models\Role;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        // Comptes de démonstration — environnement local uniquement (mot de passe : "password").
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $player = User::factory()->create([
            'name' => 'Joueur Démo',
            'email' => 'joueur@example.com',
        ]);

        // Fonds fictifs pour essayer le parcours de pari (10 000,00 dans la devise configurée).
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($player), Money::ofMinor(1_000_000, config('wallet.currency')), 'seed:demo-player:credit', 'Fonds de démonstration');

        foreach (RoleSlug::cases() as $slug) {
            User::factory()
                ->create(['name' => $slug->label().' Démo', 'email' => str_replace('_', '-', $slug->value).'@example.com'])
                ->roles()
                ->attach(Role::where('slug', $slug->value)->value('id'));
        }
    }
}
