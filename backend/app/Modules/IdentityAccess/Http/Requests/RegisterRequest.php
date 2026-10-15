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
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
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
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper($this->input('country'))]);
        }
    }
}
