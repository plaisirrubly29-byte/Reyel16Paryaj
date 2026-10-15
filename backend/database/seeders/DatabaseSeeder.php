<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\IdentityAccess\Models\Role;
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

        User::factory()->create([
            'name' => 'Joueur Démo',
            'email' => 'joueur@example.com',
        ]);

        foreach (RoleSlug::cases() as $slug) {
            User::factory()
                ->create(['name' => $slug->label().' Démo', 'email' => str_replace('_', '-', $slug->value).'@example.com'])
                ->roles()
                ->attach(Role::where('slug', $slug->value)->value('id'));
        }
    }
}
