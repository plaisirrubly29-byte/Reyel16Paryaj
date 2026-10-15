<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\IdentityAccess\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Compte avec un statut donné (suspendu, exclu…).
     */
    public function withStatus(AccountStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
            'status_changed_at' => now(),
        ]);
    }

    /**
     * Compte administratif avec le rôle donné (les rôles doivent être seedés : RoleSeeder).
     */
    public function withRole(RoleSlug $role): static
    {
        return $this->afterCreating(function (User $user) use ($role) {
            $user->roles()->attach(Role::where('slug', $role->value)->firstOrFail());
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
