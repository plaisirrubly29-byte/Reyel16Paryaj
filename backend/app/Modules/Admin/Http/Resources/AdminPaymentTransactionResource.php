<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transaction d'un prestataire de paiement vue par l'administration (V2-B01). Les webhooks reçus (corps brut compris)
 * ne sont renvoyés que si la relation webhookEvents est chargée (détail).
 *
 * @mixin PaymentTransaction
 */
class AdminPaymentTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'user_id' => (string) $this->user_id,
            'provider' => $this->provider,
            'direction' => $this->direction->value,
            'status' => $this->status->value,
            'amount' => ['minor' => $this->amount_minor, 'currency' => $this->currency],
            'reference' => $this->reference,
            'provider_reference' => $this->provider_reference,
            'ledger_entry_id' => $this->ledger_entry_id === null ? null : (string) $this->ledger_entry_id,
            'review_reason' => $this->review_reason,
            'reported' => $this->metadata['reported'] ?? null,
            'late' => (bool) ($this->metadata['late'] ?? false),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'webhooks' => $this->whenLoaded('webhookEvents', fn () => $this->webhookEvents->map(fn (WebhookEvent $event) => [
                'id' => (string) $event->id,
                'event_id' => $event->event_id,
                'signature_valid' => $event->signature_valid,
                'outcome' => $event->outcome->value,
                'payload' => $event->payload,
                'ip_address' => $event->ip_address,
                'created_at' => $event->created_at->toIso8601String(),
            ])->values()),
        ];
    }
}
