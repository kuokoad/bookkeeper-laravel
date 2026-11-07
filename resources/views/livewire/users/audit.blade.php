{{-- The old (app)/users/audit page. --}}
@use('App\Enums\AuditAction')
@use('App\Livewire\Users\Audit')
@use('App\Support\Clock')
@use('App\View\Format')
@php
    $labels = Audit::ACTION_LABELS;
    $today = Clock::today();
@endphp
<div class="w-full">
    <x-page-header title="Audit log" description="Everything that has happened, who did it, and when.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/users">Users</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <x-stat icon="search" label="Records found" :value="(string) $total" />
        <x-stat label="Showing" :value="count($entries).' of '.$total" :hint="'Page '.$list['page'].' of '.$lastPage" />
        <x-stat icon="users" label="People" :value="(string) count($users)" />
    </div>

    <x-filter-bar compact :list="$list" :fields="[
        ['kind' => 'search', 'key' => 'q', 'label' => 'Search', 'placeholder' => 'What happened, or who', 'value' => $resolved['q'] ?? ''],
        ['kind' => 'select', 'key' => 'user', 'label' => 'Person', 'allLabel' => 'Anyone', 'options' => array_column($users, 'displayName', 'id'), 'value' => $resolved['user'] ?? null],
        ['kind' => 'select', 'key' => 'action', 'label' => 'Action', 'allLabel' => 'Anything', 'options' => array_combine(AuditAction::values(), array_map(fn ($v) => $labels[$v] ?? $v, AuditAction::values())), 'value' => $resolved['action'] ?? null],
        ['kind' => 'select', 'key' => 'entity', 'label' => 'Kind of record', 'allLabel' => 'Anything', 'options' => array_combine($entityTypes, array_map(fn ($v) => str_replace('_', ' ', $v), $entityTypes)) ?: [], 'value' => $resolved['entity'] ?? null],
        ['kind' => 'date', 'key' => 'from', 'label' => 'From', 'max' => $today, 'value' => $resolved['from'] ?? null],
        ['kind' => 'date', 'key' => 'to', 'label' => 'To', 'max' => $today, 'value' => $resolved['to'] ?? null],
    ]" />

    @if ($entries->isEmpty())
        <x-empty-state title="Nothing matches" description="Try widening the dates, or clearing the filters." />
    @else
        <x-table>
            <x-slot:head><x-th>When</x-th><x-th>Who</x-th><x-th>Action</x-th><x-th>What happened</x-th><x-th>Record</x-th></x-slot:head>
            @foreach ($entries as $entry)
                <tr class="border-b border-line last:border-0">
                    <x-td><span class="whitespace-nowrap text-content-muted">{{ Format::dateTime($entry->created_at) }}</span></x-td>
                    <x-td><span class="text-content">{{ $entry->username ?? 'System' }}</span></x-td>
                    <x-td><x-badge :tone="Audit::ACTION_TONES[$entry->action] ?? 'neutral'">{{ $labels[$entry->action] ?? $entry->action }}</x-badge></x-td>
                    <x-td>{{ $entry->summary }}</x-td>
                    <x-td><span class="text-xs text-content-subtle">{{ str_replace('_', ' ', $entry->entity_type) }}{{ $entry->entity_id ? " #{$entry->entity_id}" : '' }}</span></x-td>
                </tr>
            @endforeach
        </x-table>
        <x-pagination :list="$list" :total="$total" noun="record" />
    @endif

    <p class="mt-4 text-xs text-content-subtle">Nothing here can be edited or removed — there is no code path in the application that changes or deletes an audit record. Passwords and PINs are never written to it.</p>
</div>
