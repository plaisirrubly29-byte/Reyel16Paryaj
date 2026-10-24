<?php

namespace App\Modules\IdentityAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'regex:/^[0-9]{8}$/', 'unique:users,phone'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->subYears(config('identity.minimum_age'))->toDateString()],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'birth_date.before_or_equal' => __('Vous devez avoir au moins :age ans pour ouvrir un compte.', ['age' => config('identity.minimum_age')]),
            'phone.regex' => __('Le numéro de téléphone doit comporter 8 chiffres (numéro haïtien local, sans l\'indicatif 509).'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper($this->input('country'))]);
        }

        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => $this->normalizePhone($this->input('phone'))]);
        }
    }

    /**
     * Garde uniquement les chiffres, puis retire l'indicatif "509" s'il est présent
     * (saisie du type "+509 34 12 34 56" ou "509-3412-3456").
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (strlen($digits) === 11 && str_starts_with($digits, '509')) {
            $digits = substr($digits, 3);
        }

        return $digits;
    }
}
