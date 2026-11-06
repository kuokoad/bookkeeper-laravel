<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Enums\AdjustmentReason;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Services\SettingsService;
use App\Services\StockAdjustmentService;
use App\Support\Clock;
use App\Support\CostFields;
use App\Support\Filters\Filters;
use App\View\Format;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The old inventory/adjustments/new page and adjustment-form.tsx: stock in or
 * out for any reason other than a sale or purchase. Stock out is valued by
 * the service at weighted average; stock in takes a unit cost or a total,
 * the other worked out by CostFields, and only the total is posted.
 */
#[Title('New stock adjustment')]
final class NewAdjustment extends Component
{
    public string $businessDate = '';

    public string $reason = 'OPENING_STOCK';

    public string $note = '';

    /** @var list<array{productId: string, direction: string, qty: string, alt: bool, altFactor: ?int, value: string, costSource: string, batchId: string}> */
    public array $rows = [];

    public function mount(): void
    {
        $this->businessDate = Clock::today();
        $this->reason = AdjustmentReason::cases()[0]->value;
        $this->rows = [self::blankRow('IN')];
    }

    private static function blankRow(string $direction): array
    {
        return ['productId' => '', 'direction' => $direction, 'qty' => '', 'alt' => false, 'altFactor' => null, 'value' => '', 'costSource' => 'total', 'batchId' => ''];
    }

    private function forcedDirection(): ?string
    {
        return StockAdjustmentService::REASON_DEFAULT_DIRECTION[$this->reason] ?? null;
    }

    public function updatedReason(): void
    {
        $direction = $this->forcedDirection();
        foreach ($this->rows as $i => $row) {
            if ($direction !== null) {
                $this->rows[$i]['direction'] = $direction;
            }
            // A crate only means anything on an expired write-off; the service refuses it elsewhere.
            if ($this->reason !== 'EXPIRED') {
                $this->rows[$i]['batchId'] = '';
            }
        }
    }

    public function updatedRows(mixed $value, string $key): void
    {
        [$i, $field] = explode('.', $key) + [1 => ''];
        if ($field === 'productId' && isset($this->rows[$i])) {
            // The crate and unit belong to the old product; the conversion shown is remembered for the stale check.
            $this->rows[$i]['batchId'] = '';
            $this->rows[$i]['alt'] = false;
            $product = $value === '' ? null : collect($this->trackedProducts())->firstWhere('id', (int) $value);
            $this->rows[$i]['altFactor'] = $product['altFactor'] ?? null;
        }
    }

    public function setCost(int $index, string $source, string $typed): void
    {
        if (isset($this->rows[$index]) && in_array($source, ['unit', 'total'], true)) {
            $this->rows[$index]['value'] = $typed;
            $this->rows[$index]['costSource'] = $source;
        }
    }

    public function addRow(): void
    {
        $this->rows[] = self::blankRow($this->forcedDirection() ?? 'OUT');
    }

    public function removeRow(int $index): void
    {
        if (count($this->rows) > 1) {
            unset($this->rows[$index]);
            $this->rows = array_values($this->rows);
        }
    }

    public function save(): void
    {
        $this->authorize('inventory.create');
        $this->resetErrorBag();
        if (! Filters::isValidBusinessDate($this->businessDate)) {
            $this->addError('businessDate', 'Enter a valid date.');
        }
        if (AdjustmentReason::tryFrom($this->reason) === null) {
            $this->addError('reason', 'Choose a reason for the adjustment.');
        }
        if (mb_strlen(trim($this->note)) > 300) {
            $this->addError('note', 'The note must not be greater than 300 characters.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $items = [];
        foreach (array_values($this->rows) as $index => $row) {
            $line = $index + 1;
            $rawProduct = trim((string) $row['productId']);
            $rawQty = trim((string) $row['qty']);
            // A blank row is simply not part of the document.
            if ($rawProduct === '' || $rawQty === '') {
                continue;
            }
            $productId = Filters::parseId($rawProduct);
            if ($productId === null) {
                $this->addError('form', "Line {$line}: choose a stock item.");

                return;
            }
            $direction = $this->forcedDirection() ?? $row['direction'];
            if (! in_array($direction, ['IN', 'OUT'], true)) {
                $this->addError('form', "Line {$line}: choose whether stock is going in or out.");

                return;
            }
            try {
                $qty = Quantity::parsePositive($rawQty, "Line {$line} quantity");
                $altEntry = Quantity::parseAltEntry((bool) $row['alt'], $row['alt'] ? $row['altFactor'] : null, "Line {$line}");
                if ($direction === 'IN') {
                    $total = CostFields::of($row['costSource'], (string) $row['value'], $rawQty)['total'];
                    if (trim($total) === '') {
                        $this->addError('form', "Line {$line}: enter the total value of the stock being added.");

                        return;
                    }
                    $items[] = ['productId' => $productId, 'direction' => 'IN', 'qty' => $qty, ...$altEntry, 'totalCost' => Money::parse($total)];
                } else {
                    // Value is computed from the weighted average, never taken from the form.
                    $batchId = $this->reason === 'EXPIRED' && $row['batchId'] !== '' ? Filters::parseId((string) $row['batchId']) : null;
                    $items[] = ['productId' => $productId, 'direction' => 'OUT', 'qty' => $qty, ...$altEntry, ...($batchId === null ? [] : ['batchId' => $batchId])];
                }
            } catch (DomainError $e) {
                $this->addError('form', $e->userMessage);

                return;
            }
        }
        if ($items === []) {
            $this->addError('form', 'Add at least one stock item to the adjustment.');

            return;
        }

        try {
            StockAdjustmentService::createStockAdjustment([
                'businessDate' => $this->businessDate,
                'reason' => $this->reason,
                'note' => trim($this->note) === '' ? null : trim($this->note),
                'items' => $items,
            ], Principal::load((int) auth()->id())->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }

        session()->flash('status', 'Adjustment saved. Stock and the accounts were updated together.');
        $this->redirect('/inventory/adjustments');
    }

    /** @return list<array> */
    private function trackedProducts(): array
    {
        // shortcut: the first 500 active products, the service's ceiling; a picker with search would lift it.
        return array_values(array_filter(CatalogService::listProducts(['limit' => 500]), fn (array $p): bool => $p['trackInventory']));
    }

    public function render(): View
    {
        // Which crates have actually turned, so a write-off can name one instead of the good crate.
        $expired = InventoryService::listExpiredBatches(Clock::today());
        $products = array_map(fn (array $p): array => [
            ...$p,
            'qtyOnHandLabel' => Quantity::formatInBothUnits($p['qtyOnHand'], $p['unit'], $p['altUnit'], $p['altFactor']).' on hand',
            'expiredBatches' => array_values(array_map(
                fn (array $b): array => ['id' => $b['id'], 'label' => "{$b['batchRef']} — ".Format::qty($b['qtyMilli'], $p['unit']).', expired '.Format::date($b['expiryDate'])],
                array_filter($expired, fn (array $b): bool => $b['productId'] === $p['id']),
            )),
        ], $this->trackedProducts());

        return view('livewire.inventory.new-adjustment', [
            'products' => collect($products)->keyBy('id')->all(),
            'reasons' => StockAdjustmentService::REASON_LABELS,
            'forcedDirection' => $this->forcedDirection(),
            'currency' => SettingsService::get()->currency_code ?? 'GHS',
        ]);
    }
}
