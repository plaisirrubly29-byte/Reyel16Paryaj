<?php

namespace Database\Seeders;

use App\Modules\IdentityAccess\Models\Permission;
use App\Modules\IdentityAccess\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Synchronise permissions et rôles → permissions depuis config/permissions.php.
 * Idempotent ; retire les permissions qui ne figurent plus dans la config.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $catalog = config('permissions.permissions');

        DB::transaction(function () use ($catalog) {
            foreach ($catalog as $slug => $name) {
                Permission::updateOrCreate(['slug' => $slug], ['name' => $name]);
            }

            Permission::whereNotIn('slug', array_keys($catalog))->delete();

            foreach (config('permissions.roles') as $roleSlug => $permissionSlugs) {
                $unknown = array_diff($permissionSlugs, array_keys($catalog));
                if ($unknown !== []) {
                    throw new InvalidArgumentException("Permissions inconnues pour {$roleSlug} : ".implode(', ', $unknown));
                }

                Role::where('slug', $roleSlug)->firstOrFail()
                    ->permissions()
                    ->sync(Permission::whereIn('slug', $permissionSlugs)->pluck('id'));
            }
        });
    }
}
