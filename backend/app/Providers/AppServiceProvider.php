<?php

namespace App\Providers;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Providers\TheOddsApiProvider;
use App\Modules\SportsData\Services\CreditBudget;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
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
        // Catalogue public : 120 requêtes/min par IP (lecture seule, sans authentification).
        RateLimiter::for('catalogue', fn (Request $request) => Limit::perMinute(120)->by('catalogue:'.$request->ip()));
    }
}
