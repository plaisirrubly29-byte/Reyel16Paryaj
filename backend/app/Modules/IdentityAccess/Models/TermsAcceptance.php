<?php

namespace App\Modules\IdentityAccess\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Acceptation des textes d'information (V2-E01) : version acceptée, date, IP et navigateur.
 * Preuve juridique, en ajout seul : une nouvelle version des textes donnera une nouvelle ligne.
 *
 * @property int $user_id
 * @property string $version
 * @property Carbon $accepted_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
#[Fillable(['user_id', 'version', 'accepted_at', 'ip_address', 'user_agent'])]
class TermsAcceptance extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une acceptation des conditions ne se modifie jamais.'));
        static::deleting(fn () => throw new LogicException('Une acceptation des conditions ne se supprime jamais.'));
    }

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
