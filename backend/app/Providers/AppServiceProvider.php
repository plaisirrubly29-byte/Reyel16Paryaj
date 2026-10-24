<?php

namespace App\Providers;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Providers\TheOddsApiProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
