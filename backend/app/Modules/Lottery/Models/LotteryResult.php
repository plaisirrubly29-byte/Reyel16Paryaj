<?php

namespace App\Modules\Lottery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Résultat publié d'un tirage, un jour donné. Jamais écrasé par la synchro (voir LotterySyncService).
 *
 * @property int $id
 * @property int $lottery_draw_id
 * @property string $draw_date "YYYY-MM-DD", date locale du tirage
 * @property string $lot1
 * @property string $lot2
 * @property string $lot3
 * @property string|null $pick3
 * @property string|null $pick4
 * @property string $source
 * @property string|null $external_id
 * @property Carbon|null $published_at
 * @property Carbon $created_at
 */
class LotteryResult extends Model
{
    protected $fillable = [
        'lottery_draw_id', 'draw_date', 'lot1', 'lot2', 'lot3', 'pick3', 'pick4', 'source', 'external_id', 'published_at',
    ];

    protected function casts(): array
    {
        // draw_date reste une chaîne "YYYY-MM-DD" : un cast date l'écrirait avec une heure et fausserait les
        // comparaisons en SQLite.
        return ['published_at' => 'datetime'];
    }

    /** @return BelongsTo<LotteryDraw, $this> */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(LotteryDraw::class, 'lottery_draw_id');
    }

    /** @return array{string, string, string} */
    public function lots(): array
    {
        return [$this->lot1, $this->lot2, $this->lot3];
    }
}
