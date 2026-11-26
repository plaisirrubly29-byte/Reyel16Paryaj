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
            // Valeur enregistrée, lue telle quelle : une action inconnue de cette version (retour arrière après un
            // déploiement, ligne écrite par une autre branche) s'affiche en clair au lieu de faire tomber le journal.
            'action' => (string) $this->getRawOriginal('action'),
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
