<?php

declare(strict_types=1);

namespace App\Livewire\Products;

use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Quantity;
use App\Services\CatalogService;
use App\Services\ProductReplaceService;
use App\Support\Clock;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The old products/[id]/replace page: a product whose units are locked is
 * replaced by a new one in the new units, its stock moved across at the same
 * value, the old one archived.
 */
final class Replace extends Component
{
    use ProductFields;

    public int $productId;

    /** The stock the page showed, so a count made against stock that has since moved is refused. */
    public int $expectedQty = 0;

    /** Starts blank on purpose: the old count is in the old unit. */
    public string $newQty = '';

    public function mount(string $id): void
    {
        try {
            $product = CatalogService::getProduct((int) $id);
        } catch (NotFoundError) {
            abort(404);
        }
        $this->productId = $product['id'];
        // Only a locked, active product needs replacing; anything else is just edited.
        if (! $product['isActive'] || ! CatalogService::productUnitsLocked($product['id'])) {
            $this->redirect("/products/{$product['id']}/edit");

            return;
        }
        $this->expectedQty = $product['qtyOnHand'];
        $this->fillFromProduct($product);
    }

    public function save(): void
    {
        $this->authorize('products.edit');
        $this->authorize('products.create');
        $this->authorize('inventory.create');

        // No field when the old product is empty; the service refuses a blank when it is not.
        $extra = [];
        $newQty = 0;
        if (trim($this->newQty) !== '') {
            try {
                $newQty = Quantity::parse(trim($this->newQty));
            } catch (DomainError $e) {
                $extra['newQty'] = $e->userMessage;
            }
        }
        $input = $this->productInput($extra);
        if ($input === null) {
            return;
        }
        try {
            ProductReplaceService::replaceProductUnit($this->productId, $input, ['expectedOldQty' => $this->expectedQty, 'newQty' => $newQty], Clock::today(), $this->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }

        session()->flash('status', 'Product replaced. Any stock it held moved to the new product at the same value, and the old product is archived with its history.');
        $this->redirect('/products');
    }

    public function render(): View
    {
        $product = CatalogService::getProduct($this->productId);

        return view('livewire.products.replace', [
            ...$this->formOptions(true),
            'product' => $product,
            'unitsLocked' => false,
            'altUnitLocked' => false,
            'moveStock' => ['current' => Quantity::formatWithUnit($this->expectedQty, $product['unit']), 'hasStock' => $this->expectedQty > 0],
        ])->title('Replace product');
    }
}
