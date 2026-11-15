<?php

namespace App\Modules\IdentityAccess\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\IdentityAccess\Models\TermsAcceptance;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Http\ApiException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Crée un compte joueur actif (sans rôle), son profil et, en V1 (fonds fictifs), son crédit de bienvenue.
     *
     * @param  array{name: string, phone: string, email?: string|null, password: string, birth_date: string, country?: string|null, terms_version: string}  $data
     */
    public function register(array $data, ?string $ip = null, ?string $userAgent = null): User
    {
        return DB::transaction(function () use ($data, $ip, $userAgent) {
            // Email absent => NULL explicite (pas de chaîne vide, qui romprait l'unicité "nullable").
            $email = isset($data['email']) && $data['email'] !== '' ? Str::lower($data['email']) : null;

            $user = User::create([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $email,
                'password' => $data['password'],
            ]);

            $user->profile()->create([
                'birth_date' => $data['birth_date'],
                'country' => $data['country'] ?? null,
            ]);

            // V2-E01 : preuve de l'acceptation des textes (version, date, IP), dans la même transaction que le compte.
            TermsAcceptance::create([
                'user_id' => $user->id,
                'version' => $data['terms_version'],
                'accepted_at' => now(),
                'ip_address' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            ]);

            $this->creditWelcomeFunds($user);

            return $user;
        });
    }

    /**
     * Fonds fictifs de bienvenue : sans eux, un nouveau joueur ne peut placer aucun pari en V1.
     * Écrit par le Ledger (référence unique par compte : jamais crédité deux fois), dans la transaction
     * de l'inscription : pas de compte sans crédit, ni de crédit sans compte.
     */
    private function creditWelcomeFunds(User $user): void
    {
        $amount = (int) config('wallet.welcome_credit_minor');
        if (! config('wallet.simulated') || $amount <= 0) {
            return;
        }

        $this->ledger->creditSimulated(
            $this->ledger->walletFor($user),
            Money::ofMinor($amount, config('wallet.currency')),
            "welcome:user:{$user->id}",
            'Fonds fictifs de bienvenue',
        );
    }

    /**
     * Vérifie les identifiants. Même erreur et même coût (hachage) que le compte existe ou non,
     * pour ne pas permettre l'énumération des comptes. Chaque échec est audité (revue P8-02, F12).
     *
     * @param  'phone'|'email'  $field  identifiant déjà normalisé (téléphone sans indicatif, email en minuscules)
     *
     * @throws ApiException invalid_credentials | account_suspended | account_excluded
     */
    public function verifyCredentials(string $field, string $login, string $password): User
    {
        $user = User::where($field === 'email' ? 'email' : 'phone', $login)->first();

        // Hachage factice si le compte n'existe pas : le temps de réponse ne trahit pas son existence.
        $valid = Hash::check($password, $user?->password ?? $this->dummyHash());

        if ($user === null || ! $valid) {
            $this->recordFailure($user, $field, $login, $user === null ? 'unknown_account' : 'wrong_password');

            throw new ApiException('invalid_credentials', __('Identifiants invalides.'), 422);
        }

        // Le statut n'est révélé qu'après un mot de passe correct.
        if (! $user->status->canAuthenticate()) {
            $denied = $user->status->accessDenied();
            $this->recordFailure($user, $field, $login, $denied->errorCode);

            throw $denied;
        }

        return $user;
    }

    /** Connexion réussie (session ouverte) : date de dernière connexion et audit (revue P8-02, F12). */
    public function recordLogin(User $user): void
    {
        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record(AuditAction::LoginSucceeded, $user, actor: $user);
    }

    /**
     * Journal interne, réservé aux administrateurs (audit.view) : le compte visé s'il existe, le motif, l'IP et le
     * navigateur (ajoutés par AuditLogger). Jamais le mot de passe ; l'identifiant saisi est masqué. La réponse au client
     * reste la même : l'audit ne révèle rien à l'extérieur.
     */
    private function recordFailure(?User $user, string $field, string $login, string $reason): void
    {
        $this->audit->record(AuditAction::LoginFailed, $user, metadata: [
            'reason' => $reason,
            'field' => $field,
            'login' => self::maskLogin($field, $login),
        ]);
    }

    /** « ****3456 » pour un téléphone, « j***@example.com » pour un email. */
    public static function maskLogin(string $field, string $login): string
    {
        if ($field === 'email' && str_contains($login, '@')) {
            [$local, $domain] = explode('@', $login, 2);

            return mb_substr($local, 0, 1).'***@'.$domain;
        }

        return '****'.mb_substr($login, -4);
    }

    private function dummyHash(): string
    {
        static $hash;

        return $hash ??= Hash::make(Str::random(32));
    }
}
