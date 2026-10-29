<?php

namespace App\Modules\Compliance\Models;

use App\Modules\Compliance\Enums\RestrictionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Pause ou auto-exclusion d'un joueur (P7-02). Irrévocable par le joueur ; seule la Conformité peut lever
 * une auto-exclusion arrivée à échéance (lifted_at).
 *
 * @property int $id
 * @property int $user_id
 * @property RestrictionType $type
 * @property string $duration
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $lifted_at
 */
class ResponsibleGamingRestriction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => RestrictionType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    /**
     * Restrictions en cours : pas levées et pas encore échues.
     *
     * @param  Builder<ResponsibleGamingRestriction>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('lifted_at')->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }
}
