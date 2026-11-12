<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Enums\UserRole;
use App\Services\Actor;
use App\Services\AuditService;
use App\Services\UserService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One person's account: the old (app)/users/[id] page, user-admin-forms.tsx
 * and the user actions. Every change goes through UserService, whose guards
 * (last owner, self-changes, owner takeover D14) are shown as its message.
 */
#[Title('User')]
final class Show extends Component
{
    use EditsPermissions;

    #[Locked]
    public int $userId;

    public string $displayName = '';

    public string $selectedRole = 'STAFF';

    public string $newPassword = '';

    public string $pin = '';

    public bool $confirmingOff = false;

    public ?string $status = null;

    public function mount(int $user): void
    {
        try {
            $row = UserService::getUser($user);
        } catch (NotFoundError) {
            abort(404);
        }
        $this->userId = $user;
        $this->displayName = $row['displayName'];
        $this->selectedRole = $row['role']->value;
        $this->fillPermissions(UserService::getUserPermissions($user));
        // "Account created" is shown by this page, not the shell, so the next action can clear it.
        $this->status = session()->pull('status');
    }

    /** A message belongs to the action that made it: any later request starts without one. */
    public function hydrate(): void
    {
        $this->status = null;
    }

    public function saveDetails(): void
    {
        $this->authorize('users.edit');
        $this->resetErrorBag();
        $name = trim($this->displayName);
        if (mb_strlen($name) < 2) {
            $this->addError('displayName', 'Enter the person’s name.');

            return;
        }
        if (mb_strlen($name) > 80) {
            $this->addError('displayName', 'Keep the name to 80 characters.');

            return;
        }
        $role = UserRole::tryFrom($this->selectedRole);
        if ($role === null) {
            $this->addError('details', 'Choose a role.');

            return;
        }
        $this->attempt('details', fn (Actor $actor) => UserService::updateUser($this->userId, ['displayName' => $name, 'role' => $role], $actor), 'Account updated.');
        $this->fillPermissions(UserService::getUserPermissions($this->userId));
    }

    public function savePermissions(): void
    {
        $this->authorize('users.edit');
        $this->resetErrorBag();
        $this->attempt('permissions', fn (Actor $actor) => UserService::setUserPermissions($this->userId, $this->postedPermissions(), $actor),
            'Permissions saved. They were signed out, so the new rights apply as soon as they sign back in.');
    }

    public function resetPassword(): void
    {
        $this->authorize('users.edit');
        $this->resetErrorBag();
        if (mb_strlen($this->newPassword) < 8) {
            $this->addError('newPassword', 'Password must be at least 8 characters.');

            return;
        }
        if ($this->attempt('password', fn (Actor $actor) => UserService::resetUserPassword($this->userId, $this->newPassword, $actor),
            'Password reset. They were signed out and must choose a new password next time.')) {
            $this->newPassword = '';
        }
    }

    public function savePin(): void
    {
        $this->authorize('users.edit');
        $this->resetErrorBag();
        $pin = trim($this->pin);
        try {
            UserService::setUserPin($this->userId, $pin === '' ? null : $pin, $this->actor());
        } catch (ValidationError $e) {
            $this->addError('pin', $e->userMessage);

            return;
        } catch (DomainError $e) {
            $this->addError('pinForm', $e->userMessage);

            return;
        }
        $this->pin = '';
        $this->status = 'Till PIN updated.';
    }

    public function unlock(): void
    {
        $this->authorize('users.edit');
        $this->resetErrorBag();
        $this->attempt('access', fn (Actor $actor) => UserService::unlockUser($this->userId, $actor), null);
    }

    public function setActive(bool $isActive): void
    {
        $this->authorize('users.edit');
        $this->resetErrorBag();
        $this->attempt('access', fn (Actor $actor) => UserService::setUserActive($this->userId, $isActive, $actor), null);
        $this->confirmingOff = false;
    }

    public function render(): View
    {
        $user = UserService::getUser($this->userId);
        $actor = Principal::load((int) auth()->id());
        $isSelf = $user['id'] === $actor?->id;
        $actorIsOwner = $actor?->role === UserRole::Owner;

        return view('livewire.users.show', [
            'user' => $user,
            'canEdit' => Gate::allows('users.edit'),
            'isSelf' => $isSelf,
            // Roles are an owner's call, and nobody changes their own.
            'mayChangeRole' => $actorIsOwner && ! $isSelf,
            'mayChangePermissions' => ! $isSelf,
            // A reset or PIN the service would refuse (yourself, or someone holding rights you lack) is not offered.
            'mayTakeOver' => $actor !== null && UserService::mayTakeOver($this->userId, $actor->actor()),
            'grantable' => $this->grantable(),
            'rows' => self::MODULE_ROWS,
            'sensitive' => self::SENSITIVE,
            'activity' => AuditService::list(['userId' => $this->userId, 'limit' => 25]),
        ]);
    }

    /** Runs a UserService change, showing its refusal against $bag. */
    private function attempt(string $bag, callable $change, ?string $success): bool
    {
        $this->status = null;
        try {
            $change($this->actor());
        } catch (DomainError $e) {
            $this->addError($bag, $e->userMessage);

            return false;
        }
        $this->status = $success;

        return true;
    }

    private function actor(): Actor
    {
        return Principal::load((int) auth()->id())->actor();
    }
}
