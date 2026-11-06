{{-- The permission matrix (the old shared/permission-matrix.tsx). Expects $rows (MODULE_ROWS), $sensitive, $grantable (null = owner), $disabled. --}}
<div>
    @unless ($disabled)
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <span class="text-xs text-content-muted">Start from:</span>
            <x-button size="sm" variant="secondary" wire:click="applyPreset('till')">Till staff</x-button>
            <x-button size="sm" variant="secondary" wire:click="applyPreset('manager')">Shop manager</x-button>
            <x-button size="sm" variant="ghost" wire:click="applyPreset('none')">Clear all</x-button>
        </div>
    @endunless

    <x-table>
        <x-slot:head>
            <x-th>Area</x-th><x-th>See</x-th><x-th>Add</x-th><x-th>Change</x-th><x-th>Void</x-th>
        </x-slot:head>
        @foreach ($rows as $module => [$label, $description])
            <tr class="border-b border-line last:border-0" wire:key="perm-{{ $module }}">
                <x-td>
                    <span class="font-medium text-content">{{ $label }}</span>
                    @if (in_array($module, $sensitive, true))
                        <span class="ml-2 rounded bg-warning-soft px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-content">Sensitive</span>
                    @endif
                    <span class="mt-0.5 block text-xs text-content-subtle">{{ $description }}</span>
                </x-td>
                @foreach (['canView' => 'view', 'canCreate' => 'create', 'canEdit' => 'edit', 'canVoid' => 'void'] as $flag => $suffix)
                    @php($mayGrant = $grantable === null || ($grantable[$module][$flag] ?? false))
                    <x-td>
                        <input type="checkbox" wire:model.live="perms.{{ $module }}.{{ $flag }}" aria-label="{{ $label }}: {{ $suffix }}"
                               @disabled($disabled || ! $mayGrant) @unless ($mayGrant) title="You do not have this yourself, so you cannot give it away." @endunless
                               class="h-4 w-4 rounded border-line-strong disabled:opacity-50">
                    </x-td>
                @endforeach
            </tr>
        @endforeach
    </x-table>

    <p class="mt-3 text-xs text-content-subtle">&ldquo;Void&rdquo; means cancelling something already recorded, which moves money and stock. Give it only to people you would trust to correct the books.</p>
    @if ($grantable !== null)
        <p class="mt-2 text-xs text-content-subtle">The greyed-out boxes are things you do not have yourself, so they are not yours to give away. An owner can set those.</p>
    @endif
</div>
