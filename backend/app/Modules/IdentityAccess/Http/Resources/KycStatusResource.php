<?php

namespace App\Modules\IdentityAccess\Http\Resources;

use App\Models\User;
use App\Modules\IdentityAccess\Enums\KycSubmissionStatus;
use App\Modules\IdentityAccess\Models\KycSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Statut KYC vu par le joueur (GET /me/kyc) : jamais les fichiers, ni qui a décidé.
 *
 * @mixin User
 */
class KycStatusResource extends JsonResource
{
    public function __construct(User $user, private readonly ?KycSubmission $latest)
    {
        parent::__construct($user);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $latest = $this->latest;

        return [
            'status' => $this->kyc_status->value,
            'verified_at' => $this->kyc_verified_at?->toIso8601String(),
            'deposit_threshold' => ['minor' => (int) config('kyc.deposit_threshold_minor'), 'currency' => (string) config('wallet.currency')],
            'latest_submission' => $latest === null ? null : [
                'id' => (string) $latest->id,
                'document_type' => $latest->document_type->value,
                'status' => $latest->status->value,
                'submitted_at' => $latest->created_at?->toIso8601String(),
                'decided_at' => $latest->decided_at?->toIso8601String(),
                // Motif d'un refus : le joueur sait quoi corriger. Celui d'une validation reste interne.
                'rejection_reason' => $latest->status === KycSubmissionStatus::Rejected ? $latest->decision_reason : null,
            ],
        ];
    }
}
