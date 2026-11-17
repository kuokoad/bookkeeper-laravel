{{-- The old (app)/not-found.tsx: inside the shell when signed in, on its own otherwise. --}}
<x-dynamic-component :component="auth()->check() && ! auth()->user()->must_change_password ? 'layouts::app' : 'layouts::auth'" title="Not found">
    <div class="mx-auto max-w-xl">
        <x-page-header title="Not found" description="That page or record does not exist." />
        <div class="rounded-xl border border-line bg-surface-raised p-5">
            <p class="text-sm text-content">It may have been a mistyped address, a record that was never created, or a stock item that was deleted before it was ever used.</p>
            <p class="mt-2 text-sm text-content-muted">Sales, purchases and other financial records are never deleted.</p>
            <div class="mt-5 flex flex-wrap gap-2">
                <x-button size="sm" href="{{ config('app.url') }}">Back to the dashboard</x-button>
                <x-button size="sm" variant="secondary" href="/sales">Sales</x-button>
            </div>
        </div>
    </div>
</x-dynamic-component>
