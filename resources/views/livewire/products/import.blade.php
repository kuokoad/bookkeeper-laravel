@php
    $money = fn (?int $value): string => $value === null ? '' : \App\Domain\Money::toDecimalString($value);
    $qty = fn (int $value): string => \App\Domain\Quantity::format($value);
    $selectClass = 'h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content';
@endphp
<div class="w-full">
    <x-page-header title="Import stock items" description="Add many stock items at once from an Excel (.xlsx) or CSV price list. Nothing is saved until you have checked the preview." />

    <div class="space-y-6">
        @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror

        <form wire:submit="read" class="rounded-xl border border-line bg-surface-raised p-4">
            <div class="flex flex-wrap items-end gap-3">
                <x-field label="Price list" for="file" class="min-w-0 flex-1" hint="The first sheet is read. Headings go in row 1, one stock item per row below. Up to 2 MB.">
                    <input id="file" type="file" wire:model="file" accept=".xlsx,.csv" required
                        class="block w-full text-sm text-content file:mr-3 file:rounded-lg file:border file:border-line-strong file:bg-surface-sunken file:px-3 file:py-2 file:text-content">
                </x-field>
                <x-button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="file,read">
                    <span wire:loading.remove wire:target="read">Read file</span>
                    <span wire:loading wire:target="read">Reading…</span>
                </x-button>
            </div>
            <p class="mt-3 text-sm text-content-muted">
                Starting from scratch? <a href="/products/import/template" class="font-medium text-accent underline">Download the template</a>, fill it in, and read it back here.
            </p>
        </form>

        @if ($cells !== null)
            <div class="space-y-6">
                <section class="rounded-xl border border-line bg-surface-raised p-4">
                    <h2 class="mb-1 font-semibold text-content">Columns in {{ $fileName }}</h2>
                    <p class="mb-4 text-sm text-content-muted">
                        Check each field is read from the right column. Opening quantities go in as one opening stock adjustment, valued at the opening value or, where none is given, at cost.
                    </p>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach (\App\Services\ProductImportService::IMPORT_FIELDS as $key => $label)
                            <x-field :label="$label" for="column_{{ $key }}" :required="$key === 'name'">
                                <select id="column_{{ $key }}" wire:model="map.{{ $key }}" class="{{ $selectClass }}">
                                    <option value="">Not imported</option>
                                    @for ($i = 0; $i < $columns; $i++)
                                        <option value="{{ $i }}">{{ \App\Livewire\Products\Import::columnName($i, (string) ($header[$i] ?? '')) }}</option>
                                    @endfor
                                </select>
                            </x-field>
                        @endforeach
                        <x-field label="Pack unit" for="packUnit" hint="What a pack is called, e.g. pack, carton, box. Used with units per pack.">
                            <x-input id="packUnit" wire:model="packUnit" maxlength="20" />
                        </x-field>
                    </div>
                    <div class="mt-4">
                        <x-button variant="secondary" wire:click="preview" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="preview">Update preview</span>
                            <span wire:loading wire:target="preview">Checking…</span>
                        </x-button>
                    </div>
                </section>

                @if ($preview !== null)
                    @foreach ($preview['warnings'] as $warning)
                        <x-alert tone="warning">{{ $warning }}</x-alert>
                    @endforeach
                    @if ($preview['newUnits'] !== [])
                        <x-alert tone="info">These units are not on your Units of measure list and will be added: {{ implode(', ', $preview['newUnits']) }}.</x-alert>
                    @endif

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm text-content">
                            <strong>{{ $preview['ready'] }}</strong> ready to import{!! $preview['skipped'] > 0 ? ', <strong>'.e($preview['skipped']).'</strong> will be skipped' : '' !!}.
                            @if ($preview['openingValue'] > 0)
                                Opening stock worth <strong>{{ $money($preview['openingValue']) }}</strong> goes to Inventory.
                            @endif
                        </p>
                        <div class="flex gap-2">
                            <x-button variant="secondary" href="/products">Cancel</x-button>
                            <x-button wire:click="import" wire:loading.attr="disabled" :disabled="$preview['ready'] === 0">
                                <span wire:loading.remove wire:target="import">Import {{ $preview['ready'] }} {{ \App\View\Format::plural($preview['ready'], 'product') }}</span>
                                <span wire:loading wire:target="import">Importing…</span>
                            </x-button>
                        </div>
                    </div>

                    <x-table>
                        <x-slot:head>
                            <x-th>Row</x-th>
                            <x-th>Status</x-th>
                            <x-th>Stock item</x-th>
                            <x-th>Code</x-th>
                            <x-th>Unit</x-th>
                            <x-th numeric>Cost</x-th>
                            <x-th numeric>Price</x-th>
                            <x-th>Pack</x-th>
                            <x-th numeric>Pack cost</x-th>
                            <x-th numeric>Pack price</x-th>
                            <x-th>Stock group</x-th>
                            <x-th numeric>Opening qty</x-th>
                            <x-th numeric>Opening value</x-th>
                            <x-th>Notes</x-th>
                        </x-slot:head>
                        @foreach ($preview['rows'] as $row)
                            <tr @class(['border-b border-line last:border-0', 'bg-danger-soft' => $row['problems'] !== []]) wire:key="row-{{ $row['line'] }}">
                                <x-td class="text-content-subtle">{{ $row['line'] }}</x-td>
                                <x-td>
                                    @if ($row['problems'] !== [])
                                        <x-badge tone="danger">Skipped</x-badge>
                                    @else
                                        <x-badge tone="success">Ready</x-badge>
                                    @endif
                                </x-td>
                                <x-td class="font-medium">{{ $row['name'] }}</x-td>
                                <x-td>{{ $row['sku'] }}</x-td>
                                <x-td>{{ $row['unit'] }}</x-td>
                                <x-td numeric>{{ $money($row['costPrice']) }}</x-td>
                                <x-td numeric>{{ $money($row['sellingPrice']) }}</x-td>
                                <x-td>{{ $row['altUnit'] && $row['altFactor'] !== null ? '1 '.$row['altUnit'].' = '.$qty($row['altFactor']).' '.$row['unit'] : '' }}</x-td>
                                <x-td numeric>{{ $money($row['altCostPrice']) }}</x-td>
                                <x-td numeric>{{ $money($row['altSellingPrice']) }}</x-td>
                                <x-td>{{ $row['categoryName'] ?? '' }}</x-td>
                                <x-td numeric>{{ $row['openingQty'] !== null ? $qty($row['openingQty']).' '.$row['unit'] : ($row['trackInventory'] ? '' : 'Not tracked') }}</x-td>
                                <x-td numeric>{{ $money($row['openingValue']) }}</x-td>
                                <x-td class="max-w-md whitespace-normal text-xs">
                                    @foreach ($row['problems'] as $problem)<p class="font-medium text-danger">{{ $problem }}</p>@endforeach
                                    @foreach ($row['notes'] as $note)<p class="text-content-muted">{{ $note }}</p>@endforeach
                                </x-td>
                            </tr>
                        @endforeach
                    </x-table>
                @endif
            </div>
        @endif
    </div>
</div>
