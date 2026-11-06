<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Auth\Principal;
use App\Domain\BusinessDate;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Quantity;
use App\Services\InventoryService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The old inventory/batches/[id] page: one crate and everywhere it went, and
 * correcting its date. Quantities only: a batch never carries a cost.
 */
#[Title('Batch')]
final class Batch extends Component
{
    public const SOURCE_LABELS = [
        'PURCHASE' => 'Delivery',
        'PURCHASE_RETURN' => 'Returned to supplier',
        'PURCHASE_VOID' => 'Delivery cancelled',
        'SALE' => 'Sold',
        'SALE_RETURN' => 'Customer returned',
        'SALE_VOID' => 'Sale cancelled',
        'STOCK_ADJUSTMENT' => 'Stock adjustment',
        'OPENING' => 'On the shelf at the start',
    ];

    #[Locked]
    public int $batchId;

    public string $expiryDate = '';

    public function mount(string $id): void
    {
        try {
            $history = InventoryService::getBatchHistory((int) $id);
        } catch (NotFoundError) {
            abort(404);
        }
        $this->batchId = (int) $id;
        $this->expiryDate = (string) ($history['batch']['expiryDate'] ?? '');
    }

    /** inventory:edit, not view: the date decides which stock the till refuses. */
    public function saveExpiry(): void
    {
        $this->authorize('inventory.edit');
        $this->resetErrorBag();
        $raw = trim($this->expiryDate);
        if ($raw !== '' && ! Filters::isValidBusinessDate($raw)) {
            $this->addError('expiryDate', 'Enter a valid date, or leave it blank for no date.');

            return;
        }
        try {
            InventoryService::setBatchExpiry($this->batchId, $raw === '' ? null : $raw, Principal::load((int) auth()->id())->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }
        $this->dispatch('toast', message: 'Date updated.');
    }

    public static function documentHref(array $entry): ?string
    {
        return match (true) {
            $entry['sourceId'] === null => null,
            in_array($entry['sourceType'], ['SALE', 'SALE_RETURN'], true) => "/sales/{$entry['sourceId']}",
            in_array($entry['sourceType'], ['PURCHASE', 'PURCHASE_RETURN'], true) => "/purchases/{$entry['sourceId']}",
            default => null,
        };
    }

    public function render(): View
    {
        ['batch' => $batch, 'entries' => $entries] = InventoryService::getBatchHistory($this->batchId);
        $daysLeft = $batch['expiryDate'] === null ? null : BusinessDate::daysBetween(Clock::today(), $batch['expiryDate']);

        return view('livewire.inventory.batch', [
            'batch' => $batch,
            'entries' => $entries,
            'daysLeft' => $daysLeft,
            'expired' => $daysLeft !== null && $daysLeft < 0,
            // A purchase-opened batch starts empty and is filled by its first delivery.
            'arrivedWith' => $batch['openingQtyMilli'] !== 0 ? $batch['openingQtyMilli'] : Quantity::sum(array_column($entries, 'qtyInMilli')),
        ])->title($batch['batchRef']);
    }
}
