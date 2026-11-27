<?php

namespace App\Modules\Complaints\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pièce jointe : chemin sur le disque privé (contenu chiffré), nom d'origine nettoyé, type, taille et empreinte.
 *
 * @property int $id
 * @property int $complaint_id
 * @property int|null $complaint_message_id null : pièce de l'ouverture
 * @property string $path
 * @property string $filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 */
#[Fillable(['complaint_id', 'complaint_message_id', 'path', 'filename', 'mime_type', 'size_bytes', 'sha256'])]
#[Hidden(['path'])]
class ComplaintAttachment extends Model
{
    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    /** @return BelongsTo<Complaint, $this> */
    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    /** @return BelongsTo<ComplaintMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ComplaintMessage::class, 'complaint_message_id');
    }
}
