<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\InvalidWebhookException;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Support\PaymentInitiation;
use App\Modules\Payments\Support\PaymentNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Faux prestataire (tests, développement), signé comme un vrai : en-tête X-Fake-Signature « t=<horodatage>,v1=<hmac> »,
 * HMAC-SHA256 de « <horodatage>.<corps brut> » avec PAYMENTS_FAKE_SECRET. Interdit en production.
 *
 * Corps attendu : {"id": "...", "reference": "...", "provider_reference": "...", "status": "succeeded|failed|pending",
 * "amount_minor": 12300, "currency": "HTG"}.
 */
class FakePaymentProvider implements PaymentProvider
{
    public const SIGNATURE_HEADER = 'X-Fake-Signature';

    public function name(): string
    {
        return 'fake';
    }

    public function initiate(PaymentTransaction $transaction): PaymentInitiation
    {
        return new PaymentInitiation('fake_'.Str::lower((string) Str::ulid()));
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) config('payments.providers.fake.secret');
        if ($secret === '' || ! preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', (string) $request->header(self::SIGNATURE_HEADER), $m)) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $m[1]) > (int) config('payments.webhook_tolerance_seconds')) {
            return false;
        }

        return hash_equals(self::sign($m[1], $request->getContent(), $secret), $m[2]);
    }

    public function parseWebhook(Request $request): PaymentNotification
    {
        $data = json_decode($request->getContent(), true);
        $status = is_array($data) ? PaymentStatus::tryFrom((string) ($data['status'] ?? '')) : null;

        if (! is_array($data) || ! in_array($status, [PaymentStatus::Pending, PaymentStatus::Succeeded, PaymentStatus::Failed], true)
            || ! is_string($data['id'] ?? null) || ! is_string($data['reference'] ?? null)
            || ! is_int($data['amount_minor'] ?? null) || ! is_string($data['currency'] ?? null)) {
            throw new InvalidWebhookException('Webhook du faux prestataire incomplet.');
        }

        return new PaymentNotification($data['id'], $data['reference'], $data['provider_reference'] ?? null, $status,
            $data['amount_minor'], $data['currency']);
    }

    /** En-tête de signature d'un corps : sert aux tests et à simuler un webhook en développement. */
    public static function signatureHeader(string $body, string $secret, ?int $timestamp = null): string
    {
        $timestamp = (string) ($timestamp ?? now()->getTimestamp());

        return "t={$timestamp},v1=".self::sign($timestamp, $body, $secret);
    }

    private static function sign(string $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }
}
