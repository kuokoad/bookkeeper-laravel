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
@props(['list', 'fields' => [], 'dates' => false, 'presets' => ['today', 'yesterday', 'week', 'last-week', 'month', 'last-month', 'year', 'all'], 'quick' => [], 'active' => [], 'defaultPreset' => 'month', 'compact' => false, 'all' => null])
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
        }, 'alsoClears' => isset($field['sourceKey']) ? [$field['sourceKey']] : []];
    }
    $chips = [...$chips, ...$active];
    $hasControls = $fields !== [] || $dates;

    // Compact (the old compact-sales-filters.tsx): search and date range up top, the rest behind "Filters (n)".
    $search = collect($fields)->firstWhere('kind', 'search');
    // Plain date fields (From/To, As at) stay in view with their own Apply: on a ledger the date is the main control.
    $dateFields = array_values(array_filter($fields, fn (array $f): bool => $f['kind'] === 'date'));
    $advanced = array_values(array_filter($fields, fn (array $f): bool => ! in_array($f['kind'], ['search', 'date'], true)));
    $grid = $search && $dateFields === [];
    $advancedKeys = array_merge([], ...array_map(fn (array $f): array => $f['kind'] === 'amount-range' ? [$f['minKey'], $f['maxKey']] : [$f['key']], $advanced));
    // A field's `sourceKey` (e.g. statusSource=quick) marks a value a quick button set: not counted here, and dropped when the panel sets it.
    $sourceKeys = array_values(array_filter(array_map(fn (array $f): ?string => $f['sourceKey'] ?? null, $advanced)));
    $quickSet = array_column(array_filter($advanced, fn (array $f): bool => isset($f['sourceKey']) && ($carried[$f['sourceKey']] ?? null) === 'quick'), 'key');
    $advancedCount = count(array_filter($advancedKeys, fn (string $k): bool => ($carried[$k] ?? '') !== '' && ! in_array($k, $quickSet, true)));
    // Submit without the empty fields, as the old app did; without JavaScript the form still submits normally.
    $clean = "window.location.search = new URLSearchParams([...new FormData(\$el)].filter(e => e[1] !== '')).toString()";
    $keep = fn (array $own): array => \Illuminate\Support\Arr::except($list['carried'] ?? [], [...$own, 'page']);
    $allChip = $all === null ? null : ['label' => $all['label'], 'on' => array_intersect_key($carried, array_flip($all['clears'])) === [], 'href' => $href(array_fill_keys($all['clears'], null))];
    $uid = 'filters'; // shortcut: one filter bar per page, so fixed ids; make it a prop if a page ever needs two.
