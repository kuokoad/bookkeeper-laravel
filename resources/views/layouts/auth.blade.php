{{--
    Sign-in and first-run setup: the old (auth)/layout.tsx. Two halves on a
    wide screen: the story panel on the left, the form on the right. The panel
    is decoration, so a phone or tablet gets the form alone.
--}}
@props(['title' => null])
<x-layouts::base :title="$title">
    <div class="auth-main grid min-h-dvh bg-surface-raised lg:grid-cols-2">
        <aside class="auth-story hidden flex-col justify-between p-14 text-white lg:sticky lg:top-0 lg:flex lg:h-dvh">
            <div class="flex items-center gap-2">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-[#fffdf7] p-1.5">
                    <img src="/brand/nunabooks-mark.svg" alt="" width="314" height="374" class="h-full w-auto">
                </span>
                <p class="text-2xl font-bold leading-tight">NunaBooks</p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-white/70">Bookkeeping, with clarity</p>
                <p class="mt-4 text-5xl font-semibold leading-[1.2] tracking-tight xl:text-6xl xl:leading-[1.2]">Your shop.<br>A clearer picture.</p>
                <p class="mt-6 max-w-md text-base leading-relaxed text-white/80">Sales, stock and accounts in one place. Every entry connected. Every balance traceable.</p>
                <ul class="mt-8 space-y-5 text-sm text-white/90">
                    <li class="flex items-center gap-3"><x-icon name="check" class="h-5 w-5 text-white/70" />Reliable double-entry accounting</li>
                    <li class="flex items-center gap-3"><x-icon name="reports" class="h-5 w-5 text-white/70" />Reports grounded in your ledger</li>
                </ul>
            </div>
            <p class="text-xs text-white/60">Simple Books. Stronger Business.</p>
        </aside>
        <main class="flex items-center justify-center px-4 py-10">
            <div class="w-full max-w-sm">{{ $slot }}</div>
        </main>
    </div>
</x-layouts::base>
