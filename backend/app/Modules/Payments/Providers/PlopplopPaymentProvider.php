<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Contracts\ChecksPaymentStatus;
use App\Modules\Payments\Contracts\PayoutProvider;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\InvalidWebhookException;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Support\PaymentInitiation;
use App\Modules\Payments\Support\PaymentNotification;
use App\Modules\Payments\Support\PaymentStatusCheck;
use App\Modules\Payments\Support\PayoutResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Plop Plop (API Paiement & Retrait Marchand v1.4) : une page de paiement unique pour MonCash, NatCash, Kashpaw et la
 * carte (Square). Documentation : « API Paiement Marchand.html », fournie par le prestataire.
 *
 * - Ouverture : POST api/paiement-marchand (client_id, refference_id = notre référence pay_…, montant en HTG,
 *   payment_method) → url de la page de paiement et transaction_id.
 * - Webhook (transaction.created, transaction.status_changed) : en-tête X-Webhook-Signature « sha256=<hmac> », HMAC-SHA256
 *   du corps brut avec le client_secret. Le webhook n'a pas d'identifiant d'événement : on prend
 *   « <événement>:<transaction_id>:<statut> ». Il n'a pas non plus d'horodatage signé : un webhook capturé et rejoué
 *   porte le même identifiant et n'a donc aucun nouvel effet (idempotence de PaymentService).
 * - Vérification : POST api/paiement-verify (trans_status ok ou no). Le webhook n'est envoyé qu'une fois, sans nouvel
 *   essai : la vérification sert de secours (commande payments:reconcile, suivi du dépôt par le joueur).
 *
 * - Retraits (V2-B03), versés depuis le portefeuille marchand vers MonCash ou NatCash :
 *   1. api/auth/marchand (client_id, client_secret) → jeton marchand ;
 *   2. api/auth/marchand/withdrawal-token, signé HMAC-SHA256("amount|method|recipient|reference|timestamp") → jeton de
 *      retrait (2 minutes, usage unique, lié à ces valeurs) ;
 *   3. api/withdraw/marchand → success ou failed immédiat ; la référence (notre wd_…) est unique (409 si déjà versée) ;
 *   vérification par api/withdraw/marchand/verify ; solde par api/marchand/balance. 120 s entre deux retraits par IP.
 */
class PlopplopPaymentProvider implements ChecksPaymentStatus, PayoutProvider
{
    public const SIGNATURE_HEADER = 'X-Webhook-Signature';

    /** Codes d'erreur de l'étape 3 qui laissent le retrait non exécuté : réessayé plus tard. */
    private const RETRYABLE_ERRORS = ['INSUFFICIENT_BALANCE', 'METHOD_NOT_CONFIGURED', 'WITHDRAWAL_COOLDOWN', 'TOKEN_ALREADY_USED', 'INVALID_TOKEN_TYPE', 'PARAMETER_MISMATCH'];

    public function availableBalance(): ?int
    {
        $response = $this->http()->withToken($this->merchantToken())->get('api/marchand/balance');
        $balance = $response->json('data.available_balance');

        return $response->successful() && is_numeric($balance) ? self::minor($balance) : null;
    }

