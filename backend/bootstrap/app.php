<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\IdentityAccess\Http\Middleware\EnsureAccountIsActive;
use App\Modules\IdentityAccess\Http\Middleware\EnsureMfaVerified;
use App\Modules\IdentityAccess\Http\Middleware\RequirePermission;
use App\Support\Http\ApiErrorRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Commandes artisan des modules (ex. sports-data:check, settlement:import-results).
    ->withCommands([__DIR__.'/../app/Modules/SportsData/Console', __DIR__.'/../app/Modules/Settlement/Console'])
    ->withMiddleware(function (Middleware $middleware): void {
        // Identifiant de corrélation X-Request-Id (P1-04) : en tête, pour figurer dans tous les logs de la requête.
        $middleware->prepend(AssignRequestId::class);

        // En-têtes de sécurité (nosniff, DENY, CSP, Referrer-Policy, HSTS en HTTPS) sur toutes les réponses (P8-02, F13).
        $middleware->append(SecurityHeaders::class);

        // Sanctum en mode SPA : session par cookie + CSRF pour le frontend first-party (SANCTUM_STATEFUL_DOMAINS).
        $middleware->statefulApi();

        // L'API ne redirige jamais : un invité reçoit 401 "unauthenticated".
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');

        $middleware->alias([
            'idempotent' => RequireIdempotencyKey::class,
            'permission' => RequirePermission::class,
            'active' => EnsureAccountIsActive::class,
            'mfa' => EnsureMfaVerified::class,
        ]);

        // Permissions vérifiées AVANT la résolution des {modèles} de l'URL : sans permission, 403 que
        // l'identifiant existe ou non (pas de sondage des identifiants).
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureAccountIsActive::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureMfaVerified::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, RequirePermission::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Format d'erreur unique de l'API (docs/api/README.md).
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return (new ApiErrorRenderer)($e);
            }
        });
    })->create();
