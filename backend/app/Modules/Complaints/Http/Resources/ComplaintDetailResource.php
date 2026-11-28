<?php

namespace App\Modules\Complaints\Http\Resources;

use App\Modules\Complaints\Enums\ComplaintActorType;
use App\Modules\Complaints\Enums\ComplaintMessageVisibility;
use App\Modules\Complaints\Models\Complaint;
use App\Modules\Complaints\Models\ComplaintAttachment;
use App\Modules\Complaints\Models\ComplaintEvent;
use App\Modules\Complaints\Models\ComplaintMessage;
use Illuminate\Http\Request;

/**
 * Vue du joueur (schéma ComplaintDetail) : ni notes internes, ni événements assigned ou note_added, ni identité des
 * administrateurs (l'équipe signe « Service client »). Motif d'historique seulement pour resolved et reopened.
 *
 * @mixin Complaint
 */
class ComplaintDetailResource extends ComplaintResource
{
    public const STAFF_NAME = 'Service client';

    public const SYSTEM_NAME = 'Système';

    /** Relations à charger avant de rendre la ressource. */
    public const RELATIONS = ['user', 'openingAttachments', 'messages.attachments', 'events'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $playerName = (string) $this->user->name;

        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'attachments' => $this->openingAttachments->map(fn (ComplaintAttachment $a) => self::attachment($a))->values(),
            'messages' => $this->messages
                ->filter(fn (ComplaintMessage $m) => $m->visibility === ComplaintMessageVisibility::Player)
                ->map(fn (ComplaintMessage $m) => [
                    'id' => (string) $m->id,
                    'author' => [
                        'type' => $m->author_type->value,
                        'name' => $m->author_type === ComplaintActorType::Player ? $playerName : self::STAFF_NAME,
                    ],
                    'body' => $m->body,
                    'attachments' => $m->attachments->map(fn (ComplaintAttachment $a) => self::attachment($a))->values(),
                    'created_at' => $m->created_at->toIso8601String(),
                ])->values(),
            'history' => $this->events
                ->filter(fn (ComplaintEvent $e) => $e->type->visibleToPlayer())
                ->map(fn (ComplaintEvent $e) => [
                    'id' => (string) $e->id,
                    'type' => $e->type->value,
                    'from_status' => $e->from_status?->value,
                    'to_status' => $e->to_status?->value,
                    'reason' => $e->reasonVisibleToPlayer() ? $e->reason : null,
                    'actor' => [
                        'type' => $e->actor_type->value,
                        'name' => match ($e->actor_type) {
                            ComplaintActorType::Player => $playerName,
                            ComplaintActorType::Staff => self::STAFF_NAME,
                            ComplaintActorType::System => self::SYSTEM_NAME,
                        },
                    ],
                    'created_at' => $e->created_at->toIso8601String(),
                ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    public static function attachment(ComplaintAttachment $attachment): array
    {
        return [
            'id' => (string) $attachment->id,
            'filename' => $attachment->filename,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes,
        ];
    }
}
