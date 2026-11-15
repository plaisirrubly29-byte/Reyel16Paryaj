<?php

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Services\PaymentProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dépôt vu par le joueur. La page du prestataire (redirect_url) n'est donnée que tant que le dépôt attend sa
 * confirmation. Le motif d'une revue reste interne : le joueur voit seulement « review ».
 *
 * @mixin PaymentTransaction
 */
class DepositResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'provider' => $this->provider,
            'method' => $this->metadata['method'] ?? null,
            'provider_label' => app(PaymentProviderRegistry::class)->label($this->provider, $this->metadata['method'] ?? null),
            'status' => $this->status->value,
            'amount' => ['minor' => $this->amount_minor, 'currency' => $this->currency],
            'redirect_url' => $this->status === PaymentStatus::Pending ? ($this->metadata['redirect_url'] ?? null) : null,
            'created_at' => $this->created_at->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
