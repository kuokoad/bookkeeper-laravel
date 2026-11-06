<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Enums\UserRole;
use App\Services\UserService;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Who can sign in: the old (app)/users page. */
#[Title('Users')]
final class Index extends Component
{
    public function render(): View
    {
        $users = UserService::listUsers();

        return view('livewire.users.index', [
            'users' => $users,
            'owners' => array_values(array_filter($users, fn (array $u): bool => $u['role'] === UserRole::Owner && $u['isActive'])),
            'locked' => array_values(array_filter($users, fn (array $u): bool => $u['isLocked'])),
            'active' => count(array_filter($users, fn (array $u): bool => $u['isActive'])),
            'switchedOff' => count(array_filter($users, fn (array $u): bool => ! $u['isActive'])),
        ]);
    }
}
