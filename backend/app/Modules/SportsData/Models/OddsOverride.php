<?php

namespace App\Modules\SportsData\Models;

use App\Models\User;
use App\Modules\SportsData\Enums\OddsOverrideStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cote fixée à la main par l'administration (V2-D03, décision D-18). Voir OddsOverrideService.
 *
 * @property int $id
 * @property int $market_id
 * @property int $selection_id
 * @property OddsOverrideStatus $status
 * @property string $odds
 * @property string $computed_odds cote calculée au moment de la demande
 * @property bool $requires_approval écart de plus de odds.override_approval_threshold_percent avec la cote calculée
 * @property string $reason
 * @property Carbon $expires_at
 * @property int $requested_by
 * @property int|null $decided_by
 * @property string|null $decision_reason
 * @property Carbon|null $decided_at
 * @property int|null $ended_by
 * @property Carbon|null $ended_at
 * @property Carbon $created_at
 */
class OddsOverride extends Model
{
    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'market_id', 'selection_id', 'status', 'odds', 'computed_odds', 'requires_approval', 'reason', 'expires_at',
        'requested_by', 'decided_by', 'decision_reason', 'decided_at', 'ended_by', 'ended_at', 'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OddsOverrideStatus::class,
            'odds' => 'decimal:3',
            'computed_odds' => 'decimal:3',
            'requires_approval' => 'boolean',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** Écart avec la cote calculée, en pourcentage (2 décimales, signé). */
    public function deviationPercent(): string
    {
        return self::deviation((string) $this->odds, (string) $this->computed_odds);
    }

    public static function deviation(string $odds, string $computed): string
    {
        return bcmul(bcsub(bcdiv($odds, $computed, 8), '1', 8), '100', 2);
    }

    /** @return BelongsTo<Market, $this> */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /** @return BelongsTo<Selection, $this> */
    public function selection(): BelongsTo
    {
        return $this->belongsTo(Selection::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<User, $this> */
    public function ender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
