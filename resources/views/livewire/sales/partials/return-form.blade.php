{{--
    Goods coming back (the old shared/return-form.tsx), for the sale page.
    Fields bind to the component's returnQty, returnDate, refundAccountId,
    refundAmount and returnReason; it submits recordReturn. $estimate is the
    component's figure, never worked out here.
--}}
@php($open = $errors->hasAny(['returnForm', 'returnDate', 'refundAmount']))
<div x-data="{ open: {{ $open ? 'true' : 'false' }} }" class="no-print">
    <div x-show="! open" class="rounded-xl border border-line bg-surface-raised p-4">
        <h2 class="text-sm font-semibold text-content">{{ $title }}</h2>
        <p class="mt-1 mb-3 text-sm text-content-muted">{{ $description }}</p>
        <x-button variant="secondary" x-on:click="open = true">Record a return…</x-button>
    </div>

    <form x-show="open" x-cloak wire:submit="recordReturn" novalidate class="rounded-xl border border-line bg-surface-raised p-4">
        <h2 class="mb-3 text-sm font-semibold text-content">{{ $title }}</h2>
        @error('returnForm')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror

        <div class="mb-4 max-w-xs">
            <x-field label="Date" for="return-date" name="returnDate" required>
                <x-date-input id="return-date" wire:model="returnDate" :value="$returnDate" :invalid="$errors->has('returnDate')" />
            </x-field>
        </div>

        <div class="space-y-2">
            @foreach ($items as $item)
                <div class="flex flex-wrap items-end justify-between gap-3 rounded-lg border border-line p-3" wire:key="ret-{{ $item['id'] }}">
                    <div class="min-w-0">
                        <p class="font-medium text-content">{{ $item['productName'] }}</p>
                        <p class="text-xs text-content-subtle">
                            {{ \App\Domain\Quantity::format($item['returnableMilli']) }} {{ $item['unit'] }} can still be returned ·
                            {{ \App\View\Format::money($item['unitPriceMinor']) }} each
                        </p>
                    </div>
                    <div class="w-32">
                        <label for="ret-{{ $item['id'] }}" class="mb-1 block text-xs text-content-muted">Return qty</label>
                        <x-input amount id="ret-{{ $item['id'] }}" class="h-10" placeholder="0" wire:model.live.debounce.400ms="returnQty.{{ $item['id'] }}" />
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div>
                <label for="refundAccountId" class="mb-1 block text-xs text-content-muted">Money handed back via</label>
                <select id="refundAccountId" wire:model="refundAccountId" class="h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content">
                    @foreach ($accounts as $account)
                        <option value="{{ $account['id'] }}">{{ $account['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <x-field label="Amount refunded" for="refundAmount" name="refundAmount">
                <x-input amount id="refundAmount" wire:model="refundAmount" placeholder="0.00" :invalid="$errors->has('refundAmount')" />
            </x-field>
            <div>
                <label for="return-reason" class="mb-1 block text-xs text-content-muted">Reason</label>
                <x-input id="return-reason" wire:model="returnReason" placeholder="e.g. Damaged" />
            </div>
        </div>

        <p class="mt-3 text-xs text-content-subtle">
            Value of goods being returned: {{ \App\View\Format::money($estimate) }}. Anything not refunded
            in cash {{ $creditLabel }}.
        </p>

        <div class="mt-4 flex items-center gap-3">
            <x-button type="submit" wire:loading.attr="disabled">{{ $submitLabel }}</x-button>
            <x-button variant="ghost" x-on:click="open = false">Cancel</x-button>
        </div>
    </form>
</div>
