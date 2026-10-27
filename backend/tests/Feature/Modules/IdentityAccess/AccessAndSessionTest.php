<?php

namespace Tests\Feature\Modules\IdentityAccess;

use App\Models\User;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\IdentityAccess\Services\Totp;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tests d'accès et de session (P2-07) : expiration, révocation, rôles, accès direct.
 *
 * Contrairement aux autres tests, chaque requête repart de zéro comme un vrai navigateur :
 * aucun utilisateur ni session gardés en mémoire par Laravel, seul le cookie de session est renvoyé.
 * Un cookie volé ou rejoué est ainsi réellement mis à l'épreuve.
 */
class AccessAndSessionTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND = 'http://localhost:5173';

    /** Routes de l'API accessibles sans session. Toute nouvelle route publique doit être ajoutée ici sciemment. */
    private const PUBLIC_ROUTES = [
        'GET api/v1/health',
        'POST api/v1/auth/register',
        'POST api/v1/auth/login',
        'POST api/v1/auth/forgot-password',
        'POST api/v1/auth/reset-password',
        'GET api/v1/sports',
        'GET api/v1/competitions',
        'GET api/v1/events',
        'GET api/v1/events/{id}/markets',
    ];

    /** Cookie de session (valeur chiffrée telle que reçue par le navigateur). */
    private ?string $sessionCookie = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Sessions en base, comme en production : l'expiration et la destruction sont celles du vrai stockage.
        config(['session.driver' => 'database']);
        $this->seed(PermissionSeeder::class);
    }

    // ---------------------------------------------------------------- Expiration

    public function test_session_expires_after_the_configured_inactivity(): void
    {
        $this->login($this->player());

        $this->travel((int) config('session.lifetime') + 1)->minutes();

        $this->browser('GET', '/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_activity_keeps_the_session_alive(): void
    {
        $this->login($this->player());
        $almostExpired = (int) config('session.lifetime') - 5;

        $this->travel($almostExpired)->minutes();
        $this->browser('GET', '/api/v1/me')->assertOk();

        $this->travel($almostExpired)->minutes();
        $this->browser('GET', '/api/v1/me')->assertOk();
    }

    // ---------------------------------------------------------------- Révocation

    public function test_logout_revokes_the_session_cookie_for_good(): void
    {
        $this->login($this->player());
        $stolen = $this->sessionCookie;

        $this->browser('POST', '/api/v1/auth/logout')->assertNoContent();

        $this->sessionCookie = $stolen; // rejeu du cookie d'avant la déconnexion
        $this->browser('GET', '/api/v1/me')->assertUnauthorized();
    }

    public function test_login_issues_a_new_session_id_against_fixation(): void
    {
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $guestCookie = $this->sessionCookie;
        $this->assertNotNull($guestCookie);

        $this->login($this->player());
        $this->assertNotSame($guestCookie, $this->sessionCookie);

        // L'identifiant connu avant la connexion (fixé par un attaquant) ne donne aucun accès.
        $this->sessionCookie = $guestCookie;
        $this->browser('GET', '/api/v1/me')->assertUnauthorized();
    }

    public function test_mfa_verification_issues_a_new_session_id(): void
    {
        $admin = $this->admin(RoleSlug::Compliance);
        $this->login($admin);
        $beforeMfa = $this->sessionCookie;

        $this->verifyMfa($admin);
        $this->assertNotSame($beforeMfa, $this->sessionCookie);
        $this->browser('GET', '/api/v1/admin/audit-logs')->assertOk();

        $this->sessionCookie = $beforeMfa;
        $this->browser('GET', '/api/v1/admin/audit-logs')->assertUnauthorized();
    }

    public function test_removing_a_role_takes_effect_on_the_open_session(): void
    {
        $admin = $this->admin(RoleSlug::Compliance);
        $this->login($admin);
        $this->verifyMfa($admin);
        $this->browser('GET', '/api/v1/admin/audit-logs')->assertOk();

        $admin->roles()->detach();

        $this->browser('GET', '/api/v1/admin/audit-logs')->assertForbidden();
    }

    public function test_suspension_takes_effect_on_the_open_session_and_destroys_it(): void
    {
        $player = $this->player();
        $this->login($player);
        $cookie = $this->sessionCookie;

        $player->forceFill(['status' => 'suspended'])->save();
        $this->browser('GET', '/api/v1/me')->assertStatus(423)->assertJsonPath('error.code', 'account_suspended');

        // Même réactivé, le compte doit se reconnecter : la session a été détruite.
        $player->forceFill(['status' => 'active'])->save();
        $this->sessionCookie = $cookie;
        $this->browser('GET', '/api/v1/me')->assertUnauthorized();
    }

    public function test_deleted_account_loses_its_session(): void
    {
        $player = $this->player();
        $this->login($player);

        $player->delete();

        $this->browser('GET', '/api/v1/me')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- Rôles et accès direct

    public function test_only_the_known_routes_are_public(): void
    {
        $public = collect($this->apiRoutes())
            ->reject(fn (RouteDefinition $route) => $this->hasMiddleware($route, 'auth:sanctum'))
            ->map(fn (RouteDefinition $route, string $key) => $key)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(collect(self::PUBLIC_ROUTES)->sort()->values()->all(), $public);
    }

    public function test_every_protected_route_rejects_a_guest(): void
    {
        foreach ($this->protectedRoutes() as $key => $route) {
            $this->sessionCookie = null;
            $response = $this->browser(strtok($key, ' '), $this->concreteUri($route));

            $this->assertSame(401, $response->status(), "Accessible sans session : {$key}");
            $this->assertSame('unauthenticated', $response->json('error.code'), $key);
        }
    }

    public function test_every_authenticated_route_checks_the_account_status(): void
    {
        foreach ($this->protectedRoutes() as $key => $route) {
            if ($key === 'POST api/v1/auth/logout') {
                continue; // un compte suspendu doit pouvoir fermer sa session
            }

            $this->assertTrue($this->hasMiddleware($route, 'active'), "Statut du compte non vérifié : {$key}");
        }
    }

    public function test_every_admin_route_requires_mfa_and_a_permission(): void
    {
        $adminRoutes = collect($this->apiRoutes())->filter(fn (RouteDefinition $route) => str_starts_with($route->uri(), 'api/v1/admin/'));
        $this->assertNotEmpty($adminRoutes);

        foreach ($adminRoutes as $key => $route) {
            $this->assertTrue($this->hasMiddleware($route, 'mfa'), "MFA non exigé : {$key}");
            $this->assertTrue(
                collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:')),
                "Aucune permission exigée : {$key}",
            );
        }
    }

    public function test_a_player_reaching_admin_urls_directly_gets_403_everywhere(): void
    {
        $this->login($this->player());

        foreach ($this->apiRoutes() as $key => $route) {
            if (! str_starts_with($route->uri(), 'api/v1/admin/')) {
                continue;
            }

            $response = $this->browser(strtok($key, ' '), $this->concreteUri($route));
            $this->assertSame(403, $response->status(), "Réponse inattendue pour un joueur : {$key}");
        }
    }

    public function test_a_valid_session_is_ignored_from_a_foreign_origin(): void
    {
        $this->login($this->player());

        // Un site tiers qui ferait envoyer le cookie par le navigateur n'obtient rien (mode SPA de Sanctum).
        $this->browser('GET', '/api/v1/me', origin: 'https://site-malveillant.example')->assertUnauthorized();
        $this->browser('GET', '/api/v1/me')->assertOk();
    }

    public function test_a_forged_bearer_token_is_rejected(): void
    {
        $this->player();

        $this->browser('GET', '/api/v1/me', headers: ['Authorization' => 'Bearer 1|faux-jeton-forge'])->assertUnauthorized();
    }

    // ---------------------------------------------------------------- Outils

    /**
     * Envoie une requête comme le ferait le navigateur : rien en mémoire côté Laravel,
     * seul le cookie de session reçu précédemment est renvoyé (et remplacé s'il change).
     */
    private function browser(string $method, string $uri, array $data = [], array $headers = [], string $origin = self::FRONTEND): TestResponse
    {
        // En production chaque requête est un nouveau processus : rien ne doit survivre en mémoire.
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->flushHeaders();
        $this->unencryptedCookies = [];

        if ($this->sessionCookie !== null) {
            $this->withUnencryptedCookie(config('session.cookie'), $this->sessionCookie);
        }

        // withCredentials : sans lui, les requêtes JSON de test n'envoient aucun cookie.
        $response = $this->withCredentials()->withHeaders(['Origin' => $origin] + $headers)->json($method, $uri, $data);

        $cookie = $response->getCookie(config('session.cookie'), decrypt: false);
        if ($cookie !== null) {
            $this->sessionCookie = $cookie->getValue();
        }

        return $response;
    }

    private function login(User $user): void
    {
        $this->browser('POST', '/api/v1/auth/login', ['phone' => $user->phone, 'password' => 'motdepasse123'])->assertOk();
    }

    private function verifyMfa(User $admin): void
    {
        $code = Totp::codeAt($admin->fresh()->mfa_secret, intdiv(now()->getTimestamp(), Totp::PERIOD));

        $this->browser('POST', '/api/v1/auth/mfa/verify', ['code' => $code])->assertNoContent();
    }

    private function player(): User
    {
        return User::factory()->create(['password' => 'motdepasse123']);
    }

    private function admin(RoleSlug $role): User
    {
        $admin = User::factory()->withRole($role)->create(['password' => 'motdepasse123']);
        $admin->forceFill(['mfa_secret' => Totp::generateSecret(), 'mfa_confirmed_at' => now()])->save();

        return $admin;
    }

    /** @return array<string, RouteDefinition> routes de l'API, indexées par « MÉTHODE uri » */
    private function apiRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes["{$method} {$route->uri()}"] = $route;
            }
        }

        return $routes;
    }

    /** @return array<string, RouteDefinition> */
    private function protectedRoutes(): array
    {
        return array_filter($this->apiRoutes(), fn (RouteDefinition $route) => $this->hasMiddleware($route, 'auth:sanctum'));
    }

    private function hasMiddleware(RouteDefinition $route, string $middleware): bool
    {
        return in_array($middleware, $route->gatherMiddleware(), true);
    }

    /** Remplace les paramètres d'URL ({user}, {id}…) par un identifiant quelconque. */
    private function concreteUri(RouteDefinition $route): string
    {
        return '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
    }
}
