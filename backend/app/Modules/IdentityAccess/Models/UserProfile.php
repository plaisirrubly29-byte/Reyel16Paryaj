<?php

namespace App\Modules\IdentityAccess\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Données personnelles du joueur, séparées du compte (accès restreint selon le rôle).
 *
 * @property int $user_id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property Carbon|null $birth_date
 * @property string|null $phone
 * @property string|null $country
 */
#[Fillable(['first_name', 'last_name', 'birth_date', 'phone', 'country'])]
class UserProfile extends Model
{
    protected function casts(): array
    {
        return ['birth_date' => 'date'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
