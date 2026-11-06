<div class="mx-auto max-w-3xl">
    <x-page-header title="Replace with new units"
        description="A new stock item takes over from “{{ $product['name'] }}”: same stock value, same SKU and barcode. The old stock item is archived and its history keeps its old units." />

    @include('livewire.products.partials.product-form', ['submitLabel' => 'Replace stock item', 'showStockNotice' => false])
</div>
