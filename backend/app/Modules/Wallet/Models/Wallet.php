<?php

namespace App\Modules\Wallet\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Portefeuille d'un utilisateur dans une devise. Il ne stocke PAS de solde :
 * le solde est la somme des écritures (LedgerService::balance).
 *
 * @property int $id
 * @property int $user_id
 * @property string $currency
 * @property string $status
 */
class Wallet extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_FROZEN = 'frozen';

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
