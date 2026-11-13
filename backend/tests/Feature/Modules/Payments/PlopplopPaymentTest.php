<?php

namespace Tests\Feature\Modules\Payments;

use App\Models\User;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\WebhookEvent;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Support\ProductionSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** V2-B02 : dépôts par Plop Plop (API simulée) — ouverture, webhook signé, vérification de secours. */
class PlopplopPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'secret-plopplop-de-test';

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();

        config([
            'payments.providers.plopplop.enabled' => true,
            'payments.providers.plopplop.base_url' => 'https://plopplop.test/',
            'payments.providers.plopplop.client_id' => 'pp_test',
            'payments.providers.plopplop.client_secret' => self::SECRET,
            'payments.providers.plopplop.payment_method' => 'all',
            'payments.providers.fake.enabled' => false,
        ]);
        $this->player = User::factory()->create();
    }

    private function fakeOpening(string $transactionId = '173763124912345'): void
    {
        Http::fake(['plopplop.test/api/paiement-marchand' => Http::response([
            'status' => true, 'message' => 'success', 'url' => "https://plopplop.test/pay?id={$transactionId}", 'transaction_id' => $transactionId,
        ])]);
    }

    private function open(int $amountMinor = 1_500_00): TestResponse
    {
        return $this->actingAs($this->player)->postJson('/api/v1/payments/deposits',
            ['provider' => 'plopplop', 'amount_minor' => $amountMinor], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /** @param  array<string, mixed>  $transaction */
    private function webhook(array $transaction, string $event = 'transaction.status_changed', ?string $signature = null): TestResponse
    {
        $body = json_encode([
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'transaction' => $transaction + ['transaction_id' => '173763124912345', 'amount' => 1500.00, 'method' => 'moncash', 'date' => '2026-11-12', 'heure' => '10:00:00'],
            'merchant' => ['id' => 42, 'client_id' => 'pp_test', 'entreprise' => 'REYEL 16 PARYAJ'],
        ]);

        return $this->call('POST', '/api/v1/payments/webhooks/plopplop', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature ?? 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], content: $body);
    }

    private function balance(): int
    {
        return $this->actingAs($this->player)->getJson('/api/v1/wallet')->json('data.balance.minor');
    }

    public function test_the_player_is_sent_to_the_plopplop_payment_page(): void
    {
        $this->fakeOpening();

        $response = $this->open()->assertCreated()
            ->assertJsonPath('data.provider', 'plopplop')
            ->assertJsonPath('data.provider_label', 'MonCash, NatCash ou carte')
            ->assertJsonPath('data.redirect_url', 'https://plopplop.test/pay?id=173763124912345');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://plopplop.test/api/paiement-marchand'
            && $r['client_id'] === 'pp_test'
            && $r['refference_id'] === $response->json('data.reference')
            && $r['montant'] === 1500
            && $r['payment_method'] === 'all');
        $this->assertSame('173763124912345', PaymentTransaction::sole()->provider_reference);
        $this->assertSame(0, $this->balance());
    }

    public function test_a_refused_opening_fails_the_deposit_without_credit(): void
    {
        Http::fake(['plopplop.test/*' => Http::response(['status' => false, 'message' => 'Client introuvable'], 404)]);

        $this->open()->assertStatus(502)->assertJsonPath('error.code', 'payment_provider_error');
        $this->assertSame(PaymentStatus::Failed, PaymentTransaction::sole()->status);
    }

    public function test_a_confirmed_payment_is_credited_once_by_the_signed_webhook(): void
    {
        $this->fakeOpening();
        $reference = $this->open()->json('data.reference');

        $this->webhook(['reference_id' => $reference, 'status' => 'no'], 'transaction.created')
            ->assertOk()->assertJsonPath('data.outcome', 'ignored');
        $this->assertSame(0, $this->balance());

        $confirmed = ['reference_id' => $reference, 'old_status' => 'no', 'new_status' => 'ok'];
        $this->webhook($confirmed)->assertOk()->assertJsonPath('data.outcome', 'applied');
        $this->webhook($confirmed)->assertOk()->assertJsonPath('data.outcome', 'applied'); // rejoué : même réponse

        $this->assertSame(1_500_00, $this->balance());
        $entry = LedgerEntry::where('type', LedgerEntryType::Deposit)->sole();
        $this->assertSame("payment:{$reference}", $entry->reference);
        $this->assertSame('Dépôt MonCash, NatCash ou carte', $entry->description);
        $this->assertSame(2, WebhookEvent::count());
    }

    public function test_a_wrong_signature_or_a_failed_payment_credits_nothing(): void
    {
        $this->fakeOpening();
        $reference = $this->open()->json('data.reference');

        $this->webhook(['reference_id' => $reference, 'new_status' => 'ok'], signature: 'sha256='.str_repeat('0', 64))
            ->assertUnauthorized()->assertJsonPath('error.code', 'invalid_signature');
        $this->webhook(['reference_id' => $reference, 'new_status' => 'ok'], signature: '')->assertUnauthorized();

        $this->webhook(['reference_id' => $reference, 'old_status' => 'no', 'new_status' => 'failed'])->assertOk();

        $this->assertSame(PaymentStatus::Failed, PaymentTransaction::sole()->status);
        $this->assertSame(0, $this->balance());
    }

    public function test_a_different_amount_is_put_in_review_without_credit(): void
    {
        $this->fakeOpening();
        $reference = $this->open()->json('data.reference');

        $this->webhook(['reference_id' => $reference, 'new_status' => 'ok', 'amount' => 15.00])
            ->assertOk()->assertJsonPath('data.outcome', 'review');

        $this->assertSame(PaymentStatus::Review, PaymentTransaction::sole()->status);
        $this->assertSame(0, $this->balance());
    }

    public function test_an_unreadable_webhook_is_refused(): void
    {
        $this->webhook(['reference_id' => 'pay_x', 'new_status' => 'peut-etre'])->assertStatus(400);
        $this->webhook(['reference_id' => 'pay_x', 'new_status' => 'ok'], event: 'merchant.updated')->assertStatus(400);
    }

    /** Plop Plop simulé : ouverture, puis vérification qui renvoie le statut courant de $status (ok, no) ou $httpStatus. */
    private function fakePlopplop(string &$status, int &$httpStatus): void
    {
        Http::fake(function (HttpRequest $request) use (&$status, &$httpStatus) {
            if (str_ends_with($request->url(), 'api/paiement-marchand')) {
                return Http::response(['status' => true, 'url' => 'https://plopplop.test/pay?id=173763124912345', 'transaction_id' => '173763124912345']);
            }

            return $httpStatus !== 200 ? Http::response('Erreur interne', $httpStatus) : Http::response([
                'status' => true, 'message' => 'success', 'montant' => 1500, 'trans_status' => $status,
                'transaction_id' => '173763124912345', 'refference_id' => $request['refference_id'], 'method' => 'moncash',
            ]);
        });
    }

    public function test_a_missed_webhook_is_caught_up_by_the_verification_command(): void
    {
        $status = 'no';
        $http = 200;
        $this->fakePlopplop($status, $http);
        $reference = $this->open()->json('data.reference');

        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(PaymentStatus::Pending, PaymentTransaction::sole()->status);

        // Plus tard, le paiement est confirmé, mais le webhook s'est perdu : la vérification crédite.
        $this->travel(45)->minutes();
        $this->artisan('payments:expire')->assertSuccessful();
        $status = 'ok';
        $this->artisan('payments:reconcile')->expectsOutputToContain('1 paiement(s) mis à jour.')->assertSuccessful();
        $this->artisan('payments:reconcile')->expectsOutputToContain('0 paiement(s) mis à jour.')->assertSuccessful();

        $transaction = PaymentTransaction::sole();
        $this->assertSame(PaymentStatus::Succeeded, $transaction->status);
        $this->assertTrue($transaction->metadata['late']); // confirmé après l'expiration : crédité et signalé
        $this->assertSame(1_500_00, $this->balance());
        $this->assertSame(1, LedgerEntry::count());

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://plopplop.test/api/paiement-verify'
            && $r['client_id'] === 'pp_test' && $r['refference_id'] === $reference);
    }

    public function test_following_the_deposit_checks_plopplop_at_most_every_ten_seconds(): void
    {
        $status = 'ok';
        $http = 200;
        $this->fakePlopplop($status, $http);
        $reference = $this->open()->json('data.reference');

        $this->actingAs($this->player)->getJson("/api/v1/payments/deposits/{$reference}")->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(1_500_00, $this->balance());

        $this->actingAs($this->player)->getJson("/api/v1/payments/deposits/{$reference}")->assertJsonPath('data.status', 'succeeded');
        Http::assertSentCount(2); // ouverture + une seule vérification (statut final ensuite)
    }

    public function test_an_unreachable_plopplop_never_breaks_the_follow_up(): void
    {
        $status = 'ok';
        $http = 503;
        $this->fakePlopplop($status, $http);
        $reference = $this->open()->json('data.reference');

        $this->actingAs($this->player)->getJson("/api/v1/payments/deposits/{$reference}")
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(0, $this->balance());
    }

    public function test_production_refuses_plopplop_without_its_secret(): void
    {
        $this->assertNotContains(true, array_map(fn ($p) => str_contains($p, 'PLOPPLOP'), ProductionSafety::problems()));

        config(['payments.providers.plopplop.client_secret' => '']);
        $this->assertContains(true, array_map(fn ($p) => str_contains($p, 'PLOPPLOP'), ProductionSafety::problems()));
    }
}
