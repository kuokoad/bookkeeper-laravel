<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Auth\Principal;
use App\Auth\RoleGuard;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Domain\Sales\SaleDate;
use App\Services\Actor;
use App\Services\AuditService;
use App\Services\PaymentAccountService;
use App\Services\Reporting\LedgerService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use App\Services\SettingsService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One sale: the old (app)/sales/[id]/page.tsx with its void form and the
 * customer return form. Every figure is the service's; the writes go through
 * SaleService::voidSale and ReturnsService::createCustomerReturn.
 */
#[Title('Sale')]
final class Show extends Component
{
    #[Locked]
    public int $saleId;

    public string $reason = '';

    /** Names this page's return form, so a second press after a lost reply is the same return, not another. */
    #[Locked]
    public string $formToken = '';

    // --- the customer return form ---------------------------------------
    /** @var array<int|string, string> typed quantity by sale item id */
    public array $returnQty = [];

    public string $returnDate = '';

    public string $refundAccountId = '';

    public string $refundAmount = '';

    public string $returnReason = '';

    public function mount(int $id): void
    {
        $this->saleId = $id;
        $this->formToken = bin2hex(random_bytes(8));
        $this->sale();
        $this->returnDate = Clock::today();
        $accounts = PaymentAccountService::listPaymentAccounts();
        $default = collect($accounts)->firstWhere('isDefault', true) ?? $accounts[0] ?? null;
        $this->refundAccountId = $default === null ? '' : (string) $default['id'];
    }

    private function sale(): array
    {
        try {
            return SaleService::getSale($this->saleId);
        } catch (NotFoundError) {
            abort(404);
        }
    }

    private function actor(): Actor
    {
        return (Principal::load((int) auth()->id()) ?? abort(403))->actor();
    }

    public function void(): void
    {
        $this->authorize('sales.void');
        if (mb_strlen(trim($this->reason)) < 3) {
            $this->addError('reason', 'Give a reason for voiding this sale.');

            return;
        }
        try {
            SaleService::voidSale($this->saleId, trim($this->reason), $this->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }
        session()->flash('status', 'Sale voided. The original record was kept and a reversing entry was posted.');
        $this->redirect("/sales/{$this->saleId}");
    }

    public function recordReturn(): void
    {
        $this->authorize('sales.create');
        if (! Filters::isValidBusinessDate($this->returnDate)) {
            $this->addError('returnDate', 'Enter a valid date.');

            return;
        }

        $lines = [];
        $index = 0;
        foreach (ReturnsService::getReturnableSaleItems($this->saleId) as $item) {
            $index++;
            $raw = trim((string) ($this->returnQty[$item['id']] ?? ''));
            if ($raw === '' || $raw === '0') {
                continue;
            }
            try {
                $lines[] = ['itemId' => $item['id'], 'qty' => Quantity::parsePositive($raw, "Line {$index} quantity")];
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
        if (trim($this->refundAmount) !== '' && (int) $this->refundAccountId > 0) {
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

        // Taking goods back is till work; handing money back on a past sale is as strong as voiding it (the old app asked only for sales.create).
        if ($refunds !== [] && Gate::denies('sales.void')) {
            $this->addError('refundAmount', 'Handing money back needs the right to void sales. Ask someone who has it, or record the return without a refund.');

            return;
        }

        try {
            // The sale's own rule: a refund dated back would leave today's drawer short with no report showing why.
            SaleDate::assertAllowed($this->returnDate, Clock::today(), SettingsService::get()->books_locked_before, Gate::allows('sales.edit'), 'return');
            ReturnsService::createCustomerReturn($this->saleId, [
                'businessDate' => $this->returnDate,
                'items' => $lines,
                'refunds' => $refunds,
                'clientRef' => "return-{$this->formToken}",
                ...(trim($this->returnReason) !== '' ? ['reason' => trim($this->returnReason)] : []),
            ], $this->actor());
        } catch (DomainError $e) {
            $this->addError('returnForm', $e->userMessage);

            return;
        }
        // A walk-in sale has no customer balance to update.
        session()->flash('status', $this->sale()['customerId'] !== null ? "Return recorded. Stock and the customer's balance were updated." : 'Return recorded. Stock was updated.');
        $this->redirect("/sales/{$this->saleId}");
    }

    public function render(): View
    {
        $sale = $this->sale();
        $isOwner = RoleGuard::actorIsOwner((int) auth()->id());
        $entry = $sale['journalEntryId'] === null ? null : LedgerService::getJournalEntry((int) $sale['journalEntryId']);
        $returnable = $sale['kind'] === 'SALE' && $sale['status'] === 'POSTED' && Gate::allows('sales.create')
            ? ReturnsService::getReturnableSaleItems($this->saleId) : [];

        // The live estimate under the return form: the domain's own line arithmetic.
        $estimate = 0;
        foreach ($returnable as $item) {
            try {
                $milli = Quantity::parse((string) ($this->returnQty[$item['id']] ?? ''));
            } catch (DomainError) {
                continue;
            }
            if ($milli > 0) {
                $estimate = Money::add($estimate, Quantity::extendPrice($item['unitPriceMinor'], $milli));
            }
        }

        return view('livewire.sales.show', [
            'sale' => $sale,
            'entry' => $entry,
            'tendered' => Money::sum(array_column($sale['tenders'], 'amountMinor')),
            'returnable' => $returnable,
            'estimate' => $estimate,
            'accounts' => $returnable === [] ? [] : PaymentAccountService::listPaymentAccounts(),
            // D34: only the owner edits, and only a live sale.
            'canEdit' => $isOwner && $sale['kind'] === 'SALE' && $sale['status'] === 'POSTED' && $sale['voidsSaleId'] === null,
            'history' => $isOwner ? AuditService::editHistory('sale', $this->saleId) : [],
        ]);
    }
}
