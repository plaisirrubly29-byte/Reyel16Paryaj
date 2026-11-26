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
 * @property float|null $live_home_rate buts attendus de l'équipe à domicile sur un match entier (modèle en direct)
 * @property float|null $live_away_rate buts attendus de l'équipe à l'extérieur sur un match entier
 * @property float|null $live_draw_boost coefficient des nuls de l'avant-match
 * @property Carbon|null $live_model_at heure des cotes sur lesquelles le modèle a été calé
 * @property Carbon|null $score_checked_at dernier score reçu du fournisseur pendant le match
 * @property Carbon|null $score_changed_at dernier changement de score vu
 * @property Carbon|null $hidden_at match masqué par un administrateur : retiré du site, plus pariable
 * @property string|null $hidden_reason
 * @property Carbon|null $admin_locked_at heure ou statut fixés par un administrateur : la synchronisation ne les remplace plus
 */
class Event extends Model
{
    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'competition_id', 'provider', 'external_id', 'home_team_id', 'away_team_id', 'starts_at', 'status',
        'home_score', 'away_score', 'result_received_at', 'last_synced_at', 'created_at', 'updated_at',
        'live_home_rate', 'live_away_rate', 'live_draw_boost', 'live_model_at', 'score_checked_at', 'score_changed_at',
        'hidden_at', 'hidden_reason', 'admin_locked_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'status' => EventStatus::class,
            'result_received_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'live_home_rate' => 'float',
            'live_away_rate' => 'float',
            'live_draw_boost' => 'float',
            'live_model_at' => 'datetime',
            'score_checked_at' => 'datetime',
            'score_changed_at' => 'datetime',
            'hidden_at' => 'datetime',
            'admin_locked_at' => 'datetime',
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
