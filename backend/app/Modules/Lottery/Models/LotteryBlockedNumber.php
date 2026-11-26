<?php

namespace App\Modules\Lottery\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Boule bloquée par l'administration (L-3) : plus vendue tant que le blocage n'est pas levé. Jamais effacée.
 *
 * @property int $id
 * @property string $number
 * @property int|null $lottery_draw_id
 * @property string|null $draw_date
 * @property string $reason
 * @property int $blocked_by
 * @property int|null $lifted_by
 * @property Carbon|null $lifted_at
 * @property string|null $lift_reason
 * @property Carbon $created_at
 */
class LotteryBlockedNumber extends Model
{
    protected $fillable = ['number', 'lottery_draw_id', 'draw_date', 'reason', 'blocked_by', 'lifted_by', 'lifted_at', 'lift_reason'];

    protected function casts(): array
    {
        return ['lifted_at' => 'datetime'];
    }

    /** @return BelongsTo<LotteryDraw, $this> */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(LotteryDraw::class, 'lottery_draw_id');
    }

    /** @return BelongsTo<User, $this> */
    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    /** @return BelongsTo<User, $this> */
    public function lifter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifted_by');
    }
}
