<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Betting\Services\TicketStakeHistory;
use App\Modules\Compliance\Contracts\StakeHistory;
use App\Modules\IdentityAccess\Support\PhoneNumber;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Providers\TheOddsApiProvider;
use App\Modules\SportsData\Services\CreditBudget;
use App\Support\ProductionSafety;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Fournisseur de données sportives choisi par SPORTS_DATA_PROVIDER (config/sports_data.php).
        $this->app->singleton(SportsDataProvider::class, fn ($app) => match (config('sports_data.provider')) {
            'fake' => new FakeSportsDataProvider,
            'theodds' => new TheOddsApiProvider(
                $app->make(HttpFactory::class),
                $app->make('cache.store'),
                config('sports_data.theodds'),
                (int) config('sports_data.min_remaining_requests'),
            ),
            default => throw new InvalidArgumentException('SPORTS_DATA_PROVIDER inconnu : '.config('sports_data.provider')),
        });

        // Mises d'un joueur pour les limites de jeu responsable : fournies par Betting à Compliance (P7-02).
        $this->app->bind(StakeHistory::class, TicketStakeHistory::class);

        // Budget de crédits du fournisseur (sports-data:sync, sports-data:credits).
        $this->app->bind(CreditBudget::class, fn ($app) => new CreditBudget(
            $app->make(SportsDataProvider::class),
            $app->make('cache.store'),
            config('sports_data'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Mode debug, proxy « * », CORS ou Sanctum « * » : refus de démarrer en production (revue P8-02).
        // Sauf pour les commandes qui permettent de corriger une configuration mise en cache.
        if ($this->app->isProduction() && ! $this->app->runningConsoleCommand(...ProductionSafety::REPAIR_COMMANDS)) {
            ProductionSafety::assertSafe();
        }

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
            // Le limiteur passe avant LoginRequest : il normalise lui-même le numéro (ou l'email), sinon "3412-3456"
            // et "+509 34123456" auraient chacun leur compteur (revue P8-02, M1).
            $phone = is_string($request->input('phone')) ? PhoneNumber::normalize($request->input('phone')) : '';
            $email = $phone === '' && is_string($request->input('email')) ? Str::lower(trim($request->input('email'))) : '';
            $login = $phone !== '' ? $phone : ($email !== '' ? 'email:'.$email : '');

            $limits = [
                Limit::perMinute(config('identity.login.max_attempts_per_phone'))->by('login:'.$login.'|'.$request->ip()),
                Limit::perMinute(config('identity.login.max_attempts_per_ip'))->by('login-ip:'.$request->ip()),
            ];

            // Par compte, quelle que soit l'IP : borne une attaque répartie sur de nombreuses adresses.
            // Compteur commun au téléphone et à l'email d'un même compte (sinon deux plafonds pour un compte).
            if ($login !== '') {
                $accountId = User::where($phone !== '' ? 'phone' : 'email', $phone !== '' ? $phone : $email)->value('id');
                $limits[] = Limit::perHour(config('identity.login.max_attempts_per_account'))
                    ->by('login-account:'.($accountId !== null ? 'id:'.$accountId : $login));
            }

            return $limits;
        });

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by('register:'.$request->ip()));

        // MFA, par compte : sans plafond long, 5 essais/min laissent 7 200 essais par jour (revue P8-02, M2).
        RateLimiter::for('mfa', function (Request $request) {
            $id = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return [
                Limit::perMinute(config('identity.mfa.max_attempts_per_minute'))->by('mfa:'.$id),
                Limit::perHour(config('identity.mfa.max_attempts_per_hour'))->by('mfa-hour:'.$id),
                Limit::perDay(config('identity.mfa.max_attempts_per_day'))->by('mfa-day:'.$id),
            ];
        });

        // Le limiteur passe avant la validation : un email envoyé en tableau ne doit pas provoquer de 500 (revue P8-02, F4).
        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(3)->by('password-reset:'.(is_string($request->input('email')) ? Str::lower($request->input('email')) : '')),
            Limit::perMinute(10)->by('password-reset-ip:'.$request->ip()),
        ]);

        // Catalogue public : 120 requêtes/min par IP (lecture seule, sans authentification).
        RateLimiter::for('catalogue', fn (Request $request) => Limit::perMinute(120)->by('catalogue:'.$request->ip()));
    }
}
