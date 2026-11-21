<?php

namespace Tests\Feature\Modules\IdentityAccess;

use App\Models\User;
use App\Modules\IdentityAccess\Services\TurnstileVerifier;
use App\Modules\Monitoring\Services\MonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vérification « humain » par Cloudflare Turnstile (D-17, V2-F04) : inscription et mot de passe oublié toujours, connexion
 * après 3 échecs. Cloudflare est simulé (Http::fake) : aucun appel réel.
 */
class HumanVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:5173');
        config(['identity.human_verification.turnstile_secret_key' => 'secret-de-test']);
    }

    /** Réponse de Cloudflare : jeton « bon » accepté, tout autre refusé. */
    private function fakeCloudflare(string $hostname = 'reyel16paryaj.com'): void
    {
        Http::fake([TurnstileVerifier::ENDPOINT => function (HttpRequest $request) use ($hostname) {
            return $request['response'] === 'bon'
                ? Http::response(['success' => true, 'hostname' => $hostname])
                : Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]);
        }]);
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Joueur Test',
            'phone' => '34123456',
            'password' => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
            'birth_date' => now()->subYears(25)->toDateString(),
            'country' => 'ht',
            'terms_accepted' => true,
            'terms_version' => '2026-11-02',
        ], $overrides);
    }

    private function login(string $password, ?string $token = null, string $phone = '34123456')
    {
        return $this->postJson('/api/v1/auth/login', array_filter(['phone' => $phone, 'password' => $password, 'captcha_token' => $token]));
    }

    public function test_disabled_without_a_secret_key_nothing_changes(): void
    {
        config(['identity.human_verification.turnstile_secret_key' => '']);
        Http::fake();

        $this->postJson('/api/v1/auth/register', $this->registration())->assertCreated();
        Http::assertNothingSent();
    }

    public function test_registration_requires_a_valid_token_sent_to_cloudflare_with_the_secret_and_ip(): void
    {
        $this->fakeCloudflare();

        $this->postJson('/api/v1/auth/register', $this->registration())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'captcha_failed')
            ->assertJsonPath('error.details.reason', 'required');

        $this->postJson('/api/v1/auth/register', $this->registration(['captcha_token' => 'faux']))
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'invalid');

        $this->postJson('/api/v1/auth/register', $this->registration(['captcha_token' => 'bon']))->assertCreated();

        Http::assertSent(fn (HttpRequest $request) => $request->url() === TurnstileVerifier::ENDPOINT
            && $request['secret'] === 'secret-de-test' && $request['response'] === 'bon'
            && $request['remoteip'] === '127.0.0.1' && is_string($request['idempotency_key'])
            && ! isset($request['phone']) && ! isset($request['password']));
    }

    public function test_the_token_is_checked_before_validation_so_a_taken_number_is_never_revealed(): void
    {
        $this->fakeCloudflare();
        User::factory()->create(['phone' => '34123456']);

        // Numéro déjà inscrit, sans jeton : seule l'erreur anti-robot, jamais « numéro déjà utilisé ».
        $this->postJson('/api/v1/auth/register', $this->registration())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'captcha_failed')
            ->assertJsonMissingPath('error.details.phone');

        // Avec un jeton valide, la validation normale reprend.
        $this->postJson('/api/v1/auth/register', $this->registration(['captcha_token' => 'bon']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_registration_and_forgotten_password_are_refused_when_cloudflare_is_unreachable_and_an_alert_is_raised(): void
    {
        Http::fake([TurnstileVerifier::ENDPOINT => fn () => throw new ConnectionException('timeout')]);

        $this->postJson('/api/v1/auth/register', $this->registration(['captcha_token' => 'bon']))
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'unavailable');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'a@example.com', 'captcha_token' => 'bon'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'unavailable');

        $alert = collect(app(MonitoringService::class)->alerts())->firstWhere('key', 'turnstile_unavailable');
        $this->assertNotNull($alert, 'Alerte d\'exploitation attendue (PS-7).');
        $this->assertSame('warning', $alert['severity']);

        // Plus de 15 minutes après le dernier échec : l'alerte disparaît.
        $this->travel(16)->minutes();
        $this->assertNull(collect(app(MonitoringService::class)->alerts())->firstWhere('key', 'turnstile_unavailable'));
    }

    public function test_a_wrong_secret_key_is_treated_as_unavailable_not_as_a_robot(): void
    {
        Http::fake([TurnstileVerifier::ENDPOINT => Http::response(['success' => false, 'error-codes' => ['invalid-input-secret']])]);

        $this->postJson('/api/v1/auth/register', $this->registration(['captcha_token' => 'bon']))
            ->assertJsonPath('error.details.reason', 'unavailable');
    }

    public function test_a_token_issued_for_another_site_is_refused(): void
    {
        config(['identity.human_verification.allowed_hostnames' => ['reyel16paryaj.com']]);
        $this->fakeCloudflare(hostname: 'site-pirate.example');

        $this->postJson('/api/v1/auth/register', $this->registration(['captcha_token' => 'bon']))
            ->assertJsonPath('error.details.reason', 'invalid');
    }

    public function test_forgotten_password_requires_a_token_and_stays_identical_whether_the_account_exists_or_not(): void
    {
        $this->fakeCloudflare();
        User::factory()->create(['email' => 'connu@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'connu@example.com'])->assertJsonPath('error.details.reason', 'required');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'connu@example.com', 'captcha_token' => 'bon'])->assertStatus(202);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'inconnu@example.com', 'captcha_token' => 'bon'])->assertStatus(202);
    }

    public function test_login_asks_for_the_token_only_after_three_failures_and_a_success_resets_the_counter(): void
    {
        $this->fakeCloudflare();
        User::factory()->create(['phone' => '34123456', 'password' => 'motdepasse123']);

        for ($i = 0; $i < 3; $i++) {
            $this->login('mauvais')->assertJsonPath('error.code', 'invalid_credentials');
        }

        // 4e essai : le widget devient obligatoire, même avec le bon mot de passe.
        $this->login('motdepasse123')->assertJsonPath('error.code', 'captcha_failed')->assertJsonPath('error.details.reason', 'required');
        $this->login('motdepasse123', 'bon')->assertOk();

        // Connexion réussie : le compteur est remis à zéro, plus de widget (une minute plus tard : la limite propre de
        // la connexion, 5 essais par minute, n'entre pas en jeu).
        $this->postJson('/api/v1/auth/logout');
        $this->travel(61)->seconds();
        $this->login('motdepasse123')->assertOk();
    }

    public function test_an_unknown_number_triggers_the_token_exactly_like_a_known_one(): void
    {
        $this->fakeCloudflare();
        User::factory()->create(['phone' => '34123456', 'password' => 'motdepasse123']);

        foreach (['34123456', '37999999'] as $phone) {
            $this->withServerVariables(['REMOTE_ADDR' => $phone === '34123456' ? '10.0.0.1' : '10.0.0.2']);
            for ($i = 0; $i < 3; $i++) {
                $this->login('mauvais', phone: $phone)->assertJsonPath('error.code', 'invalid_credentials');
            }
            $this->login('mauvais', phone: $phone)->assertJsonPath('error.code', 'captcha_failed');
        }
    }

    public function test_failures_are_counted_per_number_whatever_its_spelling(): void
    {
        $this->fakeCloudflare();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])->login('mauvais', phone: '34123456');
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])->login('mauvais', phone: '3412-3456');
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->login('mauvais', phone: '+509 3412 3456');

        // Nouvelle IP, même numéro sous une autre écriture : le compteur du numéro suffit.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.6'])->login('mauvais', phone: '34123456')->assertJsonPath('error.code', 'captcha_failed');
    }

    public function test_login_goes_through_when_cloudflare_is_unreachable(): void
    {
        User::factory()->create(['phone' => '34123456', 'password' => 'motdepasse123']);
        for ($i = 0; $i < 3; $i++) {
            $this->login('mauvais');
        }

        Http::fake([TurnstileVerifier::ENDPOINT => fn () => throw new ConnectionException('timeout')]);

        // Décision D2 du 2026-11-19 : la connexion passe pendant une panne (ses limites de fréquence restent appliquées).
        $this->login('motdepasse123', 'bon')->assertOk();
        $this->assertNotNull(app(TurnstileVerifier::class)->lastUnavailableAt());
    }
}
