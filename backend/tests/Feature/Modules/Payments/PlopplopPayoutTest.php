<?php

namespace Tests\Feature\Modules\Payments;

use App\Models\User;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V2-B03 : versement des retraits par Plop Plop (API simulée) — solde marchand, jeton de retrait signé, exécution,
 * délai de 120 s entre deux retraits, résultat inconnu vérifié par la référence.
 */
class PlopplopPayoutTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'secret-plopplop-de-test';

    private User $player;

    /** Réponse de l'étape 3 (exécution) et de la vérification, modifiables par chaque test. */
    private mixed $withdrawResponse;

    private mixed $verifyResponse;

    private int $merchantBalance = 1_000_000;

    /** Appels à l'étape 3, y compris ceux restés sans réponse. */
    private int $withdrawCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();

        config([
            'wallet.simulated' => true,
            'payments.providers.fake.enabled' => false,
            'payments.providers.plopplop.enabled' => true,
            'payments.providers.plopplop.base_url' => 'https://plopplop.test/',
            'payments.providers.plopplop.client_id' => 'pp_test',
            'payments.providers.plopplop.client_secret' => self::SECRET,
            'payments.withdrawals.enabled' => true,
            'payments.withdrawals.provider' => 'plopplop',
        ]);
        $this->player = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($this->player), Money::ofMinor(2_000_00, 'HTG'), 'test:gains');

        $this->withdrawResponse = fn (HttpRequest $r) => Http::response(['success' => true, 'message' => 'Retrait MonCash effectué avec succès.', 'data' => [
            'transaction_id' => 'API_WD_'.$r['reference'], 'api_reference' => '9876543210', 'amount' => $r['amount'], 'fee' => 12.5,
            'recipient' => $r['recipient'], 'reference' => $r['reference'], 'status' => 'success',
        ]]);
        $this->verifyResponse = Http::response(['success' => false], 404);

        Http::fake(function (HttpRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/api/auth/marchand' => Http::response(['success' => true, 'token' => 'jeton-marchand', 'expires_in' => 300]),
                '/api/marchand/balance' => Http::response(['success' => true, 'data' => ['available_balance' => $this->merchantBalance, 'currency' => 'HTG']]),
                '/api/auth/marchand/withdrawal-token' => $this->withdrawalToken($request),
                '/api/withdraw/marchand' => ++$this->withdrawCalls > 0 && is_callable($this->withdrawResponse) ? ($this->withdrawResponse)($request) : $this->withdrawResponse,
                '/api/withdraw/marchand/verify' => $this->verifyResponse,
                default => Http::response('inconnu', 500),
            };
        });
    }

    /** Étape 2 simulée comme le vrai serveur : jeton refusé si la signature HMAC ne correspond pas. */
    private function withdrawalToken(HttpRequest $request)
    {
        $expected = hash_hmac('sha256', implode('|', [$request['amount'], $request['method'], $request['recipient'], $request['reference'], $request['timestamp']]), self::SECRET);

        return $request->hasHeader('Authorization', 'Bearer jeton-marchand') && hash_equals($expected, (string) $request['withdrawal_signature'])
            ? Http::response(['success' => true, 'withdrawal_token' => 'jeton-retrait', 'expires_in' => 120])
            : Http::response(['success' => false, 'error_code' => 'INVALID_SIGNATURE'], 403);
    }

    private function withdraw(int $amountMinor = 500_00, string $method = 'natcash', ?User $as = null): string
    {
        return $this->actingAs($as ?? $this->player)->postJson('/api/v1/payments/withdrawals',
            ['provider' => 'plopplop', 'method' => $method, 'recipient' => '34123456', 'amount_minor' => $amountMinor], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->json('data.reference');
    }

    private function balance(): int
    {
        $ledger = app(LedgerService::class);

        return $ledger->balance($ledger->walletFor($this->player))->minor;
    }

    public function test_a_withdrawal_is_paid_with_a_signed_token(): void
    {
        $reference = $this->withdraw();

        $this->artisan('payments:payouts')->expectsOutputToContain('1 retrait(s) terminé(s).')->assertSuccessful();

        $transaction = PaymentTransaction::sole();
        $this->assertSame(PaymentStatus::Succeeded, $transaction->status);
        $this->assertSame('API_WD_'.$reference, $transaction->metadata['provider_transaction_id']);
        $this->assertSame('9876543210', $transaction->metadata['external_reference']);
        $this->assertSame(12_50, $transaction->metadata['fee_minor']); // frais pris sur le portefeuille marchand
        $this->assertSame(1_500_00, $this->balance());

        // Étape 3 : mêmes valeurs qu'à l'étape 2, avec le jeton de retrait.
        Http::assertSent(fn (HttpRequest $r) => parse_url($r->url(), PHP_URL_PATH) === '/api/withdraw/marchand'
            && $r->hasHeader('Authorization', 'Bearer jeton-retrait')
            && $r['amount'] === 500 && $r['method'] === 'natcash' && $r['recipient'] === '50934123456' && $r['reference'] === $reference);
    }

    public function test_a_transfer_refused_by_moncash_or_natcash_gives_the_money_back(): void
    {
        $this->withdrawResponse = Http::response(['success' => false, 'message' => 'Échec du retrait NatCash : Numéro NatCash invalide',
            'data' => ['transaction_id' => 'API_WD_NA_1', 'status' => 'failed'], 'error_code' => 'API_TRANSFER_FAILED'], 400);
        $this->withdraw();

        $this->artisan('payments:payouts')->assertSuccessful();

        $transaction = PaymentTransaction::sole();
        $this->assertSame(PaymentStatus::Failed, $transaction->status);
        $this->assertStringContainsString('Numéro NatCash invalide', $transaction->metadata['provider_message']);
        $this->assertSame(2_000_00, $this->balance());
    }

    public function test_nothing_is_sent_while_the_merchant_balance_is_too_low(): void
    {
        $this->merchantBalance = 100; // 100 HTG disponibles pour un retrait de 500 HTG
        $this->withdraw();

        $this->artisan('payments:payouts')->assertSuccessful();

        $transaction = PaymentTransaction::sole();
        $this->assertSame(PaymentStatus::Pending, $transaction->status);
        $this->assertSame('merchant_balance_insufficient', $transaction->metadata['last_retry_reason']);
        $this->assertSame(0, $this->withdrawCalls);
        $this->assertSame(1_500_00, $this->balance()); // toujours réservé, versé dès que le solde marchand le permet

        $this->merchantBalance = 10_000;
        $this->artisan('payments:payouts')->assertSuccessful();
        $this->assertSame(PaymentStatus::Succeeded, PaymentTransaction::sole()->status);
    }

    public function test_withdrawals_wait_120_seconds_between_each_other(): void
    {
        // Deux joueurs (un seul retrait en cours par joueur).
        $other = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($other), Money::ofMinor(500_00, 'HTG'), 'test:gains-2');
        $this->withdraw(300_00);
        $this->withdraw(200_00, 'moncash', $other);

        $this->artisan('payments:payouts')->assertSuccessful();
        $this->artisan('payments:payouts')->assertSuccessful(); // trop tôt : rien n'est envoyé
        $this->assertSame(1, $this->withdrawCalls);
        $this->assertSame([PaymentStatus::Succeeded, PaymentStatus::Pending], PaymentTransaction::orderBy('id')->pluck('status')->all());

        $this->travel(126)->seconds();
        $this->artisan('payments:payouts')->assertSuccessful();
        $this->assertSame(2, $this->withdrawCalls);
        $this->assertSame([PaymentStatus::Succeeded, PaymentStatus::Succeeded], PaymentTransaction::orderBy('id')->pluck('status')->all());
    }

    public function test_a_cooldown_refusal_leaves_the_withdrawal_queued(): void
    {
        $this->withdrawResponse = Http::response(['success' => false, 'error_code' => 'WITHDRAWAL_COOLDOWN'], 429);
        $this->withdraw();

        $this->artisan('payments:payouts')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, PaymentTransaction::sole()->status);
        $this->assertSame(1_500_00, $this->balance());
    }

    public function test_an_unknown_result_is_checked_by_reference_and_never_paid_twice(): void
    {
        $this->withdrawResponse = fn () => throw new ConnectionException('Délai dépassé');
        $reference = $this->withdraw();

        $this->artisan('payments:payouts')->assertSuccessful();
        $this->assertSame(PaymentStatus::Processing, PaymentTransaction::sole()->status);

        // Vérification une minute plus tard : Plop Plop confirme le versement.
        $this->travel(61)->seconds();
        $this->verifyResponse = Http::response(['success' => true, 'data' => ['transaction_id' => '1780234388218123', 'reference' => $reference, 'status' => 'success']]);
        $this->artisan('payments:payouts')->assertSuccessful();

        $this->assertSame(PaymentStatus::Succeeded, PaymentTransaction::sole()->status);
        $this->assertSame(1, $this->withdrawCalls);
        $this->assertSame(1_500_00, $this->balance());
    }

    public function test_a_withdrawal_unknown_to_plopplop_goes_back_to_the_queue(): void
    {
        $this->withdrawResponse = fn () => throw new ConnectionException('Délai dépassé');
        $this->withdraw();
        $this->artisan('payments:payouts')->assertSuccessful();

        $this->travel(61)->seconds(); // vérification : 404, jamais exécuté
        $this->artisan('payments:payouts')->assertSuccessful();
        $this->assertSame(PaymentStatus::Pending, PaymentTransaction::sole()->status);

        $this->travel(126)->seconds();
        $this->withdrawResponse = Http::response(['success' => true, 'data' => ['api_reference' => '111', 'status' => 'success']]);
        $this->artisan('payments:payouts')->assertSuccessful();
        $this->assertSame(PaymentStatus::Succeeded, PaymentTransaction::sole()->status);
    }

    public function test_a_wrong_secret_never_refunds_nor_pays(): void
    {
        config(['payments.providers.plopplop.client_secret' => 'mauvais-secret']);
        $this->withdraw();

        $this->artisan('payments:payouts')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, PaymentTransaction::sole()->status); // configuration à corriger
        $this->assertSame(0, $this->withdrawCalls);
        $this->assertSame(1_500_00, $this->balance());
    }
}
