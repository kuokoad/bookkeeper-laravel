<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Auth\Principal;
use App\Auth\RoleGuard;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Services\AuditService;
use App\Services\Reporting\LedgerService;
use App\Services\StockAdjustmentService;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The old inventory/adjustments/[id] page: one adjustment, its accounting entry, and voiding it. */
#[Title('Stock adjustment')]
final class Adjustment extends Component
{
    #[Locked]
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
        $isOwner = RoleGuard::actorIsOwner((int) auth()->id());

        return view('livewire.inventory.adjustment', [
            'adjustment' => $data['adjustment'],
            'items' => $data['items'],
            // The journal entry this document produced, so the accounting effect is visible.
            'journal' => $entryId === null ? null : LedgerService::getJournalEntry((int) $entryId),
            // D34: only the owner edits, and only a live adjustment that is not itself a reversal.
            'canEdit' => $isOwner && $data['adjustment']->status === 'POSTED' && $data['adjustment']->voids_adjustment_id === null,
            'history' => $isOwner ? AuditService::editHistory('stock_adjustment', $this->adjustmentId) : [],
        ])->title($data['adjustment']->adjustment_no);
    }
}
