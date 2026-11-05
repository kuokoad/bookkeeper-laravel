<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an audit-log row records.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum AuditAction: string
{
    use HasValues;

    case LoginSuccess = 'LOGIN_SUCCESS';
    case LoginFailed = 'LOGIN_FAILED';
    case Logout = 'LOGOUT';
    case Create = 'CREATE';
    case Update = 'UPDATE';
    case Archive = 'ARCHIVE';
    case Restore = 'RESTORE';
    case Void = 'VOID';
    case Reverse = 'REVERSE';
    case Reconcile = 'RECONCILE';
    case PermissionChange = 'PERMISSION_CHANGE';
    case PasswordChange = 'PASSWORD_CHANGE';
    case SettingsChange = 'SETTINGS_CHANGE';
    case SeedDemo = 'SEED_DEMO';
    case PurgeDemo = 'PURGE_DEMO';
}
