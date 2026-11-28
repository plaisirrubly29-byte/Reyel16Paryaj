<?php

namespace App\Modules\Complaints\Http\Resources;

use App\Modules\Complaints\Models\Complaint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Réclamation dans une liste (schéma Complaint) : sans description, messages ni historique.
 *
 * @mixin Complaint
 */
class ComplaintResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'number' => $this->number,
            'category' => $this->category->value,
            'subject' => $this->subject,
            'status' => $this->status->value,
            'link' => $this->link_type === null ? null : [
                'type' => $this->link_type->value,
                'id' => (string) $this->link_id,
                'label' => $this->link_label,
            ],
            'resolution' => $this->resolution,
            'can_reopen' => $this->canReopen(),
            'last_activity_at' => $this->last_activity_at->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
