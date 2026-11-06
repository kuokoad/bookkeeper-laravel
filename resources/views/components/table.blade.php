{{--
    The old ui/table.tsx: scrolls sideways inside its own box so a wide table
    never pushes the page off a phone. Header cells go in the `head` slot
    (<x-th>), rows in the default slot (<tr class="border-b border-line last:border-0">
    with <x-td> / <x-money-cell>), and an optional `foot` slot for totals.
--}}
<div {{ $attributes->class('overflow-x-auto rounded-[var(--panel-radius,var(--radius-xl))] border border-line bg-surface-raised') }}>
    <table class="w-full min-w-max border-collapse text-sm">
        <thead class="border-b border-line bg-surface-sunken text-left">
            <tr>{{ $head }}</tr>
        </thead>
        <tbody>{{ $slot }}</tbody>
        @isset($foot)
            <tfoot class="border-t border-line-strong bg-surface-sunken font-semibold">{{ $foot }}</tfoot>
        @endisset
    </table>
</div>
