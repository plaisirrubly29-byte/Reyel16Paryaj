<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Support\ProductionSafety;
use RuntimeException;
use Tests\TestCase;

/** Revue P8-02 : refus de démarrer en production avec une configuration dangereuse. */
class ProductionSafetyTest extends TestCase
{
    /** Configuration de production sûre, à dégrader une valeur à la fois. */
    private function safeProductionConfig(): void
    {
        config([
            'app.debug' => false,
            'trustedproxy.proxies' => '10.0.0.2',
            'cors.allowed_origins' => ['https://reyel16paryaj.ht'],
            'sanctum.stateful' => ['reyel16paryaj.ht'],
        ]);
    }

    public function test_a_safe_configuration_has_no_problem(): void
    {
        $this->safeProductionConfig();

        $this->assertSame([], ProductionSafety::problems());
    }

    public function test_each_dangerous_setting_is_reported(): void
    {
        $cases = [
            ['APP_DEBUG', ['app.debug' => true]],
            ['TRUSTED_PROXIES', ['trustedproxy.proxies' => '*']],
            // Autres façons de faire confiance à n'importe quel client, seules ou dans une liste.
            ['TRUSTED_PROXIES', ['trustedproxy.proxies' => 'REMOTE_ADDR']],
            ['TRUSTED_PROXIES', ['trustedproxy.proxies' => '10.0.0.2, 0.0.0.0/0']],
            ['TRUSTED_PROXIES', ['trustedproxy.proxies' => '::/0']],
            ['CORS_ALLOWED_ORIGINS', ['cors.allowed_origins' => ['*']]],
            ['SANCTUM_STATEFUL_DOMAINS', ['sanctum.stateful' => ['*']]],
            // Sanctum retire les espaces avant de comparer : « site.ht, * » ouvre les sessions à tous les domaines.
            ['SANCTUM_STATEFUL_DOMAINS', ['sanctum.stateful' => ['reyel16paryaj.ht', ' *']]],
        ];

        foreach ($cases as [$variable, $setting]) {
            $this->safeProductionConfig();
            config($setting);

            $problems = ProductionSafety::problems();
            $this->assertCount(1, $problems, json_encode($setting));
            $this->assertStringStartsWith($variable, $problems[0]);
        }
    }

    public function test_repair_commands_still_run_with_a_dangerous_configuration(): void
    {
        $this->safeProductionConfig();
        config(['app.debug' => true]);
        $this->app['env'] = 'production';
        $argv = $_SERVER['argv'];

        try {
            // Configuration erronée mise en cache : config:clear doit rester possible pour la corriger.
            $_SERVER['argv'] = ['artisan', 'config:clear'];
            (new AppServiceProvider($this->app))->boot();

            $_SERVER['argv'] = ['artisan', 'migrate'];
            $this->expectException(RuntimeException::class);
            (new AppServiceProvider($this->app))->boot();
        } finally {
            $_SERVER['argv'] = $argv;
        }
    }

    public function test_production_refuses_to_boot_with_debug_enabled(): void
    {
        $this->safeProductionConfig();
        config(['app.debug' => true]);
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_DEBUG doit valoir false');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_other_environments_are_not_checked(): void
    {
        config(['app.debug' => true]); // valeur de développement normale

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('testing', $this->app->environment());
    }
}
