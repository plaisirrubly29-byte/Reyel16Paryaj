<?php

namespace App\Modules\IdentityAccess\Services;

use App\Models\User;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Http\ApiException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Crée un compte joueur actif (sans rôle), son profil et, en V1 (fonds fictifs), son crédit de bienvenue.
     *
     * @param  array{name: string, phone: string, email?: string|null, password: string, birth_date: string, country?: string|null}  $data
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
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
     * pour ne pas permettre l'énumération des comptes.
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
            throw new ApiException('invalid_credentials', __('Identifiants invalides.'), 422);
        }

        // Le statut n'est révélé qu'après un mot de passe correct.
        if (! $user->status->canAuthenticate()) {
            throw $user->status->accessDenied();
        }

        return $user;
    }

    public function recordLogin(User $user): void
    {
        $user->forceFill(['last_login_at' => now()])->save();
    }

    private function dummyHash(): string
    {
        static $hash;

        return $hash ??= Hash::make(Str::random(32));
    }
}
