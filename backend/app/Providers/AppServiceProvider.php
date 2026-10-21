<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePasswords();
        $this->configureRateLimiting();
        $this->configureAuthorization();
    }

    /**
     * Les permissions RBAC sont utilisables comme abilities : Gate::allows('users.suspend'),
     * $this->authorize('ledger.view'), @can… Une permission absente est un refus explicite.
     */
    private function configureAuthorization(): void
    {
        Gate::before(function (User $user, string $ability) {
            if (array_key_exists($ability, config('permissions.permissions'))) {
                return $user->hasPermission($ability);
            }

            return null;
        });
    }

    private function configurePasswords(): void
    {
        Password::defaults(function () {
            $rule = Password::min(10)->letters()->numbers();

            // Vérification "Have I Been Pwned" (appel réseau) hors développement et tests.
            return $this->app->environment('production', 'staging') ? $rule->uncompromised() : $rule;
        });
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $phone = (string) $request->input('phone');

            return [
                Limit::perMinute(config('identity.login.max_attempts_per_phone'))->by('login:'.$phone.'|'.$request->ip()),
                Limit::perMinute(config('identity.login.max_attempts_per_ip'))->by('login-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by('register:'.$request->ip()));

        // MFA : 5 essais par minute et par compte (10^6 combinaisons → force brute impraticable).
        RateLimiter::for('mfa', fn (Request $request) => Limit::perMinute(5)->by('mfa:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(3)->by('password-reset:'.Str::lower((string) $request->input('email'))),
            Limit::perMinute(10)->by('password-reset-ip:'.$request->ip()),
        ]);
    }
}
