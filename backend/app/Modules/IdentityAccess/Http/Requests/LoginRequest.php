<?php

namespace App\Modules\IdentityAccess\Http\Requests;

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

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => $this->normalizePhone($this->input('phone'))]);
        }
    }

    /** Même normalisation qu'à l'inscription, pour que le numéro saisi retrouve le compte stocké. */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (strlen($digits) === 11 && str_starts_with($digits, '509')) {
            $digits = substr($digits, 3);
        }

        return $digits;
    }
}
