<?php

declare(strict_types=1);

namespace App\Auth;

use App\Enums\PermissionModule;
use App\Enums\UserRole;
use App\Services\Actor;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in person and what they may do, as the old lib/auth/permissions
 * Principal. An owner carries no permission rows: the table does not apply.
 *
 * @phpstan-type ModulePermission array{canView: bool, canCreate: bool, canEdit: bool, canVoid: bool}
 */
final readonly class Principal
{
    /** @param  array<string, array{canView: bool, canCreate: bool, canEdit: bool, canVoid: bool}>  $permissions  keyed by module */
    public function __construct(
        public int $id,
        public string $username,
        public string $displayName,
        public UserRole $role,
        public array $permissions = [],
    ) {}

    /** Read fresh from the database: the rows, not anything the browser or an old session holds. */
    public static function load(int $userId): ?self
    {
        $user = DB::table('users')->where('users.id', $userId)->first();
        // Switched off means refused everywhere, even where no middleware stands guard.
        if ($user === null || ! (bool) $user->is_active) {
            return null;
        }
        $role = UserRole::from($user->role);

        return new self((int) $user->id, $user->username, $user->display_name, $role, $role === UserRole::Owner ? [] : self::permissionMap((int) $user->id));
    }

    /** @return array<string, array{canView: bool, canCreate: bool, canEdit: bool, canVoid: bool}> */
    public static function permissionMap(int $userId): array
    {
        $map = [];
        foreach (DB::table('user_permissions')->where('user_permissions.user_id', $userId)->get() as $row) {
            $map[$row->module] = [
                'canView' => (bool) $row->can_view,
                'canCreate' => (bool) $row->can_create,
                'canEdit' => (bool) $row->can_edit,
                'canVoid' => (bool) $row->can_void,
            ];
        }

        return $map;
    }

    public function can(PermissionModule $module, string $action): bool
    {
        return Permissions::can($this, $module, $action);
    }

    public function actor(): Actor
    {
        return new Actor($this->id, $this->username);
    }
}
