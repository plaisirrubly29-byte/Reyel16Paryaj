<?php

namespace App\Modules\Betting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /bets : sélections choisies (identifiant + cote vue par le joueur) et mise.
 * La cote envoyée sert seulement à détecter un changement : le serveur applique toujours la sienne.
 */
class PlaceBetRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'stake' => ['required', 'array:minor,currency'],
            'stake.minor' => ['required', 'integer', 'min:1'],
            'stake.currency' => ['nullable', 'string', 'size:3'],
            'selections' => ['required', 'array', 'min:1', 'max:'.config('betting.max_selections')],
            'selections.*' => ['required', 'array:selection_id,odds'],
            'selections.*.selection_id' => ['required', 'integer', 'min:1', 'distinct'],
            'selections.*.odds' => ['required', 'string', 'regex:/^\d{1,5}(\.\d{1,3})?$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'selections.max' => __('Un ticket compte :max sélections au plus.'),
            'selections.*.selection_id.distinct' => __('Une même sélection ne peut figurer qu\'une fois.'),
        ];
    }
}
