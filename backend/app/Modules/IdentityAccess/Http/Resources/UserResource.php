<?php

namespace App\Modules\IdentityAccess\Http\Resources;

use App\Models\User;
use App\Modules\IdentityAccess\Services\MfaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Utilisateur connecté (GET /me). Ne jamais réutiliser pour l'admin : les données y dépendent du rôle.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
            'roles' => $this->roles->pluck('slug')->values(),
            'is_admin' => $this->isAdmin(),
            // Pour le frontend : rediriger un admin vers l'activation ou la saisie du code MFA.
            'mfa' => [
                'enabled' => $this->mfa_confirmed_at !== null,
                'verified' => app(MfaService::class)->isVerified($this->resource, $request->hasSession() ? $request->session() : null),
                'required' => $this->isAdmin(),
            ],
            'profile' => $this->whenLoaded('profile', fn () => [
                'first_name' => $this->profile?->first_name,
                'last_name' => $this->profile?->last_name,
                'birth_date' => $this->profile?->birth_date?->toDateString(),
                'phone' => $this->profile?->phone,
                'country' => $this->profile?->country,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
