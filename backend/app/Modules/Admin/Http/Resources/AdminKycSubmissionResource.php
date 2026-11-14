<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\IdentityAccess\Models\KycFile;
use App\Modules\IdentityAccess\Models\KycSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dossier KYC vu par un administrateur kyc.view : identité déclarée à comparer avec la pièce, fichiers (sans leur
 * chemin ; contenu par GET …/files/{file}, audité) et décision.
 *
 * @mixin KycSubmission
 */
class AdminKycSubmissionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'user' => [
                'id' => (string) $this->user_id,
                'name' => $this->user->name,
                'first_name' => $this->user->profile?->first_name,
                'last_name' => $this->user->profile?->last_name,
                'birth_date' => $this->user->profile?->birth_date?->toDateString(),
                'country' => $this->user->profile?->country,
                'kyc_status' => $this->user->kyc_status->value,
            ],
            'document_type' => $this->document_type->value,
            'status' => $this->status->value,
            'files' => $this->whenLoaded('files', fn () => $this->files->map(fn (KycFile $file) => [
                'id' => (string) $file->id,
                'kind' => $file->kind->value,
                'mime_type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
            ])->values()),
            'decided_by' => $this->decider === null ? null : ['id' => (string) $this->decider->id, 'name' => $this->decider->name],
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_reason' => $this->decision_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
