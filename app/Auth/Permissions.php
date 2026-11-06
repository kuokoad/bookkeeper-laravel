<?php

declare(strict_types=1);

namespace App\Auth;

use App\Enums\PermissionModule;
use App\Enums\UserRole;

/**
 * "May this person do this?": the single authority, a port of the old
 * lib/auth/permissions.ts. The Gate abilities (AppServiceProvider) and the
 * permission middleware both ask here.
 */
final class Permissions
{
    public const ACTIONS = ['view', 'create', 'edit', 'void'];

    public const NO_ACCESS = ['canView' => false, 'canCreate' => false, 'canEdit' => false, 'canVoid' => false];

    public const FULL_ACCESS = ['canView' => true, 'canCreate' => true, 'canEdit' => true, 'canVoid' => true];

    public static function can(Principal $principal, PermissionModule $module, string $action): bool
    {
        if ($principal->role === UserRole::Owner) {
            return true;
        }
        $permission = $principal->permissions[$module->value] ?? null;
        if ($permission === null) {
            return false;
        }
        // Creating, editing or voiding all need sight of the module first.
        if ($action !== 'view' && ! $permission['canView']) {
            return false;
        }

        return match ($action) {
            'view' => $permission['canView'],
            'create' => $permission['canCreate'],
            'edit' => $permission['canEdit'],
            'void' => $permission['canVoid'],
            default => false,
        };
    }

    /** @return list<PermissionModule> the modules this person may open: the nav links */
    public static function visibleModules(Principal $principal): array
    {
        return array_values(array_filter(PermissionModule::cases(), fn (PermissionModule $m): bool => self::can($principal, $m, 'view')));
    }

    /** A new shop assistant: run the till, look up stock, nothing about money, reports, users or settings. */
    public static function defaultStaffPermissions(): array
    {
        return [
            'sales' => ['canView' => true, 'canCreate' => true, 'canEdit' => false, 'canVoid' => false],
            'products' => ['canView' => true, 'canCreate' => false, 'canEdit' => false, 'canVoid' => false],
            'inventory' => ['canView' => true, 'canCreate' => false, 'canEdit' => false, 'canVoid' => false],
            'customers' => ['canView' => true, 'canCreate' => true, 'canEdit' => false, 'canVoid' => false],
        ];
    }
}
