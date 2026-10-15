<?php

namespace App\Modules\Audit\Http\Resources;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'action' => $this->action->value,
            'actor' => $this->actor_id === null ? null : [
                'id' => (string) $this->actor_id,
                'email' => $this->actor_email,
            ],
            'subject' => $this->subject_type === null ? null : [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
            ],
            'before' => $this->before,
            'after' => $this->after,
            'reason' => $this->reason,
            'metadata' => $this->metadata,
            'ip_address' => $this->ip_address,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
