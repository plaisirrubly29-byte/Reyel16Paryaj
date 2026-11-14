<?php

namespace App\Modules\IdentityAccess\Models;

use App\Models\User;
use App\Modules\IdentityAccess\Enums\KycDocumentType;
use App\Modules\IdentityAccess\Enums\KycSubmissionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Dossier KYC (V2-C03) : une pièce d'identité et un selfie, puis la décision d'un administrateur.
 * Un nouveau dossier après un refus est une nouvelle ligne : l'historique reste complet.
 *
 * @property int $user_id
 * @property KycDocumentType $document_type
 * @property KycSubmissionStatus $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 */
#[Fillable(['user_id', 'document_type', 'status', 'decided_by', 'decided_at', 'decision_reason'])]
class KycSubmission extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => KycDocumentType::class,
            'status' => KycSubmissionStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return HasMany<KycFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(KycFile::class);
    }
}
