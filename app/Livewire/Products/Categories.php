<?php

declare(strict_types=1);

namespace App\Livewire\Products;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Services\CatalogService;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The old products/categories page with its new-category-form.tsx. */
#[Title('Stock groups')]
final class Categories extends Component
{
    public string $name = '';

    public string $description = '';

    public function add(): void
    {
        $this->authorize('products.create');
        $this->validate(
            ['name' => 'required|string|max:80', 'description' => 'nullable|string|max:300'],
            ['name.required' => 'Enter a stock group name.'],
        );
        try {
            CatalogService::createCategory(['name' => $this->name, 'description' => trim($this->description) === '' ? null : trim($this->description)], Principal::load((int) auth()->id())->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }
        // Cleared so the next one can be typed straight away: this list is usually filled in one sitting.
        $this->reset('name', 'description');
        $this->dispatch('toast', message: 'Stock group added.');
    }

    public function render(): View
    {
        return view('livewire.products.categories', ['categories' => CatalogService::listCategories(true)]);
    }
}
