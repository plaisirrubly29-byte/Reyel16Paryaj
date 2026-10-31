<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Admin\Models\SettingVersion;
use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdminSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        config([
            'betting.min_stake_minor' => 10_00,
            'betting.max_stake_minor' => 50_000_00,
            'betting.max_payout_minor' => 1_000_000_00,
            'betting.allow_live' => false,
            'sports_data.provider' => 'fake',
            'sports_data.theodds.key' => null,
        ]);
        $this->superAdmin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();
    }

    /** @param array<string, mixed> $values */
    private function saveSettings(array $values, int $baseVersion = 0, ?User $admin = null, string $reason = 'Ajustement des limites V1'): TestResponse
    {
        return $this->actingAsVerifiedAdmin($admin ?? $this->superAdmin)->putJson('/api/v1/admin/settings', [
            'values' => $values,
            'base_version' => $baseVersion,
            'reason' => $reason,
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_lists_the_editable_settings_with_their_defaults(): void
    {
        $this->actingAsVerifiedAdmin($this->superAdmin)->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.version', 0)
            ->assertJsonPath('data.updated_by', null)
            ->assertJsonCount(count(PlatformSettings::DEFINITIONS), 'data.items')
            ->assertJsonPath('data.items.0.key', 'betting.min_stake_minor')
            ->assertJsonPath('data.items.0.value', 10_00)
            ->assertJsonPath('data.items.0.default', 10_00)
            ->assertJsonPath('data.items.0.currency', 'HTG');
    }

    public function test_an_update_creates_an_audited_version_and_applies_at_once(): void
    {
        $this->saveSettings(['betting' => ['min_stake_minor' => 25_00, 'allow_live' => true]])
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.updated_by.id', (string) $this->superAdmin->id)
            ->assertJsonPath('data.items.0.value', 25_00)
            ->assertJsonPath('data.items.0.default', 10_00);

        $version = SettingVersion::sole();
        $this->assertSame(['betting.min_stake_minor' => ['from' => 10_00, 'to' => 25_00], 'betting.allow_live' => ['from' => false, 'to' => true]], $version->changes);
        $this->assertSame(25_00, config('betting.min_stake_minor'));
        $this->assertTrue(config('betting.allow_live'));

        $log = AuditLog::where('action', AuditAction::SettingsUpdated)->sole();
        $this->assertSame('Ajustement des limites V1', $log->reason);
        $this->assertSame(['betting.min_stake_minor' => 10_00, 'betting.allow_live' => false], $log->before);
        $this->assertSame(['betting.min_stake_minor' => 25_00, 'betting.allow_live' => true], $log->after);
        $this->assertSame($this->superAdmin->id, $log->actor_id);
    }

    public function test_the_latest_version_applies_on_top_of_config_in_a_new_process(): void
    {
        $this->saveSettings(['betting' => ['max_payout_minor' => 200_000_00]])->assertOk();
        $this->saveSettings(['wallet' => ['welcome_credit_minor' => 0]], baseVersion: 1)->assertOk();

        // Nouveau processus : config/ revient à ses valeurs, puis la dernière version s'applique.
        config(['betting.max_payout_minor' => 1_000_000_00, 'wallet.welcome_credit_minor' => 5_000_00]);
        (new PlatformSettings)->apply();

        $this->assertSame(200_000_00, config('betting.max_payout_minor'));
        $this->assertSame(0, config('wallet.welcome_credit_minor'));
    }

    public function test_versions_are_listed_newest_first_with_their_changes(): void
    {
        $this->saveSettings(['betting' => ['max_selections' => 10]])->assertOk();
        $this->saveSettings(['betting' => ['max_selections' => 12]], baseVersion: 1, reason: 'Retour à plus de sélections')->assertOk();

        $this->actingAsVerifiedAdmin($this->superAdmin)->getJson('/api/v1/admin/settings/versions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.version', 2)
            ->assertJsonPath('data.0.reason', 'Retour à plus de sélections')
            ->assertJsonPath('data.0.changes.0', ['key' => 'betting.max_selections', 'label' => 'Sélections maximum par combiné', 'type' => 'integer', 'from' => 10, 'to' => 12])
            ->assertJsonPath('data.1.version', 1);
    }

    public function test_a_stale_base_version_is_refused_instead_of_overwriting(): void
    {
        $this->saveSettings(['betting' => ['max_selections' => 10]])->assertOk();

        $this->saveSettings(['betting' => ['max_selections' => 15]], baseVersion: 0)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'settings_changed')
            ->assertJsonPath('error.details.version', 1);

        $this->assertSame(1, SettingVersion::count());
    }

    public function test_inconsistent_unknown_or_unchanged_values_are_refused(): void
    {
        $this->saveSettings(['betting' => ['max_stake_minor' => 5_00]])
            ->assertUnprocessable()->assertJsonValidationErrors('values.betting.max_stake_minor', 'error.details');
        $this->saveSettings(['betting' => ['max_stake_minor' => 1_000_000_00, 'max_payout_minor' => 500_000_00]])
            ->assertUnprocessable()->assertJsonValidationErrors('values.betting.max_payout_minor', 'error.details');
        $this->saveSettings(['sports_data' => ['provider' => 'theodds']])
            ->assertUnprocessable()->assertJsonValidationErrors('values.sports_data.provider', 'error.details');
        $this->saveSettings(['betting' => ['rules_version' => 'v2']])
            ->assertUnprocessable()->assertJsonValidationErrors('values', 'error.details');
        $this->saveSettings(['betting' => ['min_stake_minor' => -1]])
            ->assertUnprocessable()->assertJsonValidationErrors('values.betting.min_stake_minor', 'error.details');
        $this->saveSettings(['betting' => ['min_stake_minor' => 10_00]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'no_change');
        $this->saveSettings(['betting' => ['min_stake_minor' => 20_00]], reason: '')
            ->assertUnprocessable()->assertJsonValidationErrors('reason', 'error.details');

        $this->assertSame(0, SettingVersion::count());
        $this->assertSame(10_00, config('betting.min_stake_minor'));
    }

    public function test_compliance_can_read_but_not_change_and_operations_cannot_read(): void
    {
        $compliance = User::factory()->withRole(RoleSlug::Compliance)->create();
        $operations = User::factory()->withRole(RoleSlug::Operations)->create();

        $this->actingAsVerifiedAdmin($compliance)->getJson('/api/v1/admin/settings')->assertOk();
        $this->actingAsVerifiedAdmin($compliance)->getJson('/api/v1/admin/settings/versions')->assertOk();
        $this->saveSettings(['betting' => ['max_selections' => 10]], admin: $compliance)->assertForbidden();
        $this->actingAsVerifiedAdmin($operations)->getJson('/api/v1/admin/settings')->assertForbidden();

        $this->assertSame(0, SettingVersion::count());
    }
}
