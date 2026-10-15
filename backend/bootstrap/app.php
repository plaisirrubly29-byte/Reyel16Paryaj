<?php

use App\Http\Middleware\RequireIdempotencyKey;
use App\Support\Http\ApiErrorRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum en mode SPA : session par cookie + CSRF pour le frontend first-party (SANCTUM_STATEFUL_DOMAINS).
        $middleware->statefulApi();

        // L'API ne redirige jamais : un invité reçoit 401 "unauthenticated".
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');

        $middleware->alias([
            'idempotent' => RequireIdempotencyKey::class,
        ]);
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