    public function payout(PaymentTransaction $transaction): PayoutResult
    {
        $body = [
            'amount' => self::amount($transaction->amount_minor),
            'method' => (string) ($transaction->metadata['method'] ?? ''),
            'recipient' => (string) ($transaction->metadata['recipient'] ?? ''),
            'reference' => $transaction->reference,
        ];

        try {
            $merchantToken = $this->merchantToken();
        } catch (RuntimeException $e) {
            return PayoutResult::retryLater($e->getMessage());
        }

        // Étape 2 : jeton de retrait lié au montant, au destinataire, à la méthode et à la référence.
        $timestamp = now()->getTimestamp();
        $signed = implode('|', [$body['amount'], $body['method'], $body['recipient'], $body['reference'], $timestamp]);
        $tokenResponse = $this->http()->withToken($merchantToken)->post('api/auth/marchand/withdrawal-token', $body + [
            'timestamp' => $timestamp,
            'withdrawal_signature' => hash_hmac('sha256', $signed, (string) $this->config('client_secret')),
        ]);
        $withdrawalToken = $tokenResponse->json('withdrawal_token');
        if (! $tokenResponse->successful() || ! is_string($withdrawalToken)) {
            // 400 sur le montant ou le numéro : refus définitif ; signature, secret ou jeton : configuration à corriger.
            return $tokenResponse->status() === 400 && ! str_contains((string) $tokenResponse->json('error_code'), 'TIMESTAMP')
                ? PayoutResult::rejected('Jeton de retrait refusé : '.$tokenResponse->json('message'))
                : PayoutResult::retryLater("Jeton de retrait refusé (HTTP {$tokenResponse->status()}, {$tokenResponse->json('error_code')}).");
        }

        // Étape 3 : exécution, avec exactement les mêmes valeurs qu'à l'étape 2.
        try {
            $response = $this->http()->withToken($withdrawalToken)->post('api/withdraw/marchand', $body);
        } catch (Throwable $e) {
            return PayoutResult::unknown('Pas de réponse au versement : '.$e->getMessage());
        }

        $code = (string) $response->json('error_code');
        $data = $response->json('data');

        return match (true) {
            $response->successful() && $response->json('success') === true && ($data['status'] ?? null) === 'success' => PayoutResult::paid(isset($data['transaction_id']) ? (string) $data['transaction_id'] : null, is_numeric($data['fee'] ?? null) ? self::minor($data['fee']) : null, isset($data['api_reference']) ? (string) $data['api_reference'] : null),
            $response->status() === 409 => $this->checkPayout($transaction) ?? PayoutResult::unknown('Référence déjà utilisée chez Plop Plop.'),
            $response->status() === 429 || in_array($code, self::RETRYABLE_ERRORS, true) => PayoutResult::retryLater("Versement non exécuté ({$code}) : ".$response->json('message')),
            $response->status() === 400 && ($data['status'] ?? null) === 'failed' => PayoutResult::rejected('Versement refusé : '.$response->json('message')),
            $response->clientError() => PayoutResult::retryLater("Versement refusé (HTTP {$response->status()}, {$code})."),
            default => PayoutResult::unknown("Réponse inattendue au versement (HTTP {$response->status()})."),
        };
    }

    public function checkPayout(PaymentTransaction $transaction): ?PayoutResult
    {
        $response = $this->http()->withToken($this->merchantToken())
            ->post('api/withdraw/marchand/verify', ['reference' => $transaction->reference]);

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful() || $response->json('success') !== true) {
            throw new RuntimeException("Vérification du retrait impossible (HTTP {$response->status()}).");
        }

