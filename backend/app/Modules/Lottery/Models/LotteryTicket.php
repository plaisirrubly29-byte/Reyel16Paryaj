<?php

namespace App\Modules\Lottery\Models;

use App\Models\User;
use App\Modules\Lottery\Enums\LotteryTicketStatus;
use App\Modules\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Fiche de borlette vendue (V2-D09) : un tirage, une date, des lignes. Reçu immuable : seuls le statut, les gains et la
 * date de règlement changent.
 *
 * @property int $id
 * @property string $reference
 * @property string|null $placement_key
 * @property int $user_id
 * @property int $wallet_id
 * @property int $lottery_draw_id
 * @property string $draw_date
 * @property Carbon $draw_at
 * @property LotteryTicketStatus $status
 * @property int $stake_minor
 * @property int $max_payout_minor
 * @property int|null $payout_minor
 * @property string $currency
 * @property string $rules_version
 * @property array<string, mixed> $rules_snapshot
 * @property Carbon $placed_at
 * @property Carbon|null $settled_at
 */
class LotteryTicket extends Model
{
    private const REFERENCE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const REFERENCE_LENGTH = 10;

    /** Conditions acceptées à la vente : jamais modifiées ensuite. */
    private const FROZEN = [
        'reference', 'user_id', 'wallet_id', 'lottery_draw_id', 'draw_date', 'draw_at', 'stake_minor', 'max_payout_minor',
        'currency', 'rules_version', 'rules_snapshot', 'placed_at',
    ];

    protected $fillable = [
        'reference', 'placement_key', 'user_id', 'wallet_id', 'lottery_draw_id', 'draw_date', 'draw_at', 'status',
        'stake_minor', 'max_payout_minor', 'payout_minor', 'currency', 'rules_version', 'rules_snapshot', 'placed_at',
        'settled_at',
    ];

    protected function casts(): array
    {
        // draw_date reste une chaîne "YYYY-MM-DD" (voir LotteryResult).
        return [
            'status' => LotteryTicketStatus::class,
            'draw_at' => 'datetime',
            'stake_minor' => 'integer',
            'max_payout_minor' => 'integer',
            'payout_minor' => 'integer',
            'rules_snapshot' => 'array',
            'placed_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $ticket) {
            $ticket->reference ??= self::newReference();
            $ticket->placed_at ??= now();
        });

        static::updating(function (self $ticket) {
            if ($ticket->isDirty(self::FROZEN)) {
                throw new LogicException('Les conditions acceptées d\'une fiche de borlette sont immuables.');
            }
        });

        static::deleting(fn () => throw new LogicException('Une fiche de borlette ne peut pas être supprimée.'));
    }

    public static function newReference(): string
    {
        $code = '';
        for ($i = 0; $i < self::REFERENCE_LENGTH; $i++) {
            $code .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
        }

        return 'B16-'.$code;
    }

    /** @return HasMany<LotteryPlay, $this> */
    public function plays(): HasMany
    {
        return $this->hasMany(LotteryPlay::class)->orderBy('position');
    }

    /** @return BelongsTo<LotteryDraw, $this> */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(LotteryDraw::class, 'lottery_draw_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** Résultat publié du tirage de la fiche, s'il existe. */
    public function result(): ?LotteryResult
    {
        return LotteryResult::where('lottery_draw_id', $this->lottery_draw_id)->where('draw_date', $this->draw_date)->first();
    }
}
