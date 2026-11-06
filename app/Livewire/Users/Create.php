<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Enums\UserRole;
use App\Services\AuthService;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Add a user: the old (app)/users/new page and createUserAction. The ticked
 * boxes are the whole story (no preset flag decides access), and a
 * non-owner may neither create an owner nor pass on rights they lack;
 * AuthService refuses both.
 */
#[Title('Add a user')]
final class Create extends Component
{
    use EditsPermissions;

    public string $displayName = '';

    public string $username = '';

    public string $password = '';

    public string $pin = '';

    public string $role = 'STAFF';

    public function mount(): void
    {
        $this->fillPermissions([]);
    }

    public function save(): void
    {
        $this->authorize('users.create');
        $this->resetErrorBag();
        $username = trim($this->username);
        $displayName = trim($this->displayName);
        $errors = [];
        if (mb_strlen($username) < 3) {
            $errors['username'] = 'Username must be at least 3 characters.';
        } elseif (mb_strlen($username) > 40) {
            $errors['username'] = 'Keep the username to 40 characters.';
        } elseif (preg_match('/^[a-zA-Z0-9._-]+$/D', $username) !== 1) {
            $errors['username'] = 'Use only letters, numbers, dots, dashes or underscores.';
        }
        if (mb_strlen($displayName) < 2) {
            $errors['displayName'] = 'Enter the person’s name.';
        } elseif (mb_strlen($displayName) > 80) {
            $errors['displayName'] = 'Keep the name to 80 characters.';
        }
        if (mb_strlen($this->password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        } elseif (mb_strlen($this->password) > 200) {
            $errors['password'] = 'Keep the password to 200 characters.';
        }
        $role = UserRole::tryFrom($this->role);
        if ($role === null) {
            $errors['role'] = 'Choose what kind of account this is.';
        }
        foreach ($errors as $field => $message) {
            $this->addError($field, $message);
        }
        if ($errors !== []) {
            return;
        }

        $pin = trim($this->pin);
        try {
            $id = AuthService::createUser([
                'username' => $username,
                'displayName' => $displayName,
                'password' => $this->password,
                'role' => $role,
                // An owner never has a till PIN (D17); the field is hidden for one.
                ...($pin !== '' && $role === UserRole::Staff ? ['pin' => $pin] : []),
                ...($role === UserRole::Staff ? ['permissions' => $this->postedPermissions()] : []),
                // They set their own on first sign-in, so whoever made the account does not keep a working password.
                'mustChangePassword' => true,
            ], Principal::load((int) auth()->id())->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }

        session()->flash('status', 'Account created. Give them the starting password — they will be asked to choose their own the first time they sign in.');
        $this->redirect("/users/{$id}");
    }

    public function render(): View
    {
        return view('livewire.users.create', [
            'isOwner' => Principal::load((int) auth()->id())?->role === UserRole::Owner,
            'grantable' => $this->grantable(),
            'rows' => self::MODULE_ROWS,
            'sensitive' => self::SENSITIVE,
        ]);
    }
}
