<?php

declare(strict_types=1);

namespace App\Livewire\Products;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Services\UnitsService;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The old products/units page with its unit-form.tsx: one add form, then
 * each unit edited in place. Forms are a list (index 0 adds), each holding
 * the symbol it was read with, since a symbol may hold dots or spaces.
 */
#[Title('Units')]
final class Units extends Component
{
    /** @var list<array{original: ?string, symbol: string, name: string, decimalPlaces: string}> */
    public array $forms = [];

    public function mount(): void
    {
        $this->resetForms();
    }

    private function resetForms(): void
    {
        $this->forms = [['original' => null, 'symbol' => '', 'name' => '', 'decimalPlaces' => '0']];
        foreach (UnitsService::listUnits() as $unit) {
            $this->forms[] = ['original' => $unit->symbol, 'symbol' => $unit->symbol, 'name' => $unit->name, 'decimalPlaces' => (string) $unit->decimal_places, 'productCount' => $unit->productCount];
        }
    }

    /** Add a unit (form 0) or change one; a rename carries every product using it. */
    public function saveUnit(int $key): void
    {
        $form = $this->forms[$key] ?? null;
        $this->authorize(($form['original'] ?? null) === null ? 'products.create' : 'products.edit');
        if ($form === null) {
            return;
        }
        $this->resetErrorBag();
        $this->validate([
            "forms.{$key}.symbol" => 'required|string|max:20',
            "forms.{$key}.name" => 'required|string|max:60',
            "forms.{$key}.decimalPlaces" => 'required|integer|min:0|max:3',
        ], [
            "forms.{$key}.symbol.required" => 'Enter a symbol, e.g. pcs.',
            "forms.{$key}.name.required" => 'Enter a name, e.g. Pieces.',
        ]);
        $input = ['symbol' => trim($form['symbol']), 'name' => trim($form['name']), 'decimalPlaces' => (int) $form['decimalPlaces']];
        $actor = Principal::load((int) auth()->id())->actor();
        try {
            $form['original'] === null ? UnitsService::createUnit($input, $actor) : UnitsService::updateUnit((string) $form['original'], $input, $actor);
        } catch (DomainError $e) {
            $this->addError("forms.{$key}", $e->userMessage);

            return;
        }
        $this->resetForms();
        $this->dispatch('toast', message: $form['original'] === null ? 'Unit added.' : 'Unit saved.');
    }

    public function deleteUnit(int $key): void
    {
        $this->authorize('products.edit');
        $symbol = $this->forms[$key]['original'] ?? null;
        if ($symbol === null) {
            return;
        }
        try {
            UnitsService::deleteUnit($symbol, Principal::load((int) auth()->id())->actor());
        } catch (DomainError $e) {
            $this->addError("forms.{$key}", $e->userMessage);

            return;
        }
        $this->resetForms();
        $this->dispatch('toast', message: 'Unit deleted.');
    }

    public function render(): View
    {
        return view('livewire.products.units');
    }
}
