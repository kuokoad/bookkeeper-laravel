{{--
    One-shot messages. From a redirect: session('status') (success) or
    session('error'). From a Livewire action without a redirect:
    $this->dispatch('toast', message: 'Saved.', tone: 'success'). A save that
    leaves the screen unchanged must still say so.
--}}
<div class="no-print space-y-2" aria-live="polite">
    @if (session('status'))
        <x-alert tone="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif
    @if (session('error'))
        <x-alert tone="danger" class="mb-4">{{ session('error') }}</x-alert>
    @endif
    <div x-data="{ toasts: [] }"
         x-on:toast.window="const t = { id: Date.now() + Math.random(), message: $event.detail.message, tone: $event.detail.tone ?? 'success' }; toasts.push(t); setTimeout(() => toasts = toasts.filter(x => x.id !== t.id), 6000)"
         class="fixed right-4 bottom-20 z-50 w-80 max-w-[calc(100vw-2rem)] space-y-2 lg:bottom-4">
        <template x-for="t in toasts" :key="t.id">
            <div role="status" class="motion-appear rounded-lg border px-4 py-3 text-sm text-content shadow-lg"
                 :class="t.tone === 'danger' ? 'bg-danger-soft border-danger/40' : (t.tone === 'warning' ? 'bg-warning-soft border-warning/40' : 'bg-success-soft border-success/40')"
                 x-text="t.message"></div>
        </template>
    </div>
</div>
