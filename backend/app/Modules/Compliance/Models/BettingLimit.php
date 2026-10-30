<?php

namespace App\Modules\Compliance\Models;

use App\Modules\Compliance\Enums\LimitPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Limite de mise d'un joueur sur une période glissante (P7-02).
 *
 * @property int $id
 * @property int $user_id
 * @property LimitPeriod $period
 * @property int|null $amount_minor null : aucune limite
 * @property int|null $pending_amount_minor hausse en attente (null + date : suppression en attente)
 * @property Carbon|null $pending_effective_at
 */
class BettingLimit extends Model
{
    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'user_id', 'period', 'amount_minor', 'pending_amount_minor', 'pending_effective_at', 'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'period' => LimitPeriod::class,
            'amount_minor' => 'integer',
            'pending_amount_minor' => 'integer',
            'pending_effective_at' => 'datetime',
        ];
    }

    public function hasPendingChange(): bool
    {
        return $this->pending_effective_at !== null;
    }
}
