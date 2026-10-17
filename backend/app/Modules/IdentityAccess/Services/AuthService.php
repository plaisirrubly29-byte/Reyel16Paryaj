<?php

namespace App\Modules\IdentityAccess\Services;

use App\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    /**
     * Crée un compte joueur actif (sans rôle) et son profil.
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

            return $user;
        });
    }

    /**
     * Vérifie les identifiants. Même erreur et même coût (hachage) que le compte existe ou non,
     * pour ne pas permettre l'énumération des comptes.
     *
     * @throws ApiException invalid_credentials | account_suspended
     */
    public function verifyCredentials(string $phone, string $password): User
    {
        $user = User::where('phone', $phone)->first();

        // Hachage factice si le compte n'existe pas : le temps de réponse ne trahit pas son existence.
        $valid = Hash::check($password, $user?->password ?? $this->dummyHash());

        if ($user === null || ! $valid) {
            throw new ApiException('invalid_credentials', __('Identifiants invalides.'), 422);
        }

        // Le statut n'est révélé qu'après un mot de passe correct.
        if (! $user->status->canAuthenticate()) {
            throw new ApiException('account_suspended', __('Ce compte est suspendu ou fermé. Contactez le support.'), 423);
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
