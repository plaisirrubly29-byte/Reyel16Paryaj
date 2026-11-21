<?php

namespace App\Modules\Settlement\Models;

use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Services\MarketKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Statistiques d'un match terminé (V2-D09) : score à la mi-temps, corners et cartons (jaunes + rouges) de chaque
 * équipe. Une ligne par match, en ajout seul. Écrire uniquement via StatisticsImportService.
 *
 * @property int $id
 * @property int $event_id
 * @property string $source
 * @property string|null $external_id
 * @property int|null $first_half_home
 * @property int|null $first_half_away
 * @property int|null $corners_home
 * @property int|null $corners_away
 * @property int|null $cards_home
 * @property int|null $cards_away
 * @property Carbon $created_at
 */
class MatchStatistics extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'match_statistics';

    protected $fillable = [
        'event_id', 'source', 'external_id', 'first_half_home', 'first_half_away',
        'corners_home', 'corners_away', 'cards_home', 'cards_away', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'first_half_home' => 'integer',
            'first_half_away' => 'integer',
            'corners_home' => 'integer',
            'corners_away' => 'integer',
            'cards_home' => 'integer',
            'cards_away' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Les statistiques d\'un match sont en ajout seul.'));
        static::deleting(fn () => throw new LogicException('Les statistiques d\'un match sont en ajout seul.'));
    }

    /**
     * Valeurs de chaque équipe pour une mesure (MarketKeys::FIRST_HALF, CORNERS, CARDS).
     *
     * @return array{0: int, 1: int}|null null si le fournisseur ne l'a pas donnée
     */
    public function pair(string $measure): ?array
    {
        [$home, $away] = match ($measure) {
            MarketKeys::FIRST_HALF, MarketKeys::HALFTIME_FULLTIME => [$this->first_half_home, $this->first_half_away],
            MarketKeys::CORNERS => [$this->corners_home, $this->corners_away],
            MarketKeys::CARDS => [$this->cards_home, $this->cards_away],
            default => [null, null],
        };

        return $home === null || $away === null ? null : [$home, $away];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
