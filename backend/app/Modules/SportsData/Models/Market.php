<?php

namespace App\Modules\SportsData\Models;

use App\Modules\SportsData\Enums\MarketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property string $key
 * @property MarketStatus $status
 * @property string|null $suspension_reason
 * @property int $odds_version
 * @property string|null $source_bookmaker
 * @property Carbon|null $odds_updated_at
 */
class Market extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => MarketStatus::class,
            'odds_version' => 'integer',
            'odds_updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<Selection, $this> */
    public function selections(): HasMany
    {
        return $this->hasMany(Selection::class);
    }
}
