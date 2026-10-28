<?php

namespace App\Modules\IdentityAccess\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Support\Http\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * MFA TOTP obligatoire pour les comptes administratifs.
 *
 * Parcours : setup (secret + QR) → confirm (premier code, codes de secours affichés une fois)
 * → à chaque connexion : verify (code TOTP ou code de secours) avant toute route admin.
 */
class MfaService
{
    public const SESSION_KEY = 'mfa_verified_user_id';

    public const RECOVERY_CODES = 8;

    public function __construct(private readonly AuditLogger $audit) {}

    public function isEnabled(User $user): bool
    {
        return $user->mfa_confirmed_at !== null;
    }

    public function isVerified(User $user, ?Session $session): bool
    {
        return $session !== null && $session->get(self::SESSION_KEY) === $user->getKey();
    }

    /**
     * Génère un nouveau secret (non actif tant qu'il n'est pas confirmé).
     *
     * @return array{secret: string, otpauth_uri: string}
     */
    public function setup(User $user): array
    {
        if ($this->isEnabled($user)) {
            throw new ApiException('mfa_already_enabled', __('La double authentification est déjà activée.'), 409);
        }

        $secret = Totp::generateSecret();
        $user->forceFill(['mfa_secret' => $secret, 'mfa_last_used_step' => null])->save();

        return [
            'secret' => $secret,
            // Email facultatif depuis la connexion par téléphone : le numéro sert alors de nom de compte (revue P8-02, F1).
            'otpauth_uri' => Totp::provisioningUri($secret, $user->email ?? $user->phone, (string) config('app.name')),
        ];
    }

    /**
     * Active le MFA avec un premier code valide.
     *
     * @return list<string> codes de secours en clair, à afficher une seule fois
     */
    public function confirm(User $user, string $code, Session $session): array
    {
        if ($this->isEnabled($user)) {
            throw new ApiException('mfa_already_enabled', __('La double authentification est déjà activée.'), 409);
        }

        if ($user->mfa_secret === null) {
            throw new ApiException('mfa_setup_required', __('Commencez par configurer la double authentification.'), 409);
        }

        $this->consumeTotp($user, $code);

        $codes = $this->newRecoveryCodes($user);
        $user->forceFill(['mfa_confirmed_at' => now()])->save();
        $session->put(self::SESSION_KEY, $user->getKey());

        return $codes;
    }

    /** Valide la session courante avec un code TOTP ou un code de secours (consommé). */
    public function verify(User $user, ?string $code, ?string $recoveryCode, Session $session): void
    {
        if (! $this->isEnabled($user)) {
            throw new ApiException('mfa_setup_required', __('Commencez par configurer la double authentification.'), 409);
        }

        $method = $recoveryCode !== null && $recoveryCode !== '' ? 'recovery_code' : 'totp';

        try {
            if ($method === 'recovery_code') {
                $this->consumeRecoveryCode($user, $recoveryCode);
            } else {
                $this->consumeTotp($user, (string) $code);
            }
        } catch (ApiException $e) {
            $this->recordFailure($user, $method, $session);

            throw $e;
        }

        RateLimiter::clear($this->failuresKey($user));
        $session->migrate(true);
        $session->put(self::SESSION_KEY, $user->getKey());
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        return $this->newRecoveryCodes($user);
    }

    /** Réinitialise le MFA d'un autre administrateur (appareil perdu). Audité. */
    public function reset(User $actor, User $target, string $reason): void
    {
        if (! $actor->hasPermission('roles.manage')) {
            throw new AuthorizationException;
        }

        if ($actor->is($target)) {
            throw new ApiException('cannot_reset_own_mfa', __('Vous ne pouvez pas réinitialiser votre propre double authentification.'), 403);
        }

        DB::transaction(function () use ($actor, $target, $reason) {
            $before = ['mfa_enabled' => $this->isEnabled($target)];

            $target->forceFill([
                'mfa_secret' => null,
                'mfa_confirmed_at' => null,
                'mfa_recovery_codes' => null,
                'mfa_last_used_step' => null,
            ])->save();

            $this->audit->record(AuditAction::MfaReset, $target, $before, ['mfa_enabled' => false], $reason, $actor);
        });
    }

    /**
     * Échec de mfa/verify : audité (mfa.failed, base des alertes de sécurité) ; après N échecs consécutifs,
     * la session est fermée et il faut repasser par le mot de passe (revue P8-02, M2).
     *
     * @throws ApiException mfa_too_many_failures
     */
    private function recordFailure(User $user, string $method, Session $session): void
    {
        $failures = RateLimiter::hit($this->failuresKey($user), 3600);
        $loggedOut = $failures >= (int) config('identity.mfa.max_failures_before_logout');

        $this->audit->record(AuditAction::MfaFailed, $user, actor: $user, metadata: [
            'method' => $method,
            'consecutive_failures' => $failures,
            'logged_out' => $loggedOut,
        ]);

        if (! $loggedOut) {
            return;
        }

        Log::warning('mfa.too_many_failures', ['user_id' => $user->getKey(), 'consecutive_failures' => $failures]);

        // Le compteur n'est pas remis à zéro : après reconnexion, un nouvel échec referme la session.
        Auth::guard('web')->logout();
        $session->invalidate();
        $session->regenerateToken();

        throw new ApiException('mfa_too_many_failures', __('Trop de codes invalides : vous avez été déconnecté. Reconnectez-vous.'), 401);
    }

    private function failuresKey(User $user): string
    {
        return 'mfa-failures:'.$user->getKey();
    }

    private function consumeTotp(User $user, string $code): void
    {
        $step = Totp::verify((string) $user->mfa_secret, $code);

        // Un code déjà utilisé (même période ou antérieure) est refusé : pas de rejeu.
        if ($step === null || ($user->mfa_last_used_step !== null && $step <= $user->mfa_last_used_step)) {
            throw new ApiException('invalid_mfa_code', __('Code de vérification invalide.'), 422);
        }

        $user->forceFill(['mfa_last_used_step' => $step])->save();
    }

    private function consumeRecoveryCode(User $user, string $code): void
    {
        $hash = $this->hashRecoveryCode($code);
        $remaining = collect($user->mfa_recovery_codes ?? []);

        if (! $remaining->contains(fn (string $stored) => hash_equals($stored, $hash))) {
            throw new ApiException('invalid_mfa_code', __('Code de vérification invalide.'), 422);
        }

        $user->forceFill(['mfa_recovery_codes' => $remaining->reject(fn (string $stored) => hash_equals($stored, $hash))->values()->all()])->save();
    }

    /** @return list<string> */
    private function newRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, self::RECOVERY_CODES))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();

        $user->forceFill(['mfa_recovery_codes' => array_map($this->hashRecoveryCode(...), $codes)])->save();

        return $codes;
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash_hmac('sha256', Str::lower(trim($code)), (string) config('app.key'));
    }
}
