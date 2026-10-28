<?php

namespace App\Modules\Wallet\Models;

use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Écriture du ledger. Append-only : modification et suppression interdites (exception côté modèle,
 * trigger côté PostgreSQL). Écrire uniquement via App\Modules\Wallet\Services\LedgerService.
 *
 * @property int $id
 * @property int $wallet_id
 * @property LedgerEntryType $type
 * @property int $amount_minor
 * @property string $currency
 * @property int $balance_after_minor
 * @property string $reference
 * @property int|null $reversal_of_id
 * @property string|null $related_type
 * @property string|null $related_id
 * @property string|null $description
 * @property array<string, mixed>|null $metadata
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le ledger est en ajout seul : utiliser une écriture compensatoire.'));
        static::deleting(fn () => throw new LogicException('Le ledger est en ajout seul : utiliser une écriture compensatoire.'));
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    public function balanceAfter(): Money
    {
        return Money::ofMinor($this->balance_after_minor, $this->currency);
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** @return BelongsTo<LedgerEntry, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** @return HasOne<LedgerEntry, $this> */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
