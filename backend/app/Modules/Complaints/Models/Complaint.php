<?php

namespace App\Modules\Complaints\Models;

use App\Models\User;
use App\Modules\Complaints\Enums\ComplaintCategory;
use App\Modules\Complaints\Enums\ComplaintLinkType;
use App\Modules\Complaints\Enums\ComplaintStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Réclamation d'un joueur (V2-G04). Statut changé seulement par ComplaintService ; jamais supprimée.
 *
 * @property int $id
 * @property string $number numéro lisible, ex. "REC-2026-000042"
 * @property int $user_id
 * @property ComplaintCategory $category
 * @property string $subject
 * @property string $description
 * @property ComplaintStatus $status
 * @property ComplaintLinkType|null $link_type
 * @property int|null $link_id
 * @property string|null $link_label référence lisible de l'objet concerné, figée à l'ouverture
 * @property int|null $assigned_to
 * @property string|null $resolution motif du dernier passage en resolved
 * @property Carbon|null $awaiting_staff_since dernier message du joueur sans réponse de l'équipe
 * @property Carbon $last_activity_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 */
#[Fillable([
    'number', 'user_id', 'category', 'subject', 'description', 'status', 'link_type', 'link_id', 'link_label',
    'assigned_to', 'resolution', 'awaiting_staff_since', 'last_activity_at', 'resolved_at', 'closed_at',
])]
class Complaint extends Model
{
    protected function casts(): array
    {
        return [
            'category' => ComplaintCategory::class,
            'status' => ComplaintStatus::class,
            'link_type' => ComplaintLinkType::class,
            'link_id' => 'integer',
            'awaiting_staff_since' => 'datetime',
            'last_activity_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** Réouverture possible : resolved ou closed, et dans le délai complaints.reopen_window_days après resolved. */
    public function canReopen(): bool
    {
        return ! $this->status->isOpen() && ! $this->reopenWindowExpired();
    }

    public function reopenWindowExpired(): bool
    {
        // Sans date de résolution (cas qui ne devrait pas arriver), le délai part de la fermeture.
        $since = $this->resolved_at ?? $this->closed_at;

        return $since !== null && $since->copy()->addDays((int) config('complaints.reopen_window_days'))->isPast();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<ComplaintMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ComplaintMessage::class)->orderBy('id');
    }

    /** @return HasMany<ComplaintAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(ComplaintAttachment::class)->orderBy('id');
    }

    /**
     * Pièces de l'ouverture (hors messages).
     *
     * @return HasMany<ComplaintAttachment, $this>
     */
    public function openingAttachments(): HasMany
    {
        return $this->attachments()->whereNull('complaint_message_id');
    }

    /** @return HasMany<ComplaintEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ComplaintEvent::class)->orderBy('id');
    }
}
