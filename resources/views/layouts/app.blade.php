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
                <p class="text-xl font-bold leading-tight text-[var(--sidebar-wordmark,#fff)]">NunaBooks</p>
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

            {{-- A plain GET form: search works before any JavaScript arrives. On top of it, the old
                 search-box.tsx: results as you type from /search/suggest (debounced, the request in
                 flight aborted), Enter takes the first hit, "See all results" goes to /search?q=. --}}
            <form method="GET" action="/search" role="search" class="relative hidden max-w-md flex-1 lg:block"
                  x-data="{
                      term: @js(request()->is('search') ? (string) request()->query('q', '') : ''),
                      results: null, open: false, active: -1, timer: null, controller: null,
                      get query() { return this.term.trim() },
                      get fresh() { return this.results !== null && this.results.query === this.query ? this.results : null },
                      get hits() { return this.fresh ? this.fresh.groups.flatMap(g => g.hits) : [] },
                      get showing() { return this.open && this.query.length >= 2 && this.fresh !== null },
                      fetchSoon() {
                          clearTimeout(this.timer);
                          if (this.controller) this.controller.abort();
                          if (this.query.length < 2) return;
                          const q = this.query;
                          this.controller = new AbortController();
                          const signal = this.controller.signal;
                          this.timer = setTimeout(() => {
                              fetch('/search/suggest?q=' + encodeURIComponent(q), { signal, headers: { Accept: 'application/json' } })
                                  .then(r => r.ok ? r.json() : null)
                                  .then(data => { this.results = data; this.active = -1 })
                                  .catch(() => {});
                          }, 180);
                      },
                      go(hit) { this.open = false; this.term = ''; window.location.href = hit.href },
                      seeAll() { this.open = false; window.location.href = '/search?q=' + encodeURIComponent(this.query) },
                      move(step) {
                          if (this.hits.length === 0) return;
                          this.open = true;
                          const next = this.active + step;
                          this.active = next < 0 ? this.hits.length - 1 : (next >= this.hits.length ? 0 : next);
                      },
                      enter(event) {
                          const chosen = this.active >= 0 ? this.hits[this.active] : this.hits[0];
                          if (! chosen) return;
                          event.preventDefault();
                          this.go(chosen);
                      },
                  }"
                  x-on:focusout="if (! $el.contains($event.relatedTarget)) open = false">
                <label for="top-search" class="sr-only">Search stock items, customers, suppliers and receipts</label>
                <div class="flex h-10 items-center gap-2 rounded-lg border border-line bg-surface-sunken px-3">
                    <x-icon name="search" class="h-[18px] w-[18px] text-content-subtle" />
                    <input id="top-search" name="q" type="search" value="{{ request()->is('search') ? request()->query('q') : '' }}"
                           x-model="term" x-on:input="open = true; fetchSoon()" x-on:focus="open = true"
                           x-on:keydown.escape="open = false" x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)"
                           x-on:keydown.enter="enter($event)"
                           role="combobox" aria-controls="top-search-list" x-bind:aria-expanded="showing && hits.length > 0"
                           x-bind:aria-activedescendant="active >= 0 ? 'top-search-' + active : null"
                           placeholder="Search stock items, customers, receipts…" autocomplete="off"
                           class="h-full min-w-0 flex-1 bg-transparent text-sm text-content placeholder:text-content-subtle focus:outline-none">
                </div>

                <div x-show="showing" x-cloak class="absolute left-0 right-0 top-full z-40 mt-1 overflow-hidden rounded-xl border border-line bg-surface-raised shadow-lg">
                    <template x-if="hits.length === 0">
                        <p class="px-3 py-3 text-sm text-content-muted">Nothing matches “<span x-text="query"></span>”.</p>
                    </template>
                    <template x-if="hits.length > 0">
                        <div>
                            <ul id="top-search-list" role="listbox" aria-label="Search results" class="max-h-80 overflow-y-auto">
                                <template x-for="group in fresh.groups" x-bind:key="group.label">
                                    <li>
                                        <p class="border-b border-line bg-surface-sunken px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-content-subtle" x-text="group.label"></p>
                                        <ul>
                                            <template x-for="hit in group.hits" x-bind:key="hit.kind + '-' + hit.id">
                                                <li>
                                                    <button type="button" role="option" x-bind:id="'top-search-' + hits.indexOf(hit)"
                                                            x-bind:aria-selected="hits.indexOf(hit) === active"
                                                            x-on:mouseenter="active = hits.indexOf(hit)" x-on:click="go(hit)"
                                                            x-bind:class="hits.indexOf(hit) === active ? 'bg-surface-sunken' : ''"
                                                            class="flex w-full items-start justify-between gap-3 px-3 py-2 text-left">
                                                        <span class="min-w-0">
                                                            <span class="block truncate text-sm font-medium text-content" x-text="hit.title"></span>
                                                            <span class="block truncate text-xs text-content-subtle" x-text="hit.detail"></span>
                                                        </span>
                                                    </button>
                                                </li>
                                            </template>
                                        </ul>
                                    </li>
                                </template>
                            </ul>
                            <button type="button" x-on:click="seeAll()"
                                    class="block w-full border-t border-line px-3 py-2 text-left text-xs font-medium text-accent hover:bg-surface-sunken">
                                See all results for “<span x-text="query"></span>”<span x-show="fresh.truncated"> — more than shown here</span>
                            </button>
                        </div>
                    </template>
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
