<?php

namespace App\Modules\IdentityAccess\Http\Requests;

use App\Modules\IdentityAccess\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/** Connexion par numéro de téléphone OU par email (les administrateurs utilisent souvent leur email). */
class LoginRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Le format n'est pas revérifié ici : un numéro ou un email mal formé ne trouve simplement aucun
        // compte et tombe dans la même erreur "invalid_credentials" (anti-énumération).
        return [
            'phone' => ['required_without:email', 'nullable', 'string', 'max:30'],
            'email' => ['required_without:phone', 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Identifiant de connexion : le téléphone s'il est fourni, sinon l'email.
     *
     * @return array{field: 'phone'|'email', value: string}
     */
    public function identifier(): array
    {
        $phone = (string) $this->validated('phone');

        return $phone !== ''
            ? ['field' => 'phone', 'value' => $phone]
            : ['field' => 'email', 'value' => (string) $this->validated('email')];
    }

    /** Mêmes normalisations qu'à l'inscription, pour que la saisie retrouve le compte stocké. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'))]);
        }

        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }
}
