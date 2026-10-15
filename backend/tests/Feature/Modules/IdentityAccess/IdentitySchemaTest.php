<?php

namespace Tests\Feature\Modules\IdentityAccess;

use App\Models\User;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\IdentityAccess\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentitySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_seeder_creates_the_five_admin_roles_and_is_idempotent(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['super_admin', 'operations', 'finance', 'support', 'compliance'],
            Role::pluck('slug')->all(),
        );
        $this->assertSame('Conformité', Role::where('slug', 'compliance')->value('name'));
    }

    public function test_new_user_is_active_and_status_is_not_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'Joueur',
            'email' => 'j@example.com',
            'password' => 'secret-password',
            'status' => 'closed',
        ]);

        $this->assertSame(AccountStatus::Active, $user->fresh()->status);
    }

    public function test_user_roles_and_admin_flag(): void
    {
        $this->seed(RoleSeeder::class);

        $player = User::factory()->create();
        $finance = User::factory()->withRole(RoleSlug::Finance)->create();

        $this->assertFalse($player->isAdmin());
        $this->assertTrue($finance->isAdmin());
        $this->assertTrue($finance->hasRole(RoleSlug::Finance));
        $this->assertFalse($finance->hasRole(RoleSlug::SuperAdmin));
    }

    public function test_profile_is_separate_and_deleted_with_the_user(): void
    {
        $user = User::factory()->create();
        $user->profile()->create(['first_name' => 'Ana', 'birth_date' => '2000-01-31', 'country' => 'HT']);

        $this->assertSame('2000-01-31', $user->profile->birth_date->toDateString());

        $user->delete();
        $this->assertDatabaseCount('user_profiles', 0);
    }

    public function test_a_role_in_use_cannot_be_deleted(): void
    {
        $this->seed(RoleSeeder::class);
        User::factory()->withRole(RoleSlug::Support)->create();

        $this->expectException(QueryException::class);

        Role::where('slug', 'support')->delete();
    }

    public function test_only_active_accounts_can_authenticate(): void
    {
        $this->assertTrue(AccountStatus::Active->canAuthenticate());
        $this->assertFalse(AccountStatus::Suspended->canAuthenticate());
        $this->assertFalse(AccountStatus::Excluded->canAuthenticate());
        $this->assertFalse(AccountStatus::Closed->canAuthenticate());
    }
}
