<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A person who signs in. Switched off with is_active, never deleted: their
 * sales and audit trail must stay attributable.
 */
#[Fillable(['username', 'display_name', 'role', 'password_hash', 'pin_hash', 'is_active', 'must_change_password'])]
#[Hidden(['password_hash', 'pin_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /** The column holding the password hash. */
    protected $authPasswordName = 'password_hash';

    /** No "remember me": sessions follow the old app's idle and absolute limits. */
    protected $rememberTokenName = '';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'failed_login_count' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }
}
