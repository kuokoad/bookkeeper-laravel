<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles a user can hold. OWNER can do everything.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum UserRole: string
{
    use HasValues;

    case Owner = 'OWNER';
    case Staff = 'STAFF';
}
