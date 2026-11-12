<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Modules\Payments\Enums\PaymentDirection;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Wallet\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Transaction chez un prestataire de paiement (V2-B01). Son statut ne change que par PaymentService
 * (webhook vérifié, expiration) ; l'effet financier est l'écriture ledger_entry_id.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property PaymentDirection $direction
 * @property PaymentStatus $status
 * @property int $amount_minor
 * @property string $currency
 * @property string $reference
 * @property string|null $provider_reference
 * @property int|null $ledger_entry_id
 * @property string|null $review_reason
 * @property Carbon|null $expires_at
 * @property Carbon|null $completed_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
#[Fillable([
    'user_id', 'provider', 'direction', 'status', 'amount_minor', 'currency', 'reference', 'provider_reference',
    'ledger_entry_id', 'review_reason', 'expires_at', 'completed_at', 'metadata',
])]
class PaymentTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'direction' => PaymentDirection::class,
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<LedgerEntry, $this> */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    /** @return HasMany<WebhookEvent, $this> */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }
}
