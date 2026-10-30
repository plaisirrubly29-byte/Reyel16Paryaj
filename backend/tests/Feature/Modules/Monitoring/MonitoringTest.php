<?php

namespace Tests\Feature\Modules\Monitoring;

use App\Models\User;
use App\Modules\Monitoring\Mail\MonitoringAlertMail;
use App\Modules\Monitoring\Services\AlertDispatcher;
use App\Modules\Monitoring\Services\ApiErrorRate;
use App\Modules\Monitoring\Services\QueueHeartbeat;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/** Monitoring et alertes de production (P9-02) : alertes, envoi Telegram et e-mail, sans répétition. */
class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'monitoring.label' => 'Test',
            'monitoring.telegram.bot_token' => 'jeton-secret',
            'monitoring.telegram.chat_id' => '-100123',
            'monitoring.mail_to' => ['astreinte@example.com', 'renfort@example.com'],
            'monitoring.heartbeat_url' => null,
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Mail::fake();

        // Erreur serveur simulée, dans le groupe « api » (compteurs de réponses).
        Route::middleware('api')->get('/api/v1/__test-panne', fn () => throw new RuntimeException('panne simulée'));
    }

    private function check(): void
    {
        $this->artisan('monitoring:check')->assertSuccessful();
    }

    /** @return list<string> textes envoyés à Telegram */
    private function telegramTexts(): array
    {
        return Http::recorded()->map(fn (array $pair) => (string) ($pair[0]->data()['text'] ?? ''))->filter()->values()->all();
    }

    private function serverErrors(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->getJson('/api/v1/__test-panne')->assertStatus(500);
        }
    }

    public function test_api_errors_are_counted_and_alerted_once_then_resolved(): void
    {
        $this->getJson('/api/v1/health')->assertOk();
        $this->serverErrors(12);
        $this->assertSame(['total' => 13, 'errors' => 12, 'rate' => 12 / 13], app(ApiErrorRate::class)->lastMinutes(5));

        $this->check();
        $texts = $this->telegramTexts();
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('🔴 CRITIQUE : Erreurs de l\'API', $texts[0]);
        $this->assertStringContainsString('12 réponse(s) en erreur serveur (5xx) sur 13', $texts[0]);
        $this->assertStringNotContainsString('jeton-secret', $texts[0]);
        Mail::assertSent(MonitoringAlertMail::class, fn ($mail) => $mail->hasTo('astreinte@example.com') && $mail->hasTo('renfort@example.com'));

        // Toujours active 5 min plus tard : pas de nouveau message.
        $this->travel(4)->minutes();
        $this->serverErrors(12);
        $this->check();
        $this->assertCount(1, $this->telegramTexts());

        // Les erreurs sortent de la fenêtre : « résolu », une seule fois.
        $this->travel(10)->minutes();
        $this->check();
        $this->check();
        $texts = $this->telegramTexts();
        $this->assertCount(2, $texts);
        $this->assertStringContainsString('✅ Résolu : Erreurs de l\'API', $texts[1]);
    }

    public function test_a_few_errors_among_many_requests_do_not_alert(): void
    {
        $this->serverErrors(3);
        $this->check();

        $this->assertSame([], $this->telegramTexts());
        Mail::assertNothingSent();
    }

    public function test_a_critical_alert_still_active_is_repeated_every_hour(): void
    {
        Cache::forever(AlertDispatcher::STATE_KEY, []);
        $dispatcher = app(AlertDispatcher::class);
        $alert = ['key' => 'reconciliation', 'severity' => 'critical', 'category' => 'Finance', 'title' => '2 écart(s) de rapprochement', 'detail' => 'Grand livre incohérent.', 'link' => '/admin/finance'];

        $dispatcher->dispatch([$alert]);
        $this->travel(30)->minutes();
        $dispatcher->dispatch([$alert]);
        $this->travel(31)->minutes();
        $dispatcher->dispatch([$alert]);

        $texts = $this->telegramTexts();
        $this->assertCount(2, $texts);
        $this->assertStringContainsString('Toujours active — 2 écart(s) de rapprochement', $texts[1]);
        $this->assertStringContainsString('Écran : http://localhost:5173/admin/finance', $texts[0]);
    }

    public function test_a_warning_that_becomes_critical_is_sent_again(): void
    {
        $dispatcher = app(AlertDispatcher::class);
        $alert = ['key' => 'provider_credits', 'severity' => 'warning', 'category' => 'Données sportives', 'title' => 'Crédits bientôt épuisés', 'detail' => '…', 'link' => null];

        $dispatcher->dispatch([$alert]);
        $dispatcher->dispatch([['severity' => 'critical', 'title' => 'Crédits épuisés'] + $alert]);

        $texts = $this->telegramTexts();
        $this->assertCount(2, $texts);
        $this->assertStringContainsString('🟠 Avertissement : Crédits bientôt épuisés', $texts[0]);
        $this->assertStringContainsString('🔴 CRITIQUE : Crédits épuisés', $texts[1]);
    }

    public function test_financial_anomaly_from_the_reconciliation_is_pushed(): void
    {
        $ledger = app(LedgerService::class);
        $entry = $ledger->creditSimulated($ledger->walletFor(User::factory()->create()), Money::ofMinor(100_00, 'HTG'), 'test:anomalie');
        DB::table('ledger_entries')->where('id', $entry->id)->update(['balance_after_minor' => 999_00]); // solde incohérent

        $this->check();

        $this->assertStringContainsString('🔴 CRITIQUE : 1 écart(s) de rapprochement', $this->telegramTexts()[0]);
    }

    public function test_settlement_failure_is_pushed(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'a1b2', 'connection' => 'redis', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'SettleEventJob']),
            'exception' => 'Erreur', 'failed_at' => now(),
        ]);

        $this->check();

        $this->assertStringContainsString('1 tâche(s) automatique(s) en échec sur 24 h', $this->telegramTexts()[0]);
    }

    public function test_synchronisation_delay_is_pushed(): void
    {
        $this->app->instance(SportsDataProvider::class, new FakeSportsDataProvider);
        app(CatalogueSyncService::class)->sync('soccer_epl');

        $this->check();
        $this->assertSame([], $this->telegramTexts());

        Event::query()->update(['last_synced_at' => now()->subHours(7)]);
        $this->check();

        $this->assertStringContainsString('🟠 Avertissement : Retard de synchronisation du catalogue', $this->telegramTexts()[0]);
    }

    public function test_a_stopped_queue_worker_is_pushed(): void
    {
        config(['queue.default' => 'redis']);
        app(QueueHeartbeat::class)->beat();

        $this->check();
        $this->assertSame([], $this->telegramTexts());

        $this->travel(6)->minutes();
        $this->check();

        $this->assertStringContainsString('🔴 CRITIQUE : Travailleur de file arrêté', $this->telegramTexts()[0]);
    }

    public function test_one_failing_channel_does_not_block_the_other(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
        $this->serverErrors(12);

        $this->check();

        Mail::assertSent(MonitoringAlertMail::class, fn ($mail) => str_contains($mail->subjectLine, 'Erreurs de l\'API'));
    }

    public function test_the_test_option_reaches_every_configured_channel(): void
    {
        $this->artisan('monitoring:check', ['--test' => true])
            ->expectsOutputToContain('envoyé')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.telegram.org/botjeton-secret/sendMessage')
            && $request['chat_id'] === '-100123' && str_contains($request['text'], '🧪 Test des alertes'));
        Mail::assertSent(MonitoringAlertMail::class, fn ($mail) => str_contains($mail->subjectLine, '🧪 Test des alertes'));
    }

    public function test_the_test_option_fails_without_any_channel(): void
    {
        config(['monitoring.telegram.bot_token' => null, 'monitoring.mail_to' => []]);

        $this->artisan('monitoring:check', ['--test' => true])->assertFailed();
    }

    public function test_the_external_heartbeat_is_pinged_after_each_check(): void
    {
        config(['monitoring.heartbeat_url' => 'https://hc-ping.com/uuid-de-test']);
        Http::fake(['hc-ping.com/*' => Http::response('OK')]);

        $this->check();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc-ping.com/uuid-de-test');
    }
}
