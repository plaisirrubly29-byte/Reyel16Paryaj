<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Settlement\Models\Result;
use App\Modules\Settlement\Models\ResultCorrection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Demande de correction de résultat (P5-06) vue par l'administration.
 *
 * @mixin ResultCorrection
 */
class ResultCorrectionResource extends JsonResource
{
    /** @var array<string, mixed>|null aperçu de l'impact, pour une demande en attente (détail seulement) */
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
        $event = $this->event;

        return [
            'id' => (string) $this->id,
            'event' => ['id' => (string) $event->id, 'name' => "{$event->homeTeam->name} – {$event->awayTeam->name}"],
            'status' => $this->status->value,
            'current' => self::outcome($this->basedOn),
            'proposed' => [
                'status' => $this->result_status->value,
                'score' => $this->home_score === null ? null : "{$this->home_score}-{$this->away_score}",
            ],
            'source' => $this->source,
            'reason' => $this->reason,
            // null : correction envoyée par le fournisseur après règlement
            'requested_by' => $this->requester === null ? null : ['id' => (string) $this->requester->id, 'name' => $this->requester->name],
            'decided_by' => $this->decider === null ? null : ['id' => (string) $this->decider->id, 'name' => $this->decider->name],
            'decision_reason' => $this->decision_reason,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'applied_result_id' => $this->applied_result_id === null ? null : (string) $this->applied_result_id,
            'impact' => $this->impact,
            'preview' => $this->when($this->preview !== null, fn () => $this->preview),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return array{version: int, status: string, score: ?string} */
    public static function outcome(Result $result): array
    {
        return [
            'version' => $result->version,
            'status' => $result->status->value,
            'score' => $result->home_score === null ? null : "{$result->home_score}-{$result->away_score}",
        ];
    }
}
