<?php

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\WebhookOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Webhook reçu d'un prestataire (V2-B01), en ajout seul : corps brut, signature, résultat.
 *
 * @property int $id
 * @property string $provider
 * @property string|null $event_id null : signature invalide ou contenu illisible
 * @property int|null $payment_transaction_id
 * @property bool $signature_valid
 * @property WebhookOutcome $outcome
 * @property string $payload corps brut reçu
 * @property string|null $ip_address
 * @property Carbon $created_at
 */
#[Fillable(['provider', 'event_id', 'payment_transaction_id', 'signature_valid', 'outcome', 'payload', 'ip_address', 'created_at'])]
class WebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'outcome' => WebhookOutcome::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal des webhooks est en ajout seul.'));
        static::deleting(fn () => throw new LogicException('Le journal des webhooks est en ajout seul.'));
    }
}
