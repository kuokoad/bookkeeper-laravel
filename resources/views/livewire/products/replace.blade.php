<div class="mx-auto max-w-3xl">
    <x-page-header title="Replace with new units"
        description="A new product takes over from “{{ $product['name'] }}”: same stock value, same SKU and barcode. The old product is archived and its history keeps its old units." />

    @include('livewire.products.partials.product-form', ['submitLabel' => 'Replace product', 'showStockNotice' => false])
</div>
