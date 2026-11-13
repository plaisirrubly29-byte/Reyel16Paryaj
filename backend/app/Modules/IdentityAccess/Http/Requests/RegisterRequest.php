<?php

namespace App\Modules\IdentityAccess\Http\Requests;

use App\Modules\IdentityAccess\Support\PhoneNumber;
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
            // V2-E01 : case « J'accepte les conditions » cochée, pour la version des textes en vigueur.
            'terms_accepted' => ['required', 'accepted'],
            'terms_version' => ['required', 'string', 'in:'.config('identity.terms_version')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'birth_date.before_or_equal' => __('Vous devez avoir au moins :age ans pour ouvrir un compte.', ['age' => config('identity.minimum_age')]),
            'phone.regex' => __('Le numéro de téléphone doit comporter 8 chiffres (numéro haïtien local, sans l\'indicatif 509).'),
            'terms_accepted.required' => __('Vous devez accepter les conditions d\'utilisation et le règlement des paris.'),
            'terms_accepted.accepted' => __('Vous devez accepter les conditions d\'utilisation et le règlement des paris.'),
            'terms_version.in' => __('Les conditions ont été mises à jour : rechargez la page pour lire la nouvelle version.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper($this->input('country'))]);
        }

        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'))]);
        }
    }
}
