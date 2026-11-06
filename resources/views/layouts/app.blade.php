{{--
    The signed-in shell: the old (app)/layout.tsx, top-bar.tsx and app-nav.tsx.
    Used by every full-page Livewire component (config/livewire.php) and by
    plain views as <x-layouts::app title="...">.

    Every route under it already passed auth, session.limits and
    password.chosen (routes/web.php); the menu is filtered by the same Gate
    the routes use, so hiding a link is a convenience, not the protection.
--}}
@props(['title' => null])
@php($shell = \App\View\Shell::app())
<x-layouts::base :title="$title">
<div class="app-shell flex min-h-dvh flex-col lg:flex-row">
    <aside class="stitch-sidebar no-print hidden w-60 shrink-0 border-r border-sidebar-line bg-sidebar lg:sticky lg:top-0 lg:flex lg:h-dvh lg:flex-col">
        <a href="/dashboard" aria-label="NunaBooks dashboard" class="flex items-center gap-2 px-4 py-4 focus-visible:outline-2 focus-visible:outline-sidebar-text">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-[#fffdf7] p-[5.625px]">
                <img src="/brand/nunabooks-mark.svg" alt="" width="314" height="374" class="h-full w-auto">
            </span>
            <div class="min-w-0">
                <p class="text-xl font-bold leading-tight text-white">NunaBooks</p>
                <p class="whitespace-nowrap text-[9px] font-medium leading-tight text-sidebar-muted">Simple Books. Stronger Business.</p>
            </div>
        </a>

        <div class="mx-3 min-h-0 flex-1 overflow-y-auto">
            <nav aria-label="Main" class="space-y-1 py-4 pr-1">
                @foreach ($shell['sections'] as $section)
                    <details open class="group">
                        <summary class="flex cursor-pointer list-none items-center gap-2 rounded px-3 py-1.5 text-[11px] font-medium uppercase tracking-wider text-sidebar-subtle transition-colors hover:text-sidebar-muted">
                            <span class="flex-1">{{ $section['heading'] }}</span>
                            <span aria-hidden="true" class="text-sidebar-subtle transition-transform group-open:rotate-90">›</span>
                        </summary>
                        <ul class="motion-reveal mt-0.5 space-y-0.5 pb-2">
                            @foreach ($section['items'] as $item)
                                @php($active = \App\View\Navigation::isActive(request()->path(), $item['href']))
                                <li>
                                    <a href="{{ $item['href'] }}" @if ($active) aria-current="page" @endif
                                       @class(['flex min-h-9 items-center gap-2 rounded-lg px-3 py-2 text-[13px] leading-[18px] transition-colors',
                                           'bg-sidebar-raised font-medium text-sidebar-text' => $active,
                                           'text-sidebar-muted hover:bg-sidebar-raised hover:text-sidebar-text' => ! $active])>
                                        <x-icon :name="$item['icon']" class="h-[18px] w-[18px] shrink-0" />
                                        <span class="truncate">{{ $item['label'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endforeach
                {{-- No module: the person who most needs telling how the till works holds the fewest rights. --}}
                <a href="/help" @if (\App\View\Navigation::isActive(request()->path(), '/help')) aria-current="page" @endif
                   class="flex min-h-9 items-center gap-2 rounded-lg px-3 py-2 text-[13px] leading-[18px] text-sidebar-muted hover:bg-sidebar-raised hover:text-sidebar-text">
                    <x-icon name="help" class="h-[18px] w-[18px]" />
                    <span>Support</span>
                </a>
            </nav>
        </div>

        <div class="border-t border-sidebar-line px-4 py-4">
            <div class="mb-2 flex items-center justify-between gap-2">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-sidebar-text">{{ $shell['user']->display_name }}</p>
                    <p class="text-xs capitalize text-sidebar-subtle">{{ strtolower($shell['user']->role) }}</p>
                </div>
                <a href="/account/password" aria-label="Change my password" title="Change password"
                   class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-sidebar-muted hover:bg-sidebar-raised hover:text-sidebar-text focus-visible:outline-2 focus-visible:outline-sidebar-text">
                    <x-icon name="password" class="h-[18px] w-[18px]" />
                </a>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <form method="POST" action="/logout">
                    @csrf
                    <input type="hidden" name="switch" value="1">
                    <x-button type="submit" variant="secondary" size="sm" class="w-full whitespace-nowrap px-2" title="Sign out and let the next person sign in with their till PIN">Switch user</x-button>
                </form>
                <form method="POST" action="/logout">
                    @csrf
                    <x-button type="submit" variant="secondary" size="sm" class="w-full">Sign out</x-button>
                </form>
            </div>
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="no-print sticky top-0 z-30 flex min-h-16 items-center gap-3 border-b border-line bg-surface-raised px-4 py-2.5 lg:px-6">
            <p class="truncate text-sm font-medium text-content lg:hidden">{{ $shell['shopName'] }}</p>

            {{-- A plain GET form: search works before any JavaScript arrives. --}}
            <form method="GET" action="/search" role="search" class="hidden max-w-md flex-1 lg:block">
                <label for="top-search" class="sr-only">Search</label>
                <div class="flex h-10 items-center gap-2 rounded-lg border border-line bg-surface-sunken px-3">
                    <x-icon name="search" class="h-[18px] w-[18px] text-content-subtle" />
                    <input id="top-search" name="q" type="search" value="{{ request()->is('search') ? request()->query('q') : '' }}"
                           placeholder="Search products, customers, receipts…" autocomplete="off"
                           class="h-full min-w-0 flex-1 bg-transparent text-sm text-content placeholder:text-content-subtle focus:outline-none">
                </div>
            </form>

            <div class="ml-auto flex items-center gap-1">
                <a href="/search" class="rounded-lg p-2 text-content-muted transition-colors hover:bg-surface-sunken hover:text-content lg:hidden" aria-label="Search">
                    <x-icon name="search" class="h-5 w-5" />
                </a>

                <x-theme-switch />

                {{-- The sidebar carries these on desktop and is hidden on a phone. --}}
                <form method="POST" action="/logout" class="lg:hidden">
                    @csrf
                    <x-button type="submit" variant="ghost" size="sm" class="whitespace-nowrap">Sign out</x-button>
                </form>

                @php($notices = $shell['notices'])
                <details class="relative">
                    <summary class="flex cursor-pointer list-none items-center rounded-lg p-2 text-content-muted transition-colors hover:bg-surface-sunken hover:text-content"
                             aria-label="{{ count($notices) === 0 ? 'Notifications: nothing needs attention' : 'Notifications: '.count($notices).' needing attention' }}">
                        <span class="relative">
                            <x-icon name="notifications" class="h-5 w-5" />
                            @if (count($notices) > 0)
                                <span class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold text-white" aria-hidden="true">{{ count($notices) }}</span>
                            @endif
                        </span>
                    </summary>
                    <div class="absolute right-0 z-40 mt-2 w-80 rounded-xl border border-line bg-surface-raised p-2 shadow-lg">
                        <p class="px-2 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-content-subtle">Needs attention</p>
                        @if (count($notices) === 0)
                            <p class="px-2 py-3 text-sm text-content-muted">Nothing needs attention. Your books balance and nothing is overdue.</p>
                        @else
                            <ul class="space-y-0.5">
                                @foreach ($notices as $notice)
                                    <li>
                                        <a href="{{ $notice['href'] }}" class="block rounded-lg px-2 py-2 transition-colors hover:bg-surface-sunken">
                                            <span @class(['block text-sm font-medium', 'text-danger' => $notice['tone'] === 'danger', 'text-warning' => $notice['tone'] === 'warning', 'text-content-muted' => $notice['tone'] === 'info'])>{{ $notice['title'] }}</span>
                                            <span class="block text-xs text-content-muted">{{ $notice['detail'] }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </details>

                <div class="ml-2 hidden items-center gap-2 border-l border-line pl-3 lg:flex">
                    <span aria-hidden="true" class="flex h-7 w-7 items-center justify-center rounded-lg bg-surface-sunken text-[11px] font-semibold text-content">{{ mb_strtoupper(mb_substr(trim($shell['shopName']), 0, 2)) }}</span>
                    <p class="max-w-40 truncate text-xs font-semibold text-content">{{ $shell['shopName'] }}</p>
                </div>
            </div>
        </header>

        <main class="app-main flex-1 px-4 py-6 pb-24 lg:px-6 lg:py-6 lg:pb-8">
            <x-flash />
            <div class="motion-page">{{ $slot }}</div>
        </main>
    </div>

    {{-- The few things done all day, one thumb-tap away on a phone behind the counter. --}}
    <nav aria-label="Main" class="no-print fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface-raised pb-[env(safe-area-inset-bottom)] lg:hidden">
        <ul class="grid grid-cols-4">
            @foreach ($shell['primary'] as $item)
                @php($active = \App\View\Navigation::isActive(request()->path(), $item['href']))
                <li>
                    <a href="{{ $item['href'] }}" @if ($active) aria-current="page" @endif
                       @class(['flex min-h-[56px] flex-col items-center justify-center gap-1 px-1', 'text-accent' => $active, 'text-content-muted' => ! $active])>
                        <x-icon :name="$item['icon']" class="h-5 w-5" />
                        <span class="text-[11px] leading-none">{{ $item['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
</div>
</x-layouts::base>
