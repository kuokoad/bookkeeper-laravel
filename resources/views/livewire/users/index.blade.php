{{-- The old (app)/users page. --}}
@use('App\Enums\UserRole')
@use('App\View\Format')
<div class="w-full">
    <x-page-header title="Users" description="Who can sign in, and what each person is allowed to do.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/users/audit">Audit log</x-button>
            @can('users.create')
                <x-button size="sm" href="/users/new">Add a user</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($locked !== [])
        <x-alert tone="warning" title="Someone is locked out" class="mb-4">{{ implode(', ', array_column($locked, 'displayName')) }} entered the wrong password too many times. Open their account to unlock it.</x-alert>
    @endif
    @if (count($owners) === 1)
        <x-alert tone="info" class="mb-4">There is only one owner account. If you lose access to it, nobody can manage the shop — consider making a second person an owner.</x-alert>
    @endif

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <x-stat icon="users" label="People" :value="(string) $active" />
        <x-stat icon="users" label="Owners" :value="(string) count($owners)" />
        <x-stat icon="users" label="Switched off" :value="(string) $switchedOff" />
    </div>

    <x-table>
        <x-slot:head>
            <x-th>Person</x-th><x-th>Role</x-th><x-th numeric>Areas</x-th><x-th>Last signed in</x-th><x-th>Status</x-th><x-th />
        </x-slot:head>
        @foreach ($users as $user)
            <tr class="border-b border-line last:border-0" wire:key="user-{{ $user['id'] }}">
                <x-td>
                    <a href="/users/{{ $user['id'] }}" class="font-medium text-accent hover:underline">{{ $user['displayName'] }}</a>
                    @if ($user['id'] === auth()->id())<x-badge tone="accent" class="ml-2">You</x-badge>@endif
                    <span class="mt-0.5 block text-xs text-content-subtle">{{ $user['username'] }}@if ($user['hasPin']) · has a till PIN @endif</span>
                </x-td>
                <x-td><x-badge :tone="$user['role'] === UserRole::Owner ? 'accent' : 'neutral'">{{ $user['role'] === UserRole::Owner ? 'Owner' : 'Staff' }}</x-badge></x-td>
                <x-td numeric>
                    @if ($user['role'] === UserRole::Owner)<span class="text-content-subtle">All</span>@else{{ $user['moduleCount'] }}@endif
                </x-td>
                <x-td><span class="whitespace-nowrap text-content-muted">{{ $user['lastLoginAt'] === null ? 'Never' : Format::dateTime($user['lastLoginAt']) }}</span></x-td>
                <x-td>@include('livewire.users.partials.status', ['user' => $user])</x-td>
                <x-td><div class="flex justify-end"><a href="/users/{{ $user['id'] }}" class="text-xs font-medium text-accent hover:underline">Manage</a></div></x-td>
            </tr>
        @endforeach
    </x-table>

    <p class="mt-4 text-xs text-content-subtle">Accounts are switched off, never deleted — their sales, counts and audit history must survive. Switching someone off ends their session immediately.</p>
</div>
