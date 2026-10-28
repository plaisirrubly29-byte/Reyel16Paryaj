<?php

namespace App\Modules\IdentityAccess\Http\Requests;

use App\Modules\IdentityAccess\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Le format n'est pas revérifié ici : un numéro mal formé ne trouve simplement aucun
        // compte et tombe dans la même erreur "invalid_credentials" (anti-énumération).
        return [
            'phone' => ['required', 'string'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /** Même normalisation qu'à l'inscription, pour que le numéro saisi retrouve le compte stocké. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'))]);
        }
    }
}
