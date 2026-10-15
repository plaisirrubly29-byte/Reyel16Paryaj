<?php

namespace App\Modules\Admin\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compte vu par un administrateur. Données minimales par défaut (Support) ;
 * profil et rôles uniquement avec la permission users.view_personal_data.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $full = $request->user()?->hasPermission('users.view_personal_data') ?? false;

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
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
                    'phone' => $this->profile?->phone,
                    'country' => $this->profile?->country,
                ],
            ]),
        ];
    }
}
