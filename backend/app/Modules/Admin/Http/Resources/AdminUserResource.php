<?php

namespace App\Modules\Admin\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compte vu par un administrateur. Données minimales par défaut (Support : email et téléphone masqués, I2 / D-07) ;
 * coordonnées complètes, profil et rôles uniquement avec la permission users.view_personal_data.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /** "jean.dupont@gmail.com" → "je•••@gmail.com" */
    public static function maskEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return $email === null ? null : '•••';
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1))).'•••@'.$domain;
    }

    /** "37123456" → "•••• 3456" */
    public static function maskPhone(?string $phone): ?string
    {
        return $phone === null ? null : '•••• '.substr($phone, -4);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $full = $request->user()?->hasPermission('users.view_personal_data') ?? false;

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'phone' => $full ? $this->phone : self::maskPhone($this->phone),
            'email' => $full ? $this->email : self::maskEmail($this->email),
            'status' => $this->status->value,
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'is_admin' => $this->isAdmin(),
            'created_at' => $this->created_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            $this->mergeWhen($full, fn () => [
                'roles' => $this->roles->pluck('slug')->values(),
                'mfa_enabled' => $this->mfa_confirmed_at !== null,
                'profile' => [
                    'first_name' => $this->profile?->first_name,
                    'last_name' => $this->profile?->last_name,
                    'birth_date' => $this->profile?->birth_date?->toDateString(),
                    'country' => $this->profile?->country,
                ],
            ]),
        ];
    }
}
