<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Auth\Principal;
use App\Auth\RoleGuard;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
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
use Livewire\Attributes\Locked;
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

    /** D34: the adjustment being edited, if this is an edit. */
    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?string $editingNo = null;

    public string $editReason = '';

    /** @var list<array{productId: string, direction: string, qty: string, alt: bool, altFactor: ?int, value: string, costSource: string, batchId: string}> */
    public array $rows = [];

    public function mount(?string $id = null): void
    {
        $this->businessDate = Clock::today();
        $this->reason = AdjustmentReason::cases()[0]->value;
        $this->rows = [self::blankRow('IN')];
        if ($id !== null) {
            $this->loadForEdit((int) $id);
        }
    }

    /** The saved adjustment, in the form's own terms, so the owner edits what is there. */
    private function loadForEdit(int $id): void
    {
        abort_unless(RoleGuard::actorIsOwner((int) auth()->id()), 403);
        try {
            $data = StockAdjustmentService::getStockAdjustment($id);
        } catch (NotFoundError) {
            abort(404);
        }
        $adjustment = $data['adjustment'];
        abort_unless($adjustment->status === 'POSTED' && $adjustment->voids_adjustment_id === null, 404);

        $this->editingId = $id;
        $this->editingNo = $adjustment->adjustment_no;
        $this->businessDate = (string) $adjustment->business_date;
        $this->reason = $adjustment->reason;
        $this->note = (string) ($adjustment->note ?? '');
        $this->rows = array_map(fn (object $item): array => [
            'productId' => (string) $item->product_id,
            'direction' => $item->direction,
            // Shown in the unit it was typed in.
            'qty' => Quantity::toInputString($item->entry_unit === null ? (int) $item->qty_milli : (int) $item->entry_qty_milli),
            'alt' => $item->entry_unit !== null,
            'altFactor' => $item->entry_factor_milli === null ? null : (int) $item->entry_factor_milli,
            'value' => $item->direction === 'IN' ? Money::toInputString((int) $item->total_cost_minor) : '',
            'costSource' => 'total',
            'batchId' => $item->batch_id === null ? '' : (string) $item->batch_id,
        ], $data['items']);
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

    public function updatedRows(mixed $value, ?string $key = null): void
    {
        if ($key === null) {
            return; // the whole form put back (keep-form.js), not one field typed
        }
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
        if ($this->editingId === null) {
            $this->authorize('inventory.create');
        } else {
            abort_unless(RoleGuard::actorIsOwner((int) auth()->id()), 403);
        }
        $this->resetErrorBag();
        if ($this->editingId !== null && mb_strlen(trim($this->editReason)) < 3) {
            $this->addError('editReason', 'Say why this adjustment is being edited.');
        }
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

        $input = [
            'businessDate' => $this->businessDate,
            'reason' => $this->reason,
            'note' => trim($this->note) === '' ? null : trim($this->note),
            'items' => $items,
        ];
        $actor = Principal::load((int) auth()->id())->actor();
        try {
            if ($this->editingId !== null) {
                StockAdjustmentService::editStockAdjustment($this->editingId, $input, trim($this->editReason), $actor);
            } else {
                StockAdjustmentService::createStockAdjustment($input, $actor);
            }
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }

        if ($this->editingId !== null) {
            session()->flash('status', "{$this->editingNo} edited. The old lines were reversed and the new ones posted under the same number.");
            $this->redirect("/inventory/adjustments/{$this->editingId}");

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
            'editingNo' => $this->editingNo,
            'editingId' => $this->editingId,
            'products' => collect($products)->keyBy('id')->all(),
            'reasons' => StockAdjustmentService::REASON_LABELS,
            'forcedDirection' => $this->forcedDirection(),
            'currency' => SettingsService::get()->currency_code ?? 'GHS',
        ]);
    }
}