@endphp
<section aria-label="Filters" {{ $attributes->class('no-print mb-4 space-y-3') }}>
    @if ($compact)
        <div class="space-y-3" x-data="{ open: false, custom: @js($preset === 'custom') }">
            <div @class(['grid grid-cols-2 items-end gap-2' => $grid, 'sm:grid-cols-[minmax(0,1fr)_auto_auto]' => $grid && $dates, 'sm:grid-cols-[minmax(0,1fr)_auto]' => $grid && ! $dates, 'flex flex-wrap items-end gap-2' => ! $grid])>
                @if ($search)
                    <form method="GET" @class(['flex min-w-0 items-end gap-2', 'col-span-2 sm:col-span-1' => $grid, 'min-w-64 flex-1' => ! $grid]) x-on:submit.prevent="{{ $clean }}">
                        @foreach ($keep([$search['key']]) as $key => $value)
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endforeach
                        <div class="min-w-0 flex-1">
                            <label for="{{ $uid }}-search" class="mb-1 block text-xs text-content-muted">Search</label>
                            <x-input id="{{ $uid }}-search" type="search" autocomplete="off" :name="$search['key']" :value="$search['value']" :placeholder="$search['placeholder'] ?? ''" />
                        </div>
                        <x-button type="submit" variant="secondary">Search</x-button>
                    </form>
                @endif
                @if ($dates)
                    {{-- A preset applies at once; "Custom range" opens the From/To row below, which submits this same form. --}}
                    <form method="GET" id="{{ $uid }}-dates" x-on:submit.prevent="{{ $clean }}">
                        @foreach ($keep(['period', 'from', 'to']) as $key => $value)
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endforeach
                        <label for="{{ $uid }}-period" class="mb-1 block text-xs text-content-muted">Date range</label>
                        <select id="{{ $uid }}-period" name="period" x-on:change="custom = $event.target.value === 'custom'; custom || $nextTick(() => $el.form.requestSubmit())"
                                class="block h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-sm text-content">
                            @foreach ([...$presets, 'custom'] as $key)
                                <option value="{{ $key }}" @selected($preset === $key)>{{ $labels[$key] }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
                @if ($dateFields !== [])
                    <form method="GET" class="flex flex-wrap items-end gap-2" x-on:submit.prevent="{{ $clean }}">
                        @foreach ($keep(array_column($dateFields, 'key')) as $key => $value)
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endforeach
                        @foreach ($dateFields as $field)
                            <div>
                                <label for="{{ $uid }}-{{ $field['key'] }}" class="mb-1 block text-xs text-content-muted">{{ $field['label'] }}</label>
                                <x-date-input id="{{ $uid }}-{{ $field['key'] }}" :name="$field['key']" :value="$field['value']" :max="$field['max'] ?? null" />
                            </div>
                        @endforeach
                        <x-button type="submit" variant="secondary">Apply</x-button>
                    </form>
                @endif
                @if ($advanced !== [])
                    <x-button type="button" variant="secondary" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-expanded="false" aria-controls="{{ $uid }}-panel">
                        Filters{{ $advancedCount > 0 ? " ({$advancedCount})" : '' }}
                    </x-button>
                @endif
            </div>

            @if ($dates)
                <div class="flex flex-wrap items-end gap-2" x-show="custom" x-cloak>
                    <div>
                        <label for="{{ $uid }}-from" class="mb-1 block text-xs text-content-muted">From</label>
                        <x-date-input id="{{ $uid }}-from" name="from" form="{{ $uid }}-dates" x-bind:disabled="! custom" :value="$list['range']['from'] === $earliest ? null : $list['range']['from']" />
                    </div>
                    <div>
                        <label for="{{ $uid }}-to" class="mb-1 block text-xs text-content-muted">To</label>
                        <x-date-input id="{{ $uid }}-to" name="to" form="{{ $uid }}-dates" x-bind:disabled="! custom" :value="$list['range']['to']" />
                    </div>
                    <x-button type="submit" variant="secondary" form="{{ $uid }}-dates">Apply dates</x-button>
                </div>
            @endif

            @if ($allChip || $quickChips !== [])
                <div class="flex flex-wrap gap-1.5" aria-label="Quick filters">
                    @foreach (array_filter([$allChip, ...$quickChips]) as $chip)
                        <x-button size="sm" :variant="$chip['on'] ? 'primary' : 'secondary'" :href="$chip['href']" :aria-pressed="$chip['on'] ? 'true' : 'false'">{{ $chip['label'] }}</x-button>
                    @endforeach
                </div>
            @endif

            @if ($advanced !== [])
                <div id="{{ $uid }}-panel" x-show="open" x-cloak class="rounded-[var(--panel-radius,var(--radius-xl))] border border-line bg-surface-raised p-3">
                    <form method="GET" x-on:submit.prevent="{{ $clean }}">
                        @foreach ($keep([...$advancedKeys, ...$sourceKeys]) as $key => $value)
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endforeach
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            @foreach ($advanced as $field)
                                @switch($field['kind'])
                                    @case('select')
                                        <div>
                                            <label for="{{ $uid }}-{{ $field['key'] }}" class="mb-1 block text-xs text-content-muted">{{ $field['label'] }}</label>
                                            <select id="{{ $uid }}-{{ $field['key'] }}" name="{{ $field['key'] }}" class="block h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-sm text-content">
                                                <option value="">{{ $field['allLabel'] }}</option>
                                                @foreach ($field['options'] as $value => $label)
                                                    <option value="{{ $value }}" @selected((string) $field['value'] === (string) $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        @break
                                    @case('amount-range')
                                        @foreach ([[$field['minKey'], 'Minimum', $field['min'] ?? ''], [$field['maxKey'], 'Maximum', $field['max'] ?? '']] as [$key, $word, $value])
                                            <div>
                                                <label for="{{ $uid }}-{{ $key }}" class="mb-1 block text-xs text-content-muted">{{ $word }} {{ mb_strtolower($field['label']) }} ({{ \App\View\Format::shopCurrency() }})</label>
                                                <x-input amount id="{{ $uid }}-{{ $key }}" :name="$key" :value="$value" />
                                            </div>
                                        @endforeach
                                        @break
                                @endswitch
                            @endforeach
                        </div>
                        <div class="mt-3 flex gap-2">
                            <x-button type="submit">Apply Filters</x-button>
                            <x-button variant="ghost" href="?">Clear Filters</x-button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    @endif

    @if (! $compact && $quickChips !== [])
        <div class="flex flex-wrap gap-1.5" aria-label="Quick filters">
            @foreach ($quickChips as $chip)
                <x-button size="sm" :variant="$chip['on'] ? 'primary' : 'secondary'" :href="$chip['href']" :aria-pressed="$chip['on'] ? 'true' : 'false'">{{ $chip['label'] }}</x-button>
            @endforeach
        </div>
    @endif

    @if (! $compact && $hasControls)
    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-[var(--panel-radius,var(--radius-xl))] border border-line bg-surface-raised p-3" x-data>
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
