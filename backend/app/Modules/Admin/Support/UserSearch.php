<?php

namespace App\Modules\Admin\Support;

use App\Modules\IdentityAccess\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;

/** Recherche de comptes admin : nom ou email (fragment), téléphone complet, ou numéro de compte. */
final class UserSearch
{
    /**
     * @param  Builder<*>  $query
     * @param  bool  $emailFragments  false sans users.view_personal_data : email complet seulement (I2, D-07),
     *                                pour qu'une recherche par fragments ne révèle pas les adresses masquées
     * @return Builder<*>
     */
    public static function apply(Builder $query, string $search, bool $emailFragments = true): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($search)).'%';
        // Téléphone : numéro complet uniquement (même normalisation qu'à la connexion),
        // pour ne pas mélanger une recherche par identifiant avec des fragments de numéro.
        $phone = PhoneNumber::normalize($search);

        return $query->where(fn ($q) => $q
            ->when($emailFragments,
                fn ($q) => $q->whereRaw("LOWER(email) LIKE ? ESCAPE '\\'", [$like]),
                fn ($q) => $q->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($search))]))
            ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])
            ->when(strlen($phone) === 8 && ctype_digit($phone), fn ($q) => $q->orWhere('phone', $phone))
            ->when(ctype_digit($search), fn ($q) => $q->orWhere('id', (int) $search)));
    }
}
