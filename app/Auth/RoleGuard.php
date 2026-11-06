<?php

declare(strict_types=1);

namespace App\Auth;

use App\Domain\Errors\ForbiddenError;
use App\Enums\PermissionModule;
use App\Services\Actor;
use Illuminate\Support\Facades\DB;

/**
 * Who may hand out rights: a port of the old services/role-guard.ts. Roles
 * are an owner's decision; permissions can be passed on only downwards (you
 * can give what you hold). The actor is read from the database on every
 * check, never from the session, which may predate a demotion.
 */
final class RoleGuard
{
    public static function actorIsOwner(int $actorId): bool
    {
        $row = DB::table('users')->where('users.id', $actorId)->first(['role', 'is_active']);

        return $row !== null && (bool) $row->is_active && $row->role === 'OWNER';
    }

    public static function assertActorIsOwner(Actor $actor, string $what): void
    {
        if (! self::actorIsOwner($actor->id)) {
            throw new ForbiddenError("only an owner can {$what}");
        }
    }

    /** Refuse to grant any ticked box the actor does not hold. Unticked boxes are not escalation. */
    public static function assertPermissionsGrantable(Actor $actor, array $requested): void
    {
        if (self::actorIsOwner($actor->id)) {
            return;
        }
        $own = Principal::permissionMap($actor->id);
        foreach (PermissionModule::cases() as $module) {
            $want = $requested[$module->value] ?? null;
            if ($want === null) {
                continue;
            }
            $have = $own[$module->value] ?? Permissions::NO_ACCESS;
            foreach (['canView', 'canCreate', 'canEdit', 'canVoid'] as $flag) {
                if (($want[$flag] ?? false) && ! $have[$flag]) {
                    throw new ForbiddenError("only an owner can grant {$module->value} access, because you do not hold it yourself");
                }
            }
        }
    }
}
