<?php

namespace App\Modules\IdentityAccess\Models;

use App\Modules\IdentityAccess\Enums\KycFileKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fichier d'un dossier KYC : chemin sur le disque privé (contenu chiffré), type, taille et empreinte d'origine.
 *
 * @property int $kyc_submission_id
 * @property KycFileKind $kind
 * @property string $path
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 */
#[Fillable(['kyc_submission_id', 'kind', 'path', 'mime_type', 'size_bytes', 'sha256'])]
#[Hidden(['path'])]
class KycFile extends Model
{
    protected function casts(): array
    {
        return ['kind' => KycFileKind::class, 'size_bytes' => 'integer'];
    }

    /** @return BelongsTo<KycSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(KycSubmission::class, 'kyc_submission_id');
    }
}
