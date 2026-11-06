{{-- A person's account status badge, as the old users list and detail page showed it. --}}
@if (! $user['isActive'])
    <x-badge tone="neutral">Switched off</x-badge>
@elseif ($user['isLocked'])
    <x-badge tone="danger">Locked</x-badge>
@elseif ($user['mustChangePassword'])
    <x-badge tone="warning">Must set password</x-badge>
@else
    <x-badge tone="success">Active</x-badge>
@endif
