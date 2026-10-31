<?php

namespace App\Modules\SportsData\Models;

use App\Modules\SportsData\Enums\EventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Match synchronisé depuis le fournisseur.
 *
 * @property int $id
 * @property int $competition_id
 * @property string $provider
 * @property string $external_id
 * @property Carbon $starts_at
 * @property EventStatus $status
 * @property string|null $home_score
 * @property string|null $away_score
 * @property Carbon|null $last_synced_at
 */
class Event extends Model
{
    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'competition_id', 'provider', 'external_id', 'home_team_id', 'away_team_id', 'starts_at', 'status',
        'home_score', 'away_score', 'result_received_at', 'last_synced_at', 'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'status' => EventStatus::class,
            'result_received_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Competition, $this> */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    /** @return BelongsTo<Team, $this> */
    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    /** @return HasMany<Market, $this> */
    public function markets(): HasMany
    {
        return $this->hasMany(Market::class);
    }
}
