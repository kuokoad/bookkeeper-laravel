<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $passwordHash = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'display_name' => fake()->name(),
            'role' => UserRole::Staff->value,
            'password_hash' => static::$passwordHash ??= Hash::make('test-password-2026'),
            'is_active' => true,
            'must_change_password' => false,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Owner->value]);
    }
}
