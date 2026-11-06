{{--
    A signed-in shell WITHOUT the menu: the old (account)/layout.tsx. The
    forced password change lives here, outside the app shell, so the shell's
    redirect needs no exception. The shop's own name, as every other screen.
--}}
@props(['title' => null, 'shopName' => 'NunaBooks'])
<x-layouts::base :title="$title">
    <div class="flex min-h-dvh flex-col bg-surface-sunken">
        <header class="flex items-center justify-between gap-3 border-b border-line bg-surface-raised px-4 py-3">
            <p class="truncate font-semibold text-content">{{ $shopName }}</p>
            <form method="POST" action="/logout">
                @csrf
                <x-button type="submit" variant="ghost" size="sm">Sign out</x-button>
            </form>
        </header>
        <main class="flex-1 px-4 py-10">{{ $slot }}</main>
    </div>
</x-layouts::base>
