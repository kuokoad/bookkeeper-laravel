<?php

declare(strict_types=1);

namespace App\Livewire\Products;

use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Services\CatalogService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The old products/[id]/edit page (product-form.tsx, remove-product.tsx):
 * change a product, archive, restore or delete it. Adding is Create.
 */
final class Form extends Component
{
    use ProductFields;

    #[Locked]
    public int $productId = 0;

    public function mount(string $id): void
    {
        try {
            $product = CatalogService::getProduct((int) $id);
        } catch (NotFoundError) {
            abort(404);
        }
        $this->productId = $product['id'];
        $this->fillFromProduct($product);
    }

    public function save(): void
    {
        $this->authorize('products.edit');
        $input = $this->productInput();
        if ($input === null) {
            return;
        }
        try {
            // Locked units ride along unchanged, as the old hidden inputs did.
            CatalogService::updateProduct($this->productId, $input, $this->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }

        session()->flash('status', 'Stock item updated.');
        $this->redirect('/products');
    }

    public function setActive(bool $isActive): void
    {
        $this->authorize('products.edit');
        try {
            CatalogService::setProductActive($this->productId, $isActive, $this->actor());
        } catch (DomainError $e) {
            $this->addError('remove', $e->userMessage);

            return;
        }
        session()->flash('status', $isActive ? 'Stock item restored.' : 'Stock item archived. It is hidden from sales and the stock item list; its history stays.');
        $this->redirect('/products');
    }

    public function delete(): void
    {
        $this->authorize('products.edit');
        try {
            CatalogService::deleteProduct($this->productId, $this->actor());
        } catch (DomainError $e) {
            $this->addError('delete', $e->userMessage);

            return;
        }
        session()->flash('status', 'Stock item deleted.');
        $this->redirect('/products');
    }

    public function render(): View
    {
        $product = CatalogService::getProduct($this->productId);
        $unitsLocked = CatalogService::productUnitsLocked($product['id']);

        return view('livewire.products.form', [
            ...$this->formOptions(true),
            'product' => $product,
            'unitsLocked' => $unitsLocked,
            'altUnitLocked' => CatalogService::productAltUnitLocked($product['id']),
            'inUse' => CatalogService::isProductInUse($product['id']),
            // The replace page needs these too; do not offer a door that will not open.
            'offerReplace' => $unitsLocked && $product['isActive'] && Gate::allows('products.create') && Gate::allows('inventory.create'),
        ])->title('Edit stock item');
    }
}
