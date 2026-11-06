<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Services\AuditService;
use App\Services\UserService;
use App\Support\Filters\ListFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The audit log: the old (app)/users/audit page, behind the users right
 * (it records who did what with the shop's money). Every filter is
 * validated before it reaches SQL (ListFilters::parseAuditFilters); an
 * unknown ?entity= narrows nothing.
 */
#[Title('Audit log')]
final class Audit extends Component
{
    public const PAGE_SIZE = 50;

    public const ACTION_LABELS = [
        'LOGIN_SUCCESS' => 'Signed in', 'LOGIN_FAILED' => 'Failed sign-in', 'LOGOUT' => 'Signed out', 'CREATE' => 'Created',
        'UPDATE' => 'Changed', 'ARCHIVE' => 'Archived', 'RESTORE' => 'Restored', 'VOID' => 'Voided', 'REVERSE' => 'Reversed',
        'RECONCILE' => 'Counted', 'PERMISSION_CHANGE' => 'Permissions', 'PASSWORD_CHANGE' => 'Password', 'SETTINGS_CHANGE' => 'Settings',
        'SEED_DEMO' => 'Demo data', 'PURGE_DEMO' => 'Demo purge',
    ];

    public const ACTION_TONES = [
        'LOGIN_SUCCESS' => 'success', 'LOGIN_FAILED' => 'danger', 'LOGOUT' => 'neutral', 'CREATE' => 'success', 'UPDATE' => 'accent',
        'ARCHIVE' => 'warning', 'RESTORE' => 'success', 'VOID' => 'danger', 'REVERSE' => 'danger', 'RECONCILE' => 'accent',
        'PERMISSION_CHANGE' => 'warning', 'PASSWORD_CHANGE' => 'warning', 'SETTINGS_CHANGE' => 'warning', 'SEED_DEMO' => 'neutral', 'PURGE_DEMO' => 'neutral',
    ];

    public array $params = [];

    public function mount(Request $request): void
    {
        $this->params = $request->query();
    }

    public function render(): View
    {
        $entityTypes = AuditService::entityTypes();
        $list = ListFilters::parseAuditFilters($this->params, $entityTypes, self::PAGE_SIZE);
        $total = AuditService::count($list['filters']);

        return view('livewire.users.audit', [
            'list' => $list,
            'resolved' => $list['resolved'],
            'total' => $total,
            'entries' => AuditService::list([...$list['filters'], 'limit' => $list['pageSize'], 'offset' => $list['offset']]),
            'users' => UserService::listUsers(),
            'entityTypes' => $entityTypes,
            'lastPage' => max(1, (int) ceil($total / self::PAGE_SIZE)),
        ]);
    }
}
