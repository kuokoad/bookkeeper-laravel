@props(['history' => []])
@php
    use App\View\Format;
@endphp
{{-- D34: what a document said before each edit, newest edit first. Shown to the owner only. --}}
@if ($history !== [])
    <h2 class="mb-3 mt-8 text-sm font-semibold text-content">Edit history</h2>
    <div class="space-y-3">
        @foreach ($history as $i => $edit)
            <x-card wire:key="edit-{{ $i }}">
                <p class="text-sm text-content">
                    <span class="font-medium">Edited {{ Format::dateTime($edit['at']) }}</span>
                    @if ($edit['username']) by {{ $edit['username'] }}@endif
                    <span class="text-content-muted">· {{ $edit['reason'] }}</span>
                </p>
                <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-content-subtle">Before this edit</p>
                <dl class="mt-1 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[10rem_1fr]">
                    <dt class="text-content-muted">Date</dt>
                    <dd class="text-content">{{ Format::date($edit['before']['date']) }}</dd>
                    @foreach ($edit['before']['fields'] as $label => $value)
                        @if ($value !== null && $value !== '')
                            <dt class="text-content-muted">{{ $label }}</dt>
                            <dd class="text-content">{{ $value }}</dd>
                        @endif
                    @endforeach
                </dl>
                @if ($edit['before']['lines'] !== [])
                    <ul class="mt-2 divide-y divide-line text-sm">
                        @foreach ($edit['before']['lines'] as $line)
                            <li class="flex justify-between gap-4 py-1">
                                <span class="text-content">{{ $line['label'] }}</span>
                                @if ($line['amount'] !== null)
                                    <span class="tabular-nums text-content">{{ Format::money((int) $line['amount'], bare: true) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($edit['before']['total'] !== null)
                    <p class="mt-2 flex justify-between text-sm font-semibold text-content">
                        <span>Total</span><span class="tabular-nums">{{ Format::money((int) $edit['before']['total'], bare: true) }}</span>
                    </p>
                @endif
            </x-card>
        @endforeach
    </div>
@endif
