<?php

declare(strict_types=1);

namespace App\Livewire\Products;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Money;
use App\Services\CatalogService;
use App\Services\UnitsService;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Add several stock items at once: one row each, the core fields only.
 * SKU, barcode, alternate unit, reorder level and expiry warning are set
 * later from Edit. All rows are saved or none.
 */
final class Create extends Component
{
    /** @var list<array{name: string, categoryId: string, unit: string, costPrice: string, sellingPrice: string, trackInventory: bool}> */
    public array $rows = [];

    #[Locked]
    public string $defaultUnit = '';

    public function mount(): void
    {
        // An upgraded shop may spell it 'Pcs'; a default the list does not hold would pick whichever sorts first.
        $units = UnitsService::listUnits();
        $this->defaultUnit = collect($units)->first(fn (object $u): bool => mb_strtolower($u->symbol) === 'pcs')->symbol ?? ($units[0]->symbol ?? '');
        $this->addRow();
    }

    /** A new row takes the category and unit of the one above, as items are usually entered in runs. */
    public function addRow(): void
    {
        $last = end($this->rows) ?: null;
        $this->rows[] = [
            'name' => '',
            'categoryId' => $last['categoryId'] ?? '',
            'unit' => $last['unit'] ?? $this->defaultUnit,
            'costPrice' => '0.00',
            'sellingPrice' => '0.00',
            'trackInventory' => true,
        ];
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
        if ($this->rows === []) {
            $this->addRow();
        }
    }

    public function save(): void
    {
        $this->authorize('products.create');
        $this->resetErrorBag();

        $inputs = [];
        $errors = [];
        foreach ($this->rows as $i => $row) {
            $name = trim((string) $row['name']);
            $untouched = $name === '' && in_array(trim((string) $row['costPrice']), ['', '0', '0.00'], true)
                && in_array(trim((string) $row['sellingPrice']), ['', '0', '0.00'], true);
            if ($untouched) {
                continue; // a spare empty row is not a mistake
            }
            if ($name === '') {
                $errors["rows.{$i}.name"] = 'Enter a stock item name.';
            } elseif (mb_strlen($name) > 160) {
                $errors["rows.{$i}.name"] = 'Name must be 160 characters or fewer.';
            }
            if (trim((string) $row['unit']) === '') {
                $errors["rows.{$i}.unit"] = 'Choose a unit.';
            }
            $prices = [];
            foreach (['costPrice', 'sellingPrice'] as $field) {
                try {
                    $prices[$field] = Money::parse(trim((string) $row[$field]));
                } catch (DomainError $e) {
                    $errors["rows.{$i}.{$field}"] = $e->userMessage;
                }
            }
            $inputs[$i] = [
                'name' => $name,
                'sku' => '',
                'barcode' => '',
                'categoryId' => ctype_digit((string) $row['categoryId']) ? (int) $row['categoryId'] : null,
                'unit' => trim((string) $row['unit']),
                'onlyListedUnits' => true,
                'costPrice' => $prices['costPrice'] ?? 0,
                'sellingPrice' => $prices['sellingPrice'] ?? 0,
                'trackInventory' => (bool) $row['trackInventory'],
            ];
        }
        if ($inputs === [] && $errors === []) {
            $errors['rows.0.name'] = 'Enter a stock item name.';
        }
        if ($errors !== []) {
            foreach ($errors as $field => $message) {
                $this->addError($field, $message);
            }

            return;
        }

        try {
            CatalogService::createProducts($inputs, Principal::load((int) auth()->id())->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }

        $count = count($inputs);
        session()->flash('status', ($count === 1 ? 'Stock item created.' : "{$count} stock items created.")
            .' They start with no stock — record a stock adjustment to enter opening stock.');
        $this->redirect('/products');
    }

    public function render(): View
    {
        return view('livewire.products.create', [
            'categories' => CatalogService::listCategories(false),
            'units' => UnitsService::listUnits(),
        ])->title('Add stock items');
    }
}