        return match ($response->json('data.status')) {
            'success' => PayoutResult::paid($response->json('data.transaction_id') === null ? null : (string) $response->json('data.transaction_id')),
            'failed', 'remboursé', 'rembourse' => PayoutResult::rejected('Retrait échoué ou remboursé chez Plop Plop.'),
            default => PayoutResult::unknown('Retrait encore en cours chez Plop Plop.'),
        };
    }

    public function payoutCooldownSeconds(): int
    {
        return (int) $this->config('payout_cooldown_seconds', 125);
    }

    /** Étape 1 : jeton marchand (environ une minute), demandé à chaque opération. */
    private function merchantToken(): string
    {
        $response = $this->http()->post('api/auth/marchand', [
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
        ]);
        $token = $response->json('token');

        if (! $response->successful() || ! is_string($token)) {
            throw new RuntimeException("Authentification marchand Plop Plop refusée (HTTP {$response->status()}).");
        }

        return $token;
    }

    public function name(): string
    {
        return 'plopplop';
    }

    public function initiate(PaymentTransaction $transaction): PaymentInitiation
    {
        $response = $this->http()->post('api/paiement-marchand', [
            'client_id' => $this->config('client_id'),
            'refference_id' => $transaction->reference, // orthographe de l'API
            'montant' => self::amount($transaction->amount_minor),
            'payment_method' => $this->config('payment_method') ?: 'all',
        ]);

        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ($data['status'] ?? false) !== true || ! is_scalar($data['transaction_id'] ?? null)) {
            throw new RuntimeException("Plop Plop a refusé l'ouverture du paiement (HTTP {$response->status()}).");
        }

        $url = is_string($data['url'] ?? null) && str_starts_with($data['url'], 'https://') ? $data['url'] : null;

        return new PaymentInitiation((string) $data['transaction_id'], $url);
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) $this->config('client_secret');
        $signature = (string) $request->header(self::SIGNATURE_HEADER);

        return $secret !== '' && $signature !== ''
            && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }

    public function parseWebhook(Request $request): PaymentNotification
    {
        $data = json_decode($request->getContent(), true);
        $event = is_array($data) ? ($data['event'] ?? null) : null;
        $transaction = is_array($data) ? ($data['transaction'] ?? null) : null;

        if (! in_array($event, ['transaction.created', 'transaction.status_changed'], true) || ! is_array($transaction)) {
            throw new InvalidWebhookException('Webhook Plop Plop illisible.');
        }

        $rawStatus = $event === 'transaction.created' ? ($transaction['status'] ?? null) : ($transaction['new_status'] ?? null);
        $status = self::status($rawStatus);
        $id = $transaction['transaction_id'] ?? null;
        $reference = $transaction['reference_id'] ?? null;
        $amount = $transaction['amount'] ?? null;

        if ($status === null || ! is_scalar($id) || ! is_string($reference) || ! is_numeric($amount)) {
            throw new InvalidWebhookException('Webhook Plop Plop incomplet.');
        }

        return new PaymentNotification("{$event}:{$id}:{$rawStatus}", $reference, (string) $id, $status, self::minor($amount), 'HTG');
    }

    public function checkStatus(PaymentTransaction $transaction): ?PaymentStatusCheck
    {
        $response = $this->http()->post('api/paiement-verify', [
            'client_id' => $this->config('client_id'),
            'refference_id' => $transaction->reference,
        ]);

        if ($response->status() === 404) {
            return null; // paiement inconnu du prestataire (jamais ouvert de son côté)
        }

        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ($data['status'] ?? false) !== true) {
            throw new RuntimeException("Vérification Plop Plop impossible (HTTP {$response->status()}).");
        }

        $status = self::status($data['trans_status'] ?? null);
        $id = $data['transaction_id'] ?? $transaction->provider_reference;
        if ($status === null || ! is_scalar($id) || ! is_numeric($data['montant'] ?? null)) {
            throw new RuntimeException('Réponse de vérification Plop Plop incomplète.');
        }

        return new PaymentStatusCheck(
            new PaymentNotification("verify:{$id}:{$data['trans_status']}", $transaction->reference, (string) $id, $status, self::minor($data['montant']), 'HTG'),
            (string) $response->body(),
        );
    }

    /** ok → réussi ; failed → échoué ; no / pending → en attente ; autre → illisible. */
    private static function status(mixed $value): ?PaymentStatus
    {
        return match ($value) {
            'ok' => PaymentStatus::Succeeded,
            'failed' => PaymentStatus::Failed,
            'no', 'pending' => PaymentStatus::Pending,
            default => null,
        };
    }

    /** 250,00 HTG → 250 ; 250,50 HTG → 250.5 (l'API attend un montant en gourdes). */
    public static function amount(int $minor): int|float
    {
        return $minor % 100 === 0 ? intdiv($minor, 100) : $minor / 100;
    }

    /** Montant en gourdes (nombre ou chaîne) → unité mineure, sans erreur d'arrondi des flottants. */
    private static function minor(int|float|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) $this->config('base_url'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) $this->config('timeout', 15));
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("payments.providers.plopplop.{$key}", $default);
    }
}
