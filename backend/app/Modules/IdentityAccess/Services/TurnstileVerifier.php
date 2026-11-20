<?php

namespace App\Modules\IdentityAccess\Services;

use App\Modules\IdentityAccess\Contracts\HumanVerifier;
use App\Modules\IdentityAccess\Enums\HumanVerificationResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cloudflare Turnstile (D-17) : le jeton du widget est vérifié auprès de Cloudflare (siteverify), sans nouveau paquet.
 *
 * - Envoyés : la clé secrète, le jeton, l'IP du visiteur et un identifiant unique par vérification (idempotency_key).
 *   Jamais le téléphone, l'email ni le mot de passe.
 * - Un jeton ne sert qu'une fois et expire après 5 minutes : Cloudflare refuse un rejeu (Invalid).
 * - Noms d'hôte : si allowed_hostnames est renseigné, un jeton émis pour un autre site est refusé.
 * - Délai court et une nouvelle tentative sur erreur réseau ; au-delà, Unavailable (jamais une exception) et la date est
 *   gardée pour l'alerte d'exploitation (PS-7). Ni le jeton ni la clé ne sont écrits dans les logs.
 */
class TurnstileVerifier implements HumanVerifier
{
    public const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const UNAVAILABLE_KEY = 'security:turnstile:unavailable_at';

    public function enabled(): bool
    {
        return $this->secret() !== '';
    }

    public function verify(string $token, ?string $ip): HumanVerificationResult
    {
        // Jeton manifestement faux (Cloudflare : 2 048 caractères au plus) : inutile d'appeler le fournisseur.
        if ($token === '' || strlen($token) > 2048) {
            return HumanVerificationResult::Invalid;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('identity.human_verification.timeout_seconds'))
                ->retry(2, 200, throw: false)
                ->post(self::ENDPOINT, array_filter([
                    'secret' => $this->secret(),
                    'response' => $token,
                    'remoteip' => $ip,
                    'idempotency_key' => (string) Str::uuid(),
                ]));
        } catch (Throwable $e) {
            return $this->unavailable(['error' => $e::class]);
        }

        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ! array_key_exists('success', $body)) {
            return $this->unavailable(['status' => $response->status()]);
        }

        if ($body['success'] !== true) {
            // Erreur de configuration de notre côté (clé secrète refusée) : signalée comme une panne, pas comme un robot.
            $codes = array_values(array_filter((array) ($body['error-codes'] ?? []), 'is_string'));
            if (array_intersect($codes, ['missing-input-secret', 'invalid-input-secret']) !== []) {
                return $this->unavailable(['error_codes' => $codes]);
            }

            Log::info('security.turnstile_rejected', ['error_codes' => $codes]);

            return HumanVerificationResult::Invalid;
        }

        $allowed = (array) config('identity.human_verification.allowed_hostnames');
        if ($allowed !== [] && ! in_array($body['hostname'] ?? null, $allowed, true)) {
            Log::warning('security.turnstile_wrong_hostname', ['hostname' => $body['hostname'] ?? null]);

            return HumanVerificationResult::Invalid;
        }

        return HumanVerificationResult::Valid;
    }

    public function lastUnavailableAt(): ?string
    {
        $at = Cache::get(self::UNAVAILABLE_KEY);

        return is_string($at) ? $at : null;
    }

    /** @param array<string, mixed> $context */
    private function unavailable(array $context): HumanVerificationResult
    {
        Log::warning('security.turnstile_unavailable', $context);
        try {
            Cache::put(self::UNAVAILABLE_KEY, now()->toIso8601String(), now()->addDay());
        } catch (Throwable) {
            // Cache indisponible : la vérification reste refusée ou laissée passer selon la route ; seule l'alerte manque.
        }

        return HumanVerificationResult::Unavailable;
    }

    private function secret(): string
    {
        return (string) config('identity.human_verification.turnstile_secret_key');
    }
}
