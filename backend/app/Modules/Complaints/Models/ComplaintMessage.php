<?php

namespace App\Modules\Complaints\Models;

use App\Models\User;
use App\Modules\Complaints\Enums\ComplaintActorType;
use App\Modules\Complaints\Enums\ComplaintMessageVisibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Message d'une réclamation : du joueur, réponse de l'équipe (visible du joueur) ou note interne.
 *
 * @property int $id
 * @property int $complaint_id
 * @property ComplaintActorType $author_type player | staff
 * @property int|null $author_id
 * @property ComplaintMessageVisibility $visibility
 * @property string $body
 * @property Carbon $created_at
 */
#[Fillable(['complaint_id', 'author_type', 'author_id', 'visibility', 'body'])]
class ComplaintMessage extends Model
{
    protected function casts(): array
    {
        return ['author_type' => ComplaintActorType::class, 'visibility' => ComplaintMessageVisibility::class];
    }

    /** @return BelongsTo<Complaint, $this> */
    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return HasMany<ComplaintAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(ComplaintAttachment::class)->orderBy('id');
    }
}
