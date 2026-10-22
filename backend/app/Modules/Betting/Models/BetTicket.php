<?php

namespace App\Modules\Betting\Models;

use App\Models\User;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Enums\BetType;
use App\Modules\Wallet\Models\Wallet;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Ticket de pari accepté. Les conditions acceptées (mise, cotes, règles) sont figées : seul le règlement
 * change le statut et le gain. Un ticket n'est jamais supprimé.
 *
 * @property int $id
 * @property string $reference référence publique du reçu, ex. "R16-7KQ2M9XAPB"
 * @property int $user_id
 * @property int $wallet_id
 * @property BetType $type
 * @property BetTicketStatus $status
 * @property int $stake_minor
 * @property string $currency
 * @property string $total_odds cote totale (chaîne, ex. "3.075")
 * @property int $potential_payout_minor
 * @property int|null $payout_minor
 * @property string $rules_version
 * @property array<string, mixed> $rules_snapshot
 * @property Carbon $placed_at
 * @property Carbon|null $settled_at
 */
class BetTicket extends Model
{
    /** Sans 0/O ni 1/I/L : la référence se dicte au téléphone sans ambiguïté. */
    private const REFERENCE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const REFERENCE_LENGTH = 10;

    /** Conditions acceptées par le joueur : aucune modification après création. */
    private const FROZEN = [
        'reference', 'user_id', 'wallet_id', 'type', 'stake_minor', 'currency', 'total_odds',
        'potential_payout_minor', 'rules_version', 'rules_snapshot', 'placed_at',
    ];

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'type' => BetType::class,
            'status' => BetTicketStatus::class,
            'stake_minor' => 'integer',
            'total_odds' => 'decimal:3',
            'potential_payout_minor' => 'integer',
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
                throw new LogicException('Les conditions acceptées d\'un ticket sont immuables.');
            }
        });

        static::deleting(fn () => throw new LogicException('Un ticket de pari ne peut pas être supprimé.'));
    }

    public static function newReference(): string
    {
        $code = '';
        for ($i = 0; $i < self::REFERENCE_LENGTH; $i++) {
            $code .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
        }

        return 'R16-'.$code;
    }

    public function stake(): Money
    {
        return Money::ofMinor($this->stake_minor, $this->currency);
    }

    public function potentialPayout(): Money
    {
        return Money::ofMinor($this->potential_payout_minor, $this->currency);
    }

    public function payout(): ?Money
    {
        return $this->payout_minor === null ? null : Money::ofMinor($this->payout_minor, $this->currency);
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

    /** @return HasMany<BetSelection, $this> */
    public function selections(): HasMany
    {
        return $this->hasMany(BetSelection::class)->orderBy('id');
    }
}
