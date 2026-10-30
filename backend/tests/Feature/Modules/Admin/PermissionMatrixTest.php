<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Admin\Enums\FinanceCorrectionStatus;
use App\Modules\Admin\Models\FinanceCorrection;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Matrice des permissions validée par l'API (P6-09) : un test par ligne de docs/permissions-matrix.md.
 *
 * Chaque route de la ligne est appelée par un administrateur de chacun des cinq rôles (MFA vérifiée) :
 * - rôle autorisé : la requête passe le contrôle de permission (aucun 403 « forbidden », aucune erreur serveur) ;
 * - rôle interdit : 403 « forbidden », avant toute lecture de la cible.
 * Les écritures sont envoyées sans corps : un rôle autorisé reçoit la validation (422), rien n'est modifié.
 *
 * test_every_admin_route_is_covered_by_a_matrix_row : toute nouvelle route admin doit être rattachée à une ligne.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const SUPER_ADMIN = 'super_admin';

    private const OPERATIONS = 'operations';

    private const FINANCE = 'finance';

    private const SUPPORT = 'support';

    private const COMPLIANCE = 'compliance';

    private const ALL = [self::SUPER_ADMIN, self::OPERATIONS, self::FINANCE, self::SUPPORT, self::COMPLIANCE];

    /** Identifiant inexistant : la permission est vérifiée avant la résolution du modèle (404 seulement si autorisé). */
    private const MISSING = 999999;

    /** Permissions attendues par rôle, transcrites de docs/permissions-matrix.md (✅, 👁 et 2️⃣ ; *.approve = second validateur). */
    private const EXPECTED_PERMISSIONS = [
        self::SUPER_ADMIN => [
            'dashboard.view', 'events.view', 'markets.suspend', 'results.enter', 'results.approve', 'settlement.run',
            'settlement.approve', 'users.view', 'users.view_personal_data', 'users.suspend', 'tickets.view', 'ledger.view',
            'finance.correct', 'finance.approve', 'settings.view', 'settings.update', 'responsible_gaming.view',
            'audit.view', 'audit.export', 'roles.manage',
        ],
        self::OPERATIONS => ['dashboard.view', 'events.view', 'markets.suspend', 'results.enter', 'results.approve', 'tickets.view'],
        self::FINANCE => [
            'dashboard.view', 'events.view', 'settlement.run', 'settlement.approve', 'users.view', 'users.view_personal_data',
            'tickets.view', 'ledger.view', 'finance.correct', 'finance.approve', 'audit.view', 'audit.export',
        ],
        // Support : utilisateurs en données minimales (pas users.view_personal_data).
        self::SUPPORT => ['dashboard.view', 'events.view', 'users.view', 'users.suspend', 'tickets.view'],
        self::COMPLIANCE => [
            'dashboard.view', 'events.view', 'users.view', 'users.view_personal_data', 'users.suspend', 'tickets.view',
            'ledger.view', 'settings.view', 'responsible_gaming.view', 'responsible_gaming.manage', 'audit.view', 'audit.export',
        ],
    ];

    /**
     * Routes de chaque ligne de la matrice (« MÉTHODE uri » de la table de routage).
     *
     * @var array<string, list<string>>
     */
    private const ROWS = [
        "Vue d'ensemble" => ['GET api/v1/admin/dashboard', 'GET api/v1/admin/alerts', 'GET api/v1/admin/search'],
        'Événements & marchés — consulter' => [
            'GET api/v1/admin/events', 'GET api/v1/admin/events/{event}', 'GET api/v1/admin/markets/{market}', 'GET api/v1/admin/events/{event}/results',
        ],
        'Marché — suspendre / masquer' => ['PATCH api/v1/admin/markets/{market}'],
        'Résultats — saisir / valider' => [
            'POST api/v1/admin/events/{event}/result-corrections', 'GET api/v1/admin/result-corrections', 'GET api/v1/admin/result-corrections/{correction}',
            'POST api/v1/admin/result-corrections/{correction}/approve', 'POST api/v1/admin/result-corrections/{correction}/reject',
        ],
        'Règlement — lancer / relancer' => [], // aucune route : règlement automatique (job, commande settlement:run)
        'Utilisateurs — rechercher / consulter' => ['GET api/v1/admin/users', 'GET api/v1/admin/users/{user}'],
        'Utilisateur — suspendre' => ['PATCH api/v1/admin/users/{user}'],
        'Tickets — consulter' => ['GET api/v1/admin/tickets', 'GET api/v1/admin/tickets/{ticket}', 'GET api/v1/admin/risk/exposure'],
        'Ledger / rapprochement — consulter' => [
            'GET api/v1/admin/ledger', 'GET api/v1/admin/finance/reconciliation', 'GET api/v1/admin/finance/corrections',
            'GET api/v1/admin/finance/corrections/{correction}',
        ],
        'Correction financière' => [
            'POST api/v1/admin/finance/corrections', 'POST api/v1/admin/finance/corrections/{correction}/approve',
            'POST api/v1/admin/finance/corrections/{correction}/reject',
        ],
        'Paramètres' => ['GET api/v1/admin/settings', 'GET api/v1/admin/settings/versions', 'GET api/v1/admin/system', 'PUT api/v1/admin/settings'],
        'Limites jeu responsable' => ['GET api/v1/admin/users/{user}/responsible-gaming', 'POST api/v1/admin/users/{user}/responsible-gaming/reopen'],
        'Audit & exports' => ['GET api/v1/admin/audit-logs', 'POST api/v1/admin/audit-logs/export'],
        'Gérer les rôles des autres' => ['PUT api/v1/admin/users/{user}/roles', 'DELETE api/v1/admin/users/{user}/mfa'],
    ];

    /** @var array<string, User> */
    private array $admins = [];

    private User $player;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->app->instance(SportsDataProvider::class, new FakeSportsDataProvider);
        app(CatalogueSyncService::class)->sync('soccer_epl');
        $this->event = Event::query()->firstOrFail();

        foreach (self::ALL as $role) {
            $this->admins[$role] = User::factory()->withRole(RoleSlug::from($role))->create();
        }
        $this->player = User::factory()->create(['phone' => '37123456', 'email' => 'jean.dupont@example.com']);
    }

    /**
     * Appelle la route pour chaque rôle et vérifie l'accès attendu.
     *
     * @param  list<string>  $allowed
     */
    private function assertAccess(string $method, string $uri, array $allowed, array $query = []): void
    {
        foreach (self::ALL as $role) {
            $response = $this->requestAs($role, $method, $uri, $query);
            $forbidden = $response->status() === 403 && $response->json('error.code') === 'forbidden';

            if (in_array($role, $allowed, true)) {
                $this->assertFalse($forbidden, "{$role} devrait accéder à {$method} {$uri}");
                $this->assertNotContains($response->status(), [401, 428], "{$role} : {$method} {$uri} → {$response->status()}");
                $this->assertLessThan(500, $response->status(), "{$role} : erreur serveur sur {$method} {$uri}");
            } else {
                $this->assertTrue($forbidden, "{$role} ne devrait pas accéder à {$method} {$uri} (reçu {$response->status()})");
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function requestAs(string $role, string $method, string $uri, array $data = []): TestResponse
    {
        return $this->actingAsVerifiedAdmin($this->admins[$role])
            ->json($method, "/api/v1/admin/{$uri}", $data, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_the_config_grants_each_role_exactly_the_permissions_of_the_matrix(): void
    {
        foreach (self::EXPECTED_PERMISSIONS as $role => $permissions) {
            $granted = config("permissions.roles.{$role}");
            sort($granted);
            sort($permissions);
            $this->assertSame($permissions, $granted, "Permissions du rôle {$role}");
            // … et la base (PermissionSeeder) les applique telles quelles.
            $this->assertEqualsCanonicalizing($permissions, $this->admins[$role]->permissionSlugs()->all(), "Permissions en base du rôle {$role}");
        }
    }

    public function test_every_admin_route_is_covered_by_a_matrix_row(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $route) => str_starts_with($route->uri(), 'api/v1/admin'))
            ->flatMap(fn (RouteDefinition $route) => array_map(fn (string $m) => "{$m} {$route->uri()}", array_diff($route->methods(), ['HEAD'])))
            ->sort()->values()->all();
        $covered = collect(self::ROWS)->flatten()->sort()->values()->all();

        $this->assertSame($routes, $covered, 'Chaque route admin doit appartenir à une ligne de docs/permissions-matrix.md (et inversement).');
    }

    public function test_row_overview_is_readable_by_every_role(): void
    {
        $this->assertAccess('GET', 'dashboard', self::ALL);
        $this->assertAccess('GET', 'alerts', self::ALL);
        $this->assertAccess('GET', 'search', self::ALL, ['q' => 'Arsenal']);
    }

    public function test_row_events_and_markets_are_readable_by_every_role(): void
    {
        $market = $this->event->markets()->firstOrFail();

        $this->assertAccess('GET', 'events', self::ALL);
        $this->assertAccess('GET', "events/{$this->event->id}", self::ALL);
        $this->assertAccess('GET', "markets/{$market->id}", self::ALL);
        $this->assertAccess('GET', "events/{$this->event->id}/results", self::ALL);
    }

    public function test_row_market_suspension_is_reserved_to_super_admin_and_operations(): void
    {
        $market = $this->event->markets()->firstOrFail();

        $this->assertAccess('PATCH', "markets/{$market->id}", [self::SUPER_ADMIN, self::OPERATIONS]);
        $this->assertSame('open', $market->fresh()->status->value, 'Aucune requête sans motif ne doit modifier le marché.');
    }

    /** Double validation (demandeur ≠ validateur) : ResultCorrectionApiTest. */
    public function test_row_results_are_entered_and_approved_by_super_admin_and_operations_only(): void
    {
        $allowed = [self::SUPER_ADMIN, self::OPERATIONS];

        $this->assertAccess('POST', "events/{$this->event->id}/result-corrections", $allowed);
        $this->assertAccess('GET', 'result-corrections', $allowed);
        $this->assertAccess('GET', 'result-corrections/'.self::MISSING, $allowed);
        $this->assertAccess('POST', 'result-corrections/'.self::MISSING.'/approve', $allowed);
        $this->assertAccess('POST', 'result-corrections/'.self::MISSING.'/reject', $allowed);
    }

    /**
     * Ligne « — » de la matrice (D-07) : le règlement est automatique (SettleEventJob, commande settlement:run), sans
     * route d'administration en V1 ; settlement.run / settlement.approve restent réservées au Super Admin et à la Finance.
     * La ligne est vérifiée sur les permissions, et sur l'absence de route qui les exposerait à un autre rôle.
     */
    public function test_row_settlement_permissions_belong_to_super_admin_and_finance_and_no_route_exposes_them(): void
    {
        foreach (self::ALL as $role) {
            $expected = in_array($role, [self::SUPER_ADMIN, self::FINANCE], true);
            $this->assertSame($expected, Gate::forUser($this->admins[$role])->allows('settlement.run'), "settlement.run pour {$role}");
            $this->assertSame($expected, Gate::forUser($this->admins[$role])->allows('settlement.approve'), "settlement.approve pour {$role}");
        }

        $exposing = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:settlement.')));
        $this->assertCount(0, $exposing, 'Une route de règlement doit être ajoutée à la ligne « Règlement » de ce test.');
    }

    public function test_row_users_are_searchable_by_all_but_operations_and_support_sees_masked_contact_data(): void
    {
        $allowed = [self::SUPER_ADMIN, self::FINANCE, self::SUPPORT, self::COMPLIANCE];

        $this->assertAccess('GET', 'users', $allowed);
        $this->assertAccess('GET', "users/{$this->player->id}", $allowed);

        // 👁 Support (D-07, I2) : email et téléphone masqués, ni profil ni rôles — dans la fiche comme dans la liste.
        $this->requestAs(self::SUPPORT, 'GET', "users/{$this->player->id}")->assertOk()
            ->assertJsonPath('data.phone', '•••• 3456')->assertJsonPath('data.email', 'je•••@example.com')
            ->assertJsonMissingPath('data.profile')->assertJsonMissingPath('data.roles');
        $listed = collect($this->requestAs(self::SUPPORT, 'GET', 'users')->assertOk()->json('data'))->firstWhere('id', (string) $this->player->id);
        $this->assertSame(['•••• 3456', 'je•••@example.com'], [$listed['phone'], $listed['email']]);

        foreach ([self::SUPER_ADMIN, self::FINANCE, self::COMPLIANCE] as $role) {
            $this->requestAs($role, 'GET', "users/{$this->player->id}")->assertOk()
                ->assertJsonPath('data.phone', '37123456')->assertJsonPath('data.email', 'jean.dupont@example.com')
                ->assertJsonStructure(['data' => ['profile', 'roles']]);
        }
    }

    public function test_row_user_suspension_is_reserved_to_super_admin_support_and_compliance(): void
    {
        $this->assertAccess('PATCH', "users/{$this->player->id}", [self::SUPER_ADMIN, self::SUPPORT, self::COMPLIANCE]);
        $this->assertSame('active', $this->player->fresh()->status->value);
    }

    public function test_row_tickets_are_readable_by_every_role(): void
    {
        $this->assertAccess('GET', 'tickets', self::ALL);
        $this->assertAccess('GET', 'tickets/'.self::MISSING, self::ALL);
        $this->assertAccess('GET', 'risk/exposure', self::ALL);
    }

    public function test_row_ledger_and_reconciliation_are_readable_by_super_admin_finance_and_compliance(): void
    {
        $allowed = [self::SUPER_ADMIN, self::FINANCE, self::COMPLIANCE];

        $this->assertAccess('GET', 'ledger', $allowed);
        $this->assertAccess('GET', 'finance/reconciliation', $allowed);
        $this->assertAccess('GET', 'finance/corrections', $allowed);
        $this->assertAccess('GET', 'finance/corrections/'.self::MISSING, $allowed);
    }

    public function test_row_finance_correction_is_double_validated_by_super_admin_and_finance(): void
    {
        $allowed = [self::SUPER_ADMIN, self::FINANCE];

        $this->assertAccess('POST', 'finance/corrections', $allowed);
        $this->assertAccess('POST', 'finance/corrections/'.self::MISSING.'/approve', $allowed);
        $this->assertAccess('POST', 'finance/corrections/'.self::MISSING.'/reject', $allowed);

        // 2️⃣ : le demandeur ne valide jamais sa propre demande ; un second administrateur habilité le fait.
        $ledger = app(LedgerService::class);
        $credit = $ledger->creditSimulated($ledger->walletFor($this->player), Money::ofMinor(100_00, 'HTG'), 'test:matrix');
        $id = $this->requestAs(self::FINANCE, 'POST', 'finance/corrections', ['ledger_entry_id' => $credit->id, 'reason' => 'Crédit en double'])
            ->assertCreated()->json('data.id');

        $this->requestAs(self::FINANCE, 'POST', "finance/corrections/{$id}/approve", ['reason' => 'Je valide ma demande'])
            ->assertForbidden()->assertJsonPath('error.code', 'self_approval_forbidden');
        $this->requestAs(self::SUPER_ADMIN, 'POST', "finance/corrections/{$id}/approve", ['reason' => 'Vérifié sur le relevé'])->assertOk();

        $this->assertSame(FinanceCorrectionStatus::Approved, FinanceCorrection::findOrFail($id)->status);
        $this->assertSame(1, LedgerEntry::where('reversal_of_id', $credit->id)->count());
    }

    public function test_row_settings_are_versioned_by_super_admin_and_read_only_for_compliance(): void
    {
        $readers = [self::SUPER_ADMIN, self::COMPLIANCE];

        $this->assertAccess('GET', 'settings', $readers);
        $this->assertAccess('GET', 'settings/versions', $readers);
        $this->assertAccess('GET', 'system', $readers);
        $this->assertAccess('PUT', 'settings', [self::SUPER_ADMIN]);
    }

    public function test_row_responsible_gaming_is_managed_by_compliance_and_read_only_for_super_admin(): void
    {
        $this->assertAccess('GET', "users/{$this->player->id}/responsible-gaming", [self::SUPER_ADMIN, self::COMPLIANCE]);
        $this->assertAccess('POST', "users/{$this->player->id}/responsible-gaming/reopen", [self::COMPLIANCE]);
    }

    public function test_row_audit_and_exports_are_reserved_to_super_admin_finance_and_compliance(): void
    {
        $allowed = [self::SUPER_ADMIN, self::FINANCE, self::COMPLIANCE];

        $this->assertAccess('GET', 'audit-logs', $allowed);
        $this->assertAccess('POST', 'audit-logs/export', $allowed);
    }

    public function test_row_roles_of_others_are_managed_by_super_admin_only_never_their_own(): void
    {
        $this->assertAccess('PUT', "users/{$this->player->id}/roles", [self::SUPER_ADMIN]);
        $this->assertAccess('DELETE', "users/{$this->player->id}/mfa", [self::SUPER_ADMIN]);

        // « jamais les siens » : même le Super Admin ne modifie pas ses propres rôles.
        $superAdmin = $this->admins[self::SUPER_ADMIN];
        $this->requestAs(self::SUPER_ADMIN, 'PUT', "users/{$superAdmin->id}/roles", ['roles' => [], 'reason' => 'Changement de poste'])
            ->assertForbidden()->assertJsonPath('error.code', 'cannot_modify_own_roles');
        $this->assertTrue($superAdmin->fresh()->hasRole(RoleSlug::SuperAdmin));
    }
}
