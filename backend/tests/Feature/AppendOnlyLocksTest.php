<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Services\FinanceCorrectionService;
use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * En Production, le compte de l'application n'a que SELECT et INSERT sur les tables en ajout seul
 * (scripts/db/restrict-app-role.sql). PostgreSQL refuse alors tout SELECT … FOR UPDATE / FOR SHARE sur ces tables
 * (« permission denied », erreur 500 à l'enregistrement des paramètres le 2026-11-22) : on n'y verrouille jamais de
 * ligne. Utile surtout dans l'étape PostgreSQL de la CI : SQLite n'écrit pas de clause FOR UPDATE.
 */
class AppendOnlyLocksTest extends TestCase
{
    use RefreshDatabase;

    private const APPEND_ONLY = ['audit_logs', 'ledger_entries', 'results', 'settlements', 'setting_versions'];

    /** @var list<string> */
    private array $locks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $tables = implode('|', self::APPEND_ONLY);
        DB::listen(function (QueryExecuted $query) use ($tables) {
            if (preg_match('/from\s+"?('.$tables.')"?\b.*\bfor\s+(update|share|no key update|key share)\b/is', $query->sql)) {
                $this->locks[] = $query->sql;
            }
        });
    }

    public function test_saving_the_settings_locks_no_append_only_row(): void
    {
        $admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();
        $settings = app(PlatformSettings::class);

        $settings->update($admin, ['betting.allow_live' => true], 'Ouverture des paris en direct', 0);
        $settings->update($admin, ['betting.allow_live' => false], 'Fermeture des paris en direct', 1);

        $this->assertSame([], $this->locks);
    }

    public function test_requesting_a_finance_correction_locks_no_ledger_row(): void
    {
        $ledger = app(LedgerService::class);
        $entry = $ledger->creditSimulated($ledger->walletFor(User::factory()->create()), Money::ofMinor(500_00, 'HTG'), 'test:credit');

        app(FinanceCorrectionService::class)->request(User::factory()->withRole(RoleSlug::SuperAdmin)->create(), $entry, 'Crédit saisi par erreur');

        $this->assertSame([], $this->locks);
    }
}
