<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Settlement\Models\TicketVoid;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Demande d'annulation d'un ticket (audit du 2026-11-22, A2) vue par l'administration.
 *
 * @mixin TicketVoid
 */
class TicketVoidResource extends JsonResource
{
    /** @var array<string, mixed>|null effet de la validation, pour une demande en attente (détail seulement) */
    private ?array $preview = null;

    /** @param  array<string, mixed>  $preview */
    public function withPreview(array $preview): static
    {
        $this->preview = $preview;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $ticket = $this->ticket;

        return [
            'id' => (string) $this->id,
            'ticket' => [
                'id' => (string) $ticket->id,
                'reference' => $ticket->reference,
                'status' => $ticket->status->value,
                'stake' => ['minor' => $ticket->stake_minor, 'currency' => $ticket->currency],
            ],
            'status' => $this->status->value,
            'ticket_status' => $this->ticket_status->value,
            'reason' => $this->reason,
            'requested_by' => ['id' => (string) $this->requested_by, 'name' => $this->requester?->name],
            'decided_by' => $this->decider === null ? null : ['id' => (string) $this->decider->id, 'name' => $this->decider->name],
            'decision_reason' => $this->decision_reason,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'impact' => $this->impact,
            'preview' => $this->when($this->preview !== null, fn () => $this->preview),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
