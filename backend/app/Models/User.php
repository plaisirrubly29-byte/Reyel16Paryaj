<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\IdentityAccess\Models\Role;
use App\Modules\IdentityAccess\Models\UserProfile;
use App\Modules\IdentityAccess\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property AccountStatus $status
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Le statut n'est pas "fillable" : il ne change que via les services du module IdentityAccess.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => AccountStatus::class,
            'status_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Lien de réinitialisation vers le frontend, envoyé en file d'attente.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /** @return HasOne<UserProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withPivot('assigned_by')->withTimestamps();
    }

    public function hasRole(RoleSlug|string $role): bool
    {
        $slug = $role instanceof RoleSlug ? $role->value : $role;

        return $this->roles->contains('slug', $slug);
    }

    /** Compte administratif (au moins un rôle admin) : MFA obligatoire. */
    public function isAdmin(): bool
    {
        return $this->roles->contains('is_admin', true);
    }

    /**
     * Permissions obtenues via les rôles (config/permissions.php).
     *
     * @return Collection<int, string>
     */
    public function permissionSlugs(): Collection
    {
        $this->loadMissing('roles.permissions');

        return $this->roles->flatMap(fn (Role $role) => $role->permissions->pluck('slug'))->unique()->values();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->permissionSlugs()->contains($permission);
    }
}
