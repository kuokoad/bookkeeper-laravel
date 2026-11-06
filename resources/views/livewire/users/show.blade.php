{{-- The old (app)/users/[id] page and user-admin-forms.tsx. --}}
@use('App\Enums\UserRole')
@use('App\View\Format')
@php($role = $user['role']->value)
<div class="w-full">
    <x-page-header :title="$user['displayName']" :description="$user['username'].' · '.($role === 'OWNER' ? 'Owner' : 'Staff')">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/users">All users</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($status)<x-alert tone="success" class="mb-4">{{ $status }}</x-alert>@endif

    @if ($user['isLocked'])
        <x-alert tone="warning" title="This account is locked" class="mb-4">Too many wrong passwords. It unlocks by itself at {{ Format::dateTime($user['lockedUntil']) }}, or you can unlock it now below.</x-alert>
    @endif
    @if (! $user['isActive'])
        <x-alert tone="warning" title="This account is switched off" class="mb-4">They cannot sign in. Their records and history are kept.</x-alert>
    @endif

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <x-card>
            <p class="text-sm text-content-muted">Last signed in</p>
            <p class="mt-1 font-medium text-content">{{ $user['lastLoginAt'] === null ? 'Never' : Format::dateTime($user['lastLoginAt']) }}</p>
        </x-card>
        <x-card>
            <p class="text-sm text-content-muted">Till PIN</p>
            <p class="mt-1 font-medium text-content">{{ $user['hasPin'] ? 'Set' : 'Not set' }}</p>
        </x-card>
        <x-card>
            <p class="text-sm text-content-muted">Status</p>
            <p class="mt-1">@include('livewire.users.partials.status', ['user' => $user])</p>
        </x-card>
    </div>

    @if ($canEdit)
        <div class="space-y-6">
            <form wire:submit="saveDetails" class="rounded-xl border border-line bg-surface-raised p-4" novalidate>
                <h2 class="mb-3 text-sm font-semibold text-content">Details</h2>
                @error('details')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Their name" for="displayName" required>
                        <x-input id="displayName" wire:model="displayName" required :invalid="$errors->has('displayName')" />
                    </x-field>
                    <x-field label="Role" for="role" required :hint="$mayChangeRole ? null : ($isSelf ? 'You cannot change your own role. Another owner has to do that for you.' : 'Only an owner can change someone’s role.')">
                        @if ($mayChangeRole)
                            <select id="role" wire:model.live="selectedRole" class="h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content">
                                <option value="STAFF">Staff</option>
                                <option value="OWNER">Owner</option>
                            </select>
                        @else
                            <p id="role" class="flex h-11 items-center rounded-lg border border-line bg-surface px-3 text-content-muted">{{ $role === 'OWNER' ? 'Owner' : 'Staff' }}</p>
                        @endif
                    </x-field>
                </div>
                <p class="mt-2 text-xs text-content-subtle">Username <strong class="text-content">{{ $user['username'] }}</strong> cannot be changed — it is referenced throughout the audit trail.</p>
                @if ($selectedRole !== $role)
                    <x-alert tone="warning" class="mt-3">{{ $selectedRole === 'OWNER' ? 'Making this person an owner gives them full access to everything, including money and other accounts. Their individual permissions will be removed.' : 'Dropping this person to staff removes their full access. Tick what they should still be able to do below.' }} They will be signed out.</x-alert>
                @endif
                <div class="mt-4"><x-button type="submit" wire:loading.attr="disabled" wire:target="saveDetails">Save details</x-button></div>
            </form>

            @if ($selectedRole === 'STAFF' && $role === 'STAFF')
                <form wire:submit="savePermissions" class="rounded-xl border border-line bg-surface-raised p-4" novalidate>
                    <h2 class="mb-1 text-sm font-semibold text-content">What they can do</h2>
                    <p class="mb-4 text-sm text-content-muted">{{ $mayChangePermissions ? 'Saving this signs them out, so the change takes effect immediately.' : 'These are your own permissions. Another owner has to change them for you.' }}</p>
                    @error('permissions')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
                    @include('livewire.users.partials.matrix', ['disabled' => ! $mayChangePermissions])
                    @if ($mayChangePermissions)
                        <div class="mt-4"><x-button type="submit" wire:loading.attr="disabled" wire:target="savePermissions">Save permissions</x-button></div>
                    @endif
                </form>
            @endif

            @if ($role === 'OWNER')
                <div class="rounded-xl border border-line bg-surface-raised p-4">
                    <h2 class="mb-1 text-sm font-semibold text-content">What they can do</h2>
                    <p class="text-sm text-content-muted">An owner has full access to everything. There is nothing to limit.</p>
                </div>
            @endif

            <form wire:submit="resetPassword" class="rounded-xl border border-line bg-surface-raised p-4" novalidate>
                <h2 class="mb-1 text-sm font-semibold text-content">Reset their password</h2>
                <p class="mb-3 text-sm text-content-muted">Use this when someone has forgotten theirs. They are signed out and must choose a new password the next time they sign in, so you do not keep knowing a working password for their account.</p>
                @error('password')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
                <div class="max-w-sm">
                    <x-field label="New starting password" for="newPassword" required>
                        <x-input id="newPassword" type="text" wire:model="newPassword" autocomplete="off" required :invalid="$errors->has('newPassword')" />
                    </x-field>
                </div>
                <div class="mt-4"><x-button type="submit" wire:loading.attr="disabled" wire:target="resetPassword">Reset password</x-button></div>
            </form>

            <form wire:submit="savePin" class="rounded-xl border border-line bg-surface-raised p-4" novalidate>
                <h2 class="mb-1 text-sm font-semibold text-content">Till PIN</h2>
                <p class="mb-3 text-sm text-content-muted">A short PIN for switching quickly at the till. {{ $user['hasPin'] ? 'A PIN is currently set. ' : '' }}Leave it blank to remove it. Wrong PINs lock the account just as wrong passwords do.</p>
                @error('pinForm')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
                <div class="max-w-xs">
                    <x-field label="PIN (4–8 digits)" for="pin">
                        <x-input id="pin" wire:model="pin" inputmode="numeric" autocomplete="off" :placeholder="$user['hasPin'] ? 'Enter a new PIN, or leave blank to remove' : 'e.g. 8351'" :invalid="$errors->has('pin')" />
                    </x-field>
                </div>
                <div class="mt-4"><x-button type="submit" wire:loading.attr="disabled" wire:target="savePin">Save PIN</x-button></div>
            </form>

            <div class="rounded-xl border border-line bg-surface-raised p-4">
                <h2 class="mb-3 text-sm font-semibold text-content">Access</h2>
                @error('access')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
                <div class="flex flex-wrap items-center gap-3">
                    @if ($user['isLocked'])
                        <x-button variant="secondary" wire:click="unlock">Unlock the account</x-button>
                    @endif
                    @if ($user['isActive'])
                        @if ($isSelf)
                            <p class="text-sm text-content-muted">You cannot switch off your own account.</p>
                        @elseif ($confirmingOff)
                            <span class="text-sm text-content">Switch off {{ $user['displayName'] }}? They will be signed out immediately.</span>
                            <x-button variant="danger" wire:click="setActive(false)">Yes, switch off</x-button>
                            <x-button variant="ghost" wire:click="$set('confirmingOff', false)">Cancel</x-button>
                        @else
                            <x-button variant="secondary" wire:click="$set('confirmingOff', true)">Switch off this account…</x-button>
                        @endif
                    @else
                        <x-button wire:click="setActive(true)">Turn the account back on</x-button>
                    @endif
                </div>
                <p class="mt-3 text-xs text-content-subtle">Accounts are never deleted. Everything this person recorded stays in the books and in the audit trail.</p>
            </div>
        </div>
    @else
        <x-card><p class="text-sm text-content-muted">You can see this account but not change it.</p></x-card>
    @endif

    <h2 class="mt-8 mb-3 text-sm font-semibold text-content">Recent activity</h2>
    @if ($activity->isEmpty())
        <x-card><p class="text-sm text-content-muted">Nothing recorded for this person yet.</p></x-card>
    @else
        <x-table>
            <x-slot:head><x-th>When</x-th><x-th>What</x-th></x-slot:head>
            @foreach ($activity as $entry)
                <tr class="border-b border-line last:border-0">
                    <x-td><span class="whitespace-nowrap text-content-muted">{{ Format::dateTime($entry->created_at) }}</span></x-td>
                    <x-td>{{ $entry->summary }}</x-td>
                </tr>
            @endforeach
        </x-table>
    @endif
    <p class="mt-3 text-xs text-content-subtle"><a href="/users/audit?user={{ $user['id'] }}" class="text-accent hover:underline">See everything this person has done</a></p>
</div>
