<?php

declare(strict_types=1);

namespace App\Livewire\Purchases;

use App\Auth\Principal;
use App\Auth\RoleGuard;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Services\AuditService;
use App\Services\PaymentAccountService;
use App\Services\PurchaseService;
use App\Services\Reporting\LedgerService;
use App\Services\ReturnsService;
use App\Services\SettingsService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The old purchases/[id]/page.tsx: one delivery, its accounting entry,
 * returning goods to the supplier (shared/return-form.tsx) and voiding it
 * (shared/confirm-void-form.tsx).
 */
#[Title('Purchase')]
final class Show extends Component
{
    #[Locked]
    public int $purchaseId;

    public string $reason = '';

    public string $returnDate = '';

    /** @var array<int|string, string> purchase item id => return qty as typed */
    public array $returnQty = [];

    public string $refundAccountId = '';

    public string $refundAmount = '';

    public string $returnReason = '';

    public function mount(string $id): void
    {
        try {
            PurchaseService::getPurchase((int) $id);
        } catch (NotFoundError) {
            abort(404);
        }
        $this->purchaseId = (int) $id;
        $this->returnDate = Clock::today();
        $accounts = PaymentAccountService::listPaymentAccounts();
        $default = collect($accounts)->firstWhere('isDefault', true) ?? ($accounts[0] ?? null);
        $this->refundAccountId = $default === null ? '' : (string) $default['id'];
    }

    public function void(): void
    {
        $this->authorize('purchases.void');
        $this->resetErrorBag();
        $reason = trim($this->reason);
        if (mb_strlen($reason) < 3) {
            $this->addError('reason', 'Give a reason for voiding this purchase.');

            return;
        }
        try {
            PurchaseService::voidPurchase($this->purchaseId, $reason, Principal::load((int) auth()->id())->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }
        session()->flash('status', 'Purchase voided. The original record was kept and a reversing entry posted.');
        $this->redirect("/purchases/{$this->purchaseId}");
    }

    /** The old createSupplierReturnAction: blank lines are not part of the return; any invalid line refuses it all. */
    public function recordReturn(): void
    {
        $this->authorize('purchases.create');
        $this->resetErrorBag();
        if (! Filters::isValidBusinessDate($this->returnDate)) {
            $this->addError('returnDate', 'Enter a valid date.');

            return;
        }
        $lines = [];
        $number = 0;
        foreach (ReturnsService::getReturnablePurchaseItems($this->purchaseId) as $item) {
            $number++;
            $raw = trim((string) ($this->returnQty[$item['id']] ?? ''));
            if ($raw === '' || $raw === '0') {
                continue;
            }
            try {
                $lines[] = ['itemId' => $item['id'], 'qty' => Quantity::parsePositive($raw, "Line {$number} quantity")];
            } catch (DomainError $e) {
                $this->addError('returnForm', $e->userMessage);

                return;
            }
        }
        if ($lines === []) {
            $this->addError('returnForm', 'Enter a quantity for at least one item.');

            return;
        }
        $refunds = [];
        if (trim($this->refundAmount) !== '' && ctype_digit($this->refundAccountId) && (int) $this->refundAccountId > 0) {
            try {
                $amount = Money::parse($this->refundAmount);
            } catch (DomainError $e) {
                $this->addError('refundAmount', $e->userMessage);

                return;
            }
            if ($amount > 0) {
                $refunds[] = ['paymentAccountId' => (int) $this->refundAccountId, 'amount' => $amount];
            }
        }
        try {
            ReturnsService::createSupplierReturn($this->purchaseId, [
                'businessDate' => $this->returnDate,
                'items' => $lines,
                'refunds' => $refunds,
                ...(trim($this->returnReason) !== '' ? ['reason' => trim($this->returnReason)] : []),
            ], Principal::load((int) auth()->id())->actor());
        } catch (DomainError $e) {
            $this->addError('returnForm', $e->userMessage);

            return;
        }
        session()->flash('status', 'Return recorded. Stock went out at what it cost from this supplier.');
        $this->redirect("/purchases/{$this->purchaseId}");
    }

    public function render(): View
    {
        $purchase = PurchaseService::getPurchase($this->purchaseId);
        $isOwner = RoleGuard::actorIsOwner((int) auth()->id());
        $returnable = $purchase['kind'] === 'PURCHASE' && $purchase['status'] === 'POSTED' ? ReturnsService::getReturnablePurchaseItems($this->purchaseId) : [];

        // The return form's estimate, as the old one: each typed quantity × the supplier's unit cost.
        $estimate = 0;
        foreach ($returnable as $item) {
            try {
                $milli = Quantity::parse(trim((string) ($this->returnQty[$item['id']] ?? '')) ?: '0');
                $estimate = $milli > 0 ? Money::add($estimate, Quantity::extendPrice($item['unitCostMinor'], $milli)) : $estimate;
            } catch (DomainError) {
                // An unreadable box adds nothing; the box itself says so.
            }
        }

        return view('livewire.purchases.show', [
            'purchase' => $purchase,
            'currency' => SettingsService::get()->currency_code ?? 'GHS',
            'paid' => $purchase['paidMinor'],
            'journal' => $purchase['journalEntryId'] === null ? null : LedgerService::getJournalEntry((int) $purchase['journalEntryId']),
            'returnable' => $returnable,
            'estimate' => $estimate,
            'accounts' => PaymentAccountService::listPaymentAccounts(),
            // D34: only the owner edits, and only a live purchase.
            'canEdit' => $isOwner && $purchase['kind'] === 'PURCHASE' && $purchase['status'] === 'POSTED',
            'history' => $isOwner ? AuditService::editHistory('purchase', $this->purchaseId) : [],
        ])->title($purchase['purchaseNo']);
    }
}
