<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Services\Reporting\LedgerService;
use App\Services\StockAdjustmentService;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The old inventory/adjustments/[id] page: one adjustment, its accounting entry, and voiding it. */
#[Title('Stock adjustment')]
final class Adjustment extends Component
{
    public int $adjustmentId;

    public string $reason = '';

    public function mount(string $id): void
    {
        try {
            StockAdjustmentService::getStockAdjustment((int) $id);
        } catch (NotFoundError) {
            abort(404);
        }
        $this->adjustmentId = (int) $id;
    }

    public function void(): void
    {
        $this->authorize('inventory.void');
        $this->resetErrorBag();
        if (mb_strlen(trim($this->reason)) < 3) {
            $this->addError('reason', 'Give a reason for voiding this adjustment.');

            return;
        }
        try {
            StockAdjustmentService::voidStockAdjustment($this->adjustmentId, trim($this->reason), Principal::load((int) auth()->id())->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }

        session()->flash('status', 'Adjustment voided. The original record was kept and a reversing entry was posted.');
        $this->redirect('/inventory/adjustments');
    }

    public function render(): View
    {
        $data = StockAdjustmentService::getStockAdjustment($this->adjustmentId);
        $entryId = $data['adjustment']->journal_entry_id;

        return view('livewire.inventory.adjustment', [
            'adjustment' => $data['adjustment'],
            'items' => $data['items'],
            // The journal entry this document produced, so the accounting effect is visible.
            'journal' => $entryId === null ? null : LedgerService::getJournalEntry((int) $entryId),
        ])->title($data['adjustment']->adjustment_no);
    }
}
