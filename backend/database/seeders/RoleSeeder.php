<?php

namespace Database\Seeders;

use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\IdentityAccess\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Rôles administratifs V1. Idempotent : peut être relancé en Staging/Production.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoleSlug::cases() as $role) {
            Role::updateOrCreate(
                ['slug' => $role->value],
                ['name' => $role->label(), 'description' => $role->description(), 'is_admin' => true],
            );
        }
    }
}
