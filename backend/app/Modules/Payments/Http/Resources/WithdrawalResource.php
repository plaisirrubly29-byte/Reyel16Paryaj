<?php

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Services\PayoutService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Retrait vu par le joueur (schéma Withdrawal du contrat V2-B03). Statuts du contrat : requested (montant réservé),
 * processing (envoyé au prestataire), paid, failed, cancelled, review. Numéro masqué sauf les 4 derniers chiffres ;
 * messages du prestataire et frais restent internes.
 *
 * @mixin PaymentTransaction
 */
class WithdrawalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $method = (string) ($this->metadata['method'] ?? '');
        $recipient = (string) ($this->metadata['recipient'] ?? '');

        return [
            'reference' => $this->reference,
            'provider' => $this->provider,
            'method' => $method,
            'method_label' => PayoutService::methodLabel($method),
            'recipient' => '****'.substr($recipient, -4),
            'status' => self::status($this->status),
            'amount' => ['minor' => $this->amount_minor, 'currency' => $this->currency],
            'cancellable' => $this->status === PaymentStatus::Pending,
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }

    public static function status(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Pending => 'requested',
            PaymentStatus::Processing => 'processing',
            PaymentStatus::Succeeded => 'paid',
            PaymentStatus::Failed, PaymentStatus::Expired => 'failed',
            PaymentStatus::Cancelled => 'cancelled',
            PaymentStatus::Review => 'review',
        };
    }
}
