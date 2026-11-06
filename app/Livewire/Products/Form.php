<?php

declare(strict_types=1);

namespace App\Livewire\Products;

use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Services\CatalogService;
use App\Services\UnitsService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The old products/new and products/[id]/edit pages (product-form.tsx,
 * remove-product.tsx): add a product, change one, archive, restore or
 * delete it.
 */
final class Form extends Component
{
    use ProductFields;

    public ?int $productId = null;

    public function mount(?string $id = null): void
    {
        if ($id === null) {
            // An upgraded shop may spell it 'Pcs'; a default the list does not hold would pick whichever sorts first.
            $units = UnitsService::listUnits();
            $this->unit = collect($units)->first(fn (object $u): bool => mb_strtolower($u->symbol) === 'pcs')->symbol ?? ($units[0]->symbol ?? '');

            return;
        }
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
        $this->authorize($this->productId === null ? 'products.create' : 'products.edit');
        $input = $this->productInput();
        if ($input === null) {
            return;
        }
        try {
            if ($this->productId === null) {
                CatalogService::createProduct($input, $this->actor());
            } else {
                // Locked units ride along unchanged, as the old hidden inputs did.
                CatalogService::updateProduct($this->productId, $input, $this->actor());
            }
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }

        session()->flash('status', $this->productId === null
            ? 'Product created. It starts with no stock — record a stock adjustment to enter opening stock.'
            : 'Product updated.');
        $this->redirect('/products');
    }

    public function setActive(bool $isActive): void
    {
        $this->authorize('products.edit');
        try {
            CatalogService::setProductActive((int) $this->productId, $isActive, $this->actor());
        } catch (DomainError $e) {
            $this->addError('remove', $e->userMessage);

            return;
        }
        session()->flash('status', $isActive ? 'Product restored.' : 'Product archived. It is hidden from sales and the product list; its history stays.');
        $this->redirect('/products');
    }

    public function delete(): void
    {
        $this->authorize('products.edit');
        try {
            CatalogService::deleteProduct((int) $this->productId, $this->actor());
        } catch (DomainError $e) {
            $this->addError('delete', $e->userMessage);

            return;
        }
        session()->flash('status', 'Product deleted.');
        $this->redirect('/products');
    }

    public function render(): View
    {
        $product = $this->productId === null ? null : CatalogService::getProduct($this->productId);
        $unitsLocked = $product !== null && CatalogService::productUnitsLocked($product['id']);

        return view('livewire.products.form', [
            ...$this->formOptions($product !== null),
            'product' => $product,
            'unitsLocked' => $unitsLocked,
            'altUnitLocked' => $product !== null && CatalogService::productAltUnitLocked($product['id']),
            'inUse' => $product !== null && CatalogService::isProductInUse($product['id']),
            // The replace page needs these too; do not offer a door that will not open.
            'offerReplace' => $unitsLocked && $product['isActive'] && Gate::allows('products.create') && Gate::allows('inventory.create'),
        ])->title($product === null ? 'Add product' : 'Edit product');
    }
}
