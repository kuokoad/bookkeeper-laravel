<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Auth\Permissions;
use App\Auth\Principal;
use App\Enums\PermissionModule;
use App\Enums\UserRole;
use App\Services\UserService;

/**
 * The permission matrix: the old shared/permission-matrix.tsx. "See" gates
 * everything else (ticking Add, Change or Void ticks See; unticking See
 * clears the row), matching Permissions::can. A non-owner may only pass on
 * what they hold, so those boxes are shown disabled. The service refuses
 * either way; this only keeps the form from offering what cannot work.
 */
trait EditsPermissions
{
    /** Plain-language names: the old shared/modules.ts MODULE_ROWS. */
    public const MODULE_ROWS = [
        'sales' => ['Sales', 'Use the till and see past sales'],
        'purchases' => ['Purchases', 'Record deliveries from suppliers'],
        'products' => ['Stock items', 'The list of what you sell, and prices'],
        'inventory' => ['Inventory', 'Stock levels and adjustments'],
        'customers' => ['Customers', 'Customer records and their debts'],
        'suppliers' => ['Suppliers', 'Supplier records and what you owe'],
        'expenses' => ['Expenses', 'Money spent running the shop'],
        'income' => ['Other income', 'Money in that is not a sale'],
        'accounts' => ['Accounts', 'Cash, MoMo and bank balances'],
        'reconciliation' => ['Reconciliation', 'Counting cash against the books'],
        'quotations' => ['Quotations', 'Prepare priced quotes for customers'],
        'reports' => ['Reports', 'Profit, balance sheet and the dashboard'],
        'users' => ['Users', 'Staff accounts and what they can do'],
        'settings' => ['Settings', 'Shop settings and closing the books'],
    ];

    public const SENSITIVE = ['users', 'settings', 'accounts', 'reconciliation'];

    private const FLAGS = ['canView', 'canCreate', 'canEdit', 'canVoid'];

    /** @var array<string, array{canView: bool, canCreate: bool, canEdit: bool, canVoid: bool}> */
    public array $perms = [];

    /** @param  array<string, array<string, bool>>  $initial */
    protected function fillPermissions(array $initial): void
    {
        $this->perms = UserService::emptyPermissionMatrix();
        foreach ($initial as $module => $p) {
            if (isset($this->perms[$module])) {
                $this->perms[$module] = array_map(fn ($v): bool => (bool) $v, array_intersect_key($p, array_flip(self::FLAGS))) + Permissions::NO_ACCESS;
            }
        }
    }

    public function updatedPerms(mixed $value, string $key): void
    {
        [$module, $flag] = array_pad(explode('.', $key, 2), 2, null);
        if (! isset($this->perms[$module]) || ! in_array($flag, self::FLAGS, true)) {
            return;
        }
        if ($value && $flag !== 'canView') {
            $this->perms[$module]['canView'] = true;
        }
        if (! $value && $flag === 'canView') {
            $this->perms[$module] = Permissions::NO_ACCESS;
        }
    }

    public function applyPreset(string $preset): void
    {
        $presets = [
            'none' => [],
            'till' => Permissions::defaultStaffPermissions(),
            'manager' => [
                'sales' => [true, true, true, true], 'purchases' => [true, true, true, false], 'products' => [true, true, true, false],
                'inventory' => [true, true, true, false], 'customers' => [true, true, true, false], 'suppliers' => [true, true, true, false],
                'expenses' => [true, true, false, false], 'income' => [true, true, false, false], 'reports' => [true, false, false, false],
            ],
        ];
        $chosen = array_map(fn (array $p): array => array_is_list($p) ? array_combine(self::FLAGS, $p) : $p, $presets[$preset] ?? []);
        // Trimmed to what this person may pass on.
        $grantable = $this->grantable();
        foreach ($chosen as $module => $p) {
            foreach (self::FLAGS as $flag) {
                $chosen[$module][$flag] = $p[$flag] && ($grantable === null || ($grantable[$module][$flag] ?? false));
            }
        }
        $this->fillPermissions($chosen);
    }

    /** What the actor may hand out; null for an owner (anything). */
    protected function grantable(): ?array
    {
        $actor = Principal::load((int) auth()->id());

        return $actor === null || $actor->role === UserRole::Owner ? null : Principal::permissionMap($actor->id);
    }

    /** Rows granting nothing are left out, as the old readPermissions did. */
    protected function postedPermissions(): array
    {
        $map = [];
        foreach (PermissionModule::cases() as $module) {
            $p = $this->perms[$module->value] ?? Permissions::NO_ACCESS;
            $p = array_map(fn ($v): bool => (bool) $v, array_intersect_key($p, array_flip(self::FLAGS))) + Permissions::NO_ACCESS;
            if (in_array(true, $p, true)) {
                $map[$module->value] = $p;
            }
        }

        return $map;
    }
}
