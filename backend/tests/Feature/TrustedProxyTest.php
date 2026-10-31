<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Revue P8-02, B2 : proxies de confiance (TRUSTED_PROXIES) et cookie de session Secure en production. */
class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/v1/test-client', fn (Request $request) => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
        ]);
    }

    /** Requête arrivant de $remote, avec les en-têtes ajoutés par un reverse proxy en HTTPS. */
    private function viaProxy(string $remote): array
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remote])
            ->getJson('/api/v1/test-client', ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->json();
    }

    public function test_a_declared_proxy_gives_the_real_client_ip_and_https(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.2,172.18.0.0/16']);

        $this->assertSame(['ip' => '203.0.113.7', 'secure' => true], $this->viaProxy('10.0.0.2'));
        $this->assertSame(['ip' => '203.0.113.7', 'secure' => true], $this->viaProxy('172.18.5.9'));
    }

    public function test_forwarded_headers_from_an_unknown_address_are_ignored(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.2']);

        // Un client qui falsifie X-Forwarded-For garde sa propre IP (limiteurs, audit).
        $this->assertSame(['ip' => '198.51.100.9', 'secure' => false], $this->viaProxy('198.51.100.9'));
    }

    public function test_no_proxy_is_trusted_by_default(): void
    {
        $this->assertNull(config('trustedproxy.proxies'));
        $this->assertSame(['ip' => '10.0.0.2', 'secure' => false], $this->viaProxy('10.0.0.2'));
    }

    public function test_hsts_is_sent_behind_a_declared_https_proxy(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.2']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->getJson('/api/v1/health', ['X-Forwarded-Proto' => 'https'])
            ->assertHeader('Strict-Transport-Security');
    }

    public function test_trusted_proxies_are_read_from_the_environment(): void
    {
        $this->assertSame('10.0.0.2,172.18.0.0/16', $this->configWith('TRUSTED_PROXIES', '10.0.0.2,172.18.0.0/16', 'trustedproxy.php')['proxies']);
        $this->assertNull($this->configWith('TRUSTED_PROXIES', '', 'trustedproxy.php')['proxies']);
    }

    public function test_session_cookie_is_secure_by_default_in_production_only(): void
    {
        $this->assertTrue($this->configWith('APP_ENV', 'production', 'session.php')['secure']);
        $this->assertNull($this->configWith('APP_ENV', 'local', 'session.php')['secure']);
        // Sans APP_ENV, l'application se considère en production (config/app.php) : cookie Secure aussi.
        $this->assertTrue($this->configWith('APP_ENV', null, 'session.php')['secure']);
    }

    /**
     * Évalue un fichier de configuration avec une variable d'environnement donnée (null : variable absente).
     *
     * @return array<string, mixed>
     */
    private function configWith(string $name, ?string $value, string $file): array
    {
        $saved = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        $this->setEnv($name, $value, $value, $value === null ? false : $value);

        try {
            return require config_path($file);
        } finally {
            $this->setEnv($name, ...$saved);
        }
    }

    private function setEnv(string $name, ?string $env, ?string $server, string|false $putenv): void
    {
        if ($env === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $env;
        }

        if ($server === null) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $server;
        }

        putenv($putenv === false ? $name : "{$name}={$putenv}");
    }
}
