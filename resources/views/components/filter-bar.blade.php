{{--
    One filter bar for every list (the old shared/filter-bar.tsx), as a plain
    GET form: the URL it produces is the one the page AND its CSV export parse
    with the same ListFilters::parseX.

    <x-filter-bar :list="$list" :dates="true" :fields="[
        ['kind' => 'search', 'key' => 'q', 'label' => 'Search', 'placeholder' => 'Receipt or customer', 'value' => $list['filters']['search'] ?? ''],
        ['kind' => 'select', 'key' => 'status', 'label' => 'Status', 'allLabel' => 'All statuses', 'options' => ['POSTED' => 'Posted', 'VOIDED' => 'Voided'], 'value' => $list['filters']['status'] ?? null],
        ['kind' => 'amount-range', 'minKey' => 'min', 'maxKey' => 'max', 'label' => 'Amount', 'min' => $minText, 'max' => $maxText],
        ['kind' => 'date', 'key' => 'asAt', 'label' => 'As at', 'value' => $asAt],
    ]" />

    Rules it keeps for every page at once:
     - every control shows the RESOLVED value (each field's `value`, and the
       period from $list['range'] / $list['preset']), never the raw URL, so a
       hand-edited ?from=junk cannot leave the box saying something other than
       the rows under it (filter rule 5). `value` is required for that reason;
     - applying drops `page` (a new filter starts at page 1);
     - filters compose: every carried key this form does not own (sort,
       direction, a quick filter) rides along as a hidden input.

    Quick filters (`quick`, the old FilterBar chips above the controls) are
    plain links over the same carried query. Each has `params` (null clears a
    key) and optionally `match` (default: its non-null params); it reads as on
    when every match key holds that value (null: is absent), and pressing an
    on chip turns it off again.

        :quick="[['label' => 'Credit sales', 'params' => ['paid' => 'unpaid']]]"

    Active-filter chips ("Showing: Method: Cash ✕") are built from the fields
    themselves: one per field whose key the URL set (it is in
    $list['carried']) and whose resolved value is not empty, plus the period
    when it is not this month. Each ✕ removes only that filter. A field can
    set 'chip' => false, and `active` adds chips for keys no field owns:
    [['key' => 'expiring', 'label' => 'Expiring', 'value' => 'within 30 days', 'alsoClears' => []]].
--}}
@props(['list', 'fields' => [], 'dates' => false, 'presets' => ['today', 'yesterday', 'week', 'last-week', 'month', 'last-month', 'year', 'all'], 'quick' => [], 'active' => [], 'defaultPreset' => 'month'])
@php
    $labels = ['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'last-week' => 'Last week', 'month' => 'This month', 'last-month' => 'Last month', 'year' => 'This year', 'all' => 'Everything', 'custom' => 'Custom range'];
    $owned = $dates ? ['period', 'from', 'to', 'page'] : ['page'];
    foreach ($fields as $field) {
        if ($field['kind'] === 'amount-range') {
            array_push($owned, $field['minKey'], $field['maxKey']);
        } else {
            $owned[] = $field['key'];
            array_key_exists('value', $field) || throw new InvalidArgumentException("Filter field \"{$field['key']}\" needs its resolved value.");
        }
    }
    $hidden = \Illuminate\Support\Arr::except($list['carried'] ?? [], $owned);
    $preset = $list['preset'] ?? 'month';
    $earliest = \App\Support\Filters\Filters::EARLIEST_DATE;

    // Links that change the filter drop the page: a new filter starts at page 1.
    $carried = \Illuminate\Support\Arr::except($list['carried'] ?? [], ['page']);
    $href = fn (array $next): string => '?'.http_build_query(array_filter([...$carried, ...$next], fn ($v) => $v !== null && $v !== ''));

    $quickChips = array_map(function (array $chip) use ($carried, $href): array {
        $match = $chip['match'] ?? array_filter($chip['params'], fn ($v) => $v !== null);
        $on = collect($match)->every(fn ($value, $key) => $value === null ? ! array_key_exists($key, $carried) : ($carried[$key] ?? null) === $value);

        return ['label' => $chip['label'], 'on' => $on, 'href' => $href($on ? array_fill_keys(array_keys($chip['params']), null) : $chip['params'])];
    }, $quick);

    $chips = [];
    if ($dates && ($preset !== $defaultPreset) && array_intersect_key($carried, array_flip(['period', 'from', 'to'])) !== []) {
        $chips[] = ['key' => 'period', 'label' => 'Period', 'value' => \App\Support\Filters\Filters::describeDateRange($list['range'], $preset, \App\Support\Clock::today()), 'alsoClears' => ['from', 'to']];
    }
    foreach ($fields as $field) {
        if (($field['chip'] ?? true) === false) {
            continue;
        }
        if ($field['kind'] === 'amount-range') {
            $min = (string) ($field['min'] ?? '');
            $max = (string) ($field['max'] ?? '');
            if (($min !== '' || $max !== '') && array_intersect_key($carried, array_flip([$field['minKey'], $field['maxKey']])) !== []) {
                $chips[] = ['key' => $field['minKey'], 'label' => $field['label'], 'value' => $min !== '' && $max !== '' ? "{$min} – {$max}" : ($min !== '' ? "over {$min}" : "under {$max}"), 'alsoClears' => [$field['maxKey']]];
            }
            continue;
        }
        $value = (string) ($field['value'] ?? '');
        if ($value === '' || ! array_key_exists($field['key'], $carried)) {
            continue;
        }
        $chips[] = ['key' => $field['key'], 'label' => $field['label'], 'value' => match ($field['kind']) {
            'select' => (string) ($field['options'][$value] ?? $value),
            'date' => \App\View\Format::date($value),
            default => $value,
        }, 'alsoClears' => []];
    }
    $chips = [...$chips, ...$active];
    $hasControls = $fields !== [] || $dates;
@endphp
<section aria-label="Filters" {{ $attributes->class('no-print mb-4 space-y-3') }}>
    @if ($quickChips !== [])
        <div class="flex flex-wrap gap-1.5" aria-label="Quick filters">
            @foreach ($quickChips as $chip)
                <x-button size="sm" :variant="$chip['on'] ? 'primary' : 'secondary'" :href="$chip['href']" :aria-pressed="$chip['on'] ? 'true' : 'false'">{{ $chip['label'] }}</x-button>
            @endforeach
        </div>
    @endif

    @if ($hasControls)
    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-xl border border-line bg-surface-raised p-3" x-data>
        @foreach ($hidden as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach

        @if ($dates)
            <label class="block text-xs text-content-muted">Period
                <select name="period" x-ref="period" class="mt-1 block h-11 rounded-lg border border-line-strong bg-surface-raised px-3 text-sm text-content">
                    @foreach ([...$presets, 'custom'] as $key)
                        <option value="{{ $key }}" @selected($preset === $key)>{{ $labels[$key] }}</option>
                    @endforeach
                </select>
            </label>
            {{-- Typing a date means a custom range; without JavaScript, choose "Custom range" above. --}}
            <label class="block text-xs text-content-muted">From
                <x-date-input name="from" class="mt-1" :value="$list['range']['from'] === $earliest ? null : $list['range']['from']" x-on:change="$refs.period.value = 'custom'" />
            </label>
            <label class="block text-xs text-content-muted">To
                <x-date-input name="to" class="mt-1" :value="$list['range']['to']" x-on:change="$refs.period.value = 'custom'" />
            </label>
        @endif

        @foreach ($fields as $field)
            @switch($field['kind'])
                @case('search')
                    <label @class(['block text-xs text-content-muted', 'min-w-64 flex-1' => $field['wide'] ?? false])>{{ $field['label'] }}
                        <x-input type="search" class="mt-1" :name="$field['key']" :value="$field['value']" :placeholder="$field['placeholder'] ?? ''" />
                    </label>
                    @break
                @case('select')
                    <label class="block text-xs text-content-muted">{{ $field['label'] }}
                        <select name="{{ $field['key'] }}" class="mt-1 block h-11 rounded-lg border border-line-strong bg-surface-raised px-3 text-sm text-content">
                            <option value="">{{ $field['allLabel'] }}</option>
                            @foreach ($field['options'] as $value => $label)
                                <option value="{{ $value }}" @selected((string) $field['value'] === (string) $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    @break
                @case('amount-range')
                    <fieldset class="block text-xs text-content-muted">
                        <legend>{{ $field['label'] }}</legend>
                        <div class="mt-1 flex items-center gap-1">
                            <x-input amount class="w-28" :name="$field['minKey']" :value="$field['min'] ?? ''" placeholder="Min" :aria-label="$field['label'].' from'" />
                            <span aria-hidden="true">–</span>
                            <x-input amount class="w-28" :name="$field['maxKey']" :value="$field['max'] ?? ''" placeholder="Max" :aria-label="$field['label'].' to'" />
                        </div>
                    </fieldset>
                    @break
                @case('date')
                    <label class="block text-xs text-content-muted">{{ $field['label'] }}
                        <x-date-input class="mt-1" :name="$field['key']" :value="$field['value']" :max="$field['max'] ?? null" />
                    </label>
                    @break
            @endswitch
        @endforeach

        <div class="flex items-center gap-2">
            <x-button type="submit" size="md">Apply</x-button>
            <x-button variant="ghost" size="md" href="?">Clear all</x-button>
        </div>
    </form>
    @endif

    @if ($chips !== [])
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="text-xs text-content-subtle">Showing:</span>
            @foreach ($chips as $chip)
                <a href="{{ $href(array_fill_keys([$chip['key'], ...($chip['alsoClears'] ?? [])], null)) }}"
                   class="inline-flex items-center gap-1.5 rounded-full border border-line-strong bg-surface-sunken px-2.5 py-1 text-xs text-content hover:bg-surface-raised focus:outline-none focus-visible:outline-2 focus-visible:outline-accent">
                    <span class="text-content-muted">{{ $chip['label'] }}:</span>
                    <span class="font-medium">{{ $chip['value'] }}</span>
                    <span aria-hidden="true" class="text-content-subtle">✕</span>
                    <span class="sr-only">Remove this filter</span>
                </a>
            @endforeach
        </div>
    @endif
</section>
