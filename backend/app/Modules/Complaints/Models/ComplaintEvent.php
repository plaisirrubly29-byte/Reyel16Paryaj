<?php

namespace App\Modules\Complaints\Models;

use App\Models\User;
use App\Modules\Complaints\Enums\ComplaintActorType;
use App\Modules\Complaints\Enums\ComplaintEventType;
use App\Modules\Complaints\Enums\ComplaintStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Entrée de l'historique d'une réclamation. En ajout seul : toute modification ou suppression lève une exception.
 *
 * @property int $id
 * @property int $complaint_id
 * @property ComplaintEventType $type
 * @property ComplaintStatus|null $from_status
 * @property ComplaintStatus|null $to_status
 * @property string|null $reason
 * @property ComplaintActorType $actor_type
 * @property int|null $actor_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
#[Fillable(['complaint_id', 'type', 'from_status', 'to_status', 'reason', 'actor_type', 'actor_id', 'metadata'])]
class ComplaintEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => ComplaintEventType::class,
            'from_status' => ComplaintStatus::class,
            'to_status' => ComplaintStatus::class,
            'actor_type' => ComplaintActorType::class,
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException("L'historique d'une réclamation est en ajout seul."));
        static::deleting(fn () => throw new LogicException("L'historique d'une réclamation est en ajout seul."));
    }

    /** @return BelongsTo<Complaint, $this> */
    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Le motif est montré au joueur seulement pour une résolution ou une réouverture. */
    public function reasonVisibleToPlayer(): bool
    {
        return $this->type === ComplaintEventType::Reopened
            || ($this->type === ComplaintEventType::StatusChanged && $this->to_status === ComplaintStatus::Resolved);
    }
}
