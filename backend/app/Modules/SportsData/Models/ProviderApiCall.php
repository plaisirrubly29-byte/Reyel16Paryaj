<?php

namespace App\Modules\SportsData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Journal des appels au fournisseur : une ligne par réponse reçue (base du suivi des crédits).
 *
 * @property int $id
 * @property string $provider
 * @property string $endpoint
 * @property string|null $competition
 * @property int $cost
 * @property int|null $remaining
 * @property int|null $used
 * @property int $status
 * @property Carbon $called_at
 */
class ProviderApiCall extends Model
{
    public $timestamps = false;

    protected $table = 'sports_data_api_calls';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cost' => 'integer',
            'remaining' => 'integer',
            'used' => 'integer',
            'status' => 'integer',
            'called_at' => 'datetime',
        ];
    }
}
