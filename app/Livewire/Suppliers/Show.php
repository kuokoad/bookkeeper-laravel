<?php

declare(strict_types=1);

namespace App\Livewire\Suppliers;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Money;
use App\Services\PaymentAccountService;
use App\Services\PurchaseService;
use App\Services\SettingsService;
use App\Services\SupplierPaymentService;
use App\Services\SupplierService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The old suppliers/[id]/page.tsx: what you owe one supplier, their
 * purchases and payments, paying them (oldest delivery first) and voiding a
 * payment in its row.
 */
#[Title('Supplier')]
final class Show extends Component
{
    #[Locked]
    public int $supplierId;

    public string $businessDate = '';

    public string $paymentAccountId = '';

    public string $amount = '';

    public string $reference = '';

    /** @var array<int|string, string> payment id => typed reason */
    public array $voidReasons = [];

    public function mount(string $id): void
    {
        try {
            SupplierService::getSupplier((int) $id);
        } catch (NotFoundError) {
            abort(404);
        }
        $this->supplierId = (int) $id;
        $this->businessDate = Clock::today();
        $accounts = PaymentAccountService::listPaymentAccounts();
        $default = collect($accounts)->firstWhere('isDefault', true) ?? ($accounts[0] ?? null);
        $this->paymentAccountId = $default === null ? '' : (string) $default['id'];
    }

    public function pay(): void
    {
        $this->authorize('suppliers.create');
        $this->resetErrorBag();
        if (! Filters::isValidBusinessDate($this->businessDate)) {
            $this->addError('businessDate', 'Enter a valid date.');
        }
        if (trim($this->amount) === '') {
            $this->addError('amount', 'Enter an amount.');
        }
        if (mb_strlen(trim($this->reference)) > 80) {
            $this->addError('reference', 'The reference must not be greater than 80 characters.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        try {
            $amount = Money::parse($this->amount);
        } catch (DomainError $e) {
            $this->addError('amount', $e->userMessage);

            return;
        }

        try {
            SupplierPaymentService::recordSupplierPayment([
                'supplierId' => $this->supplierId,
                'businessDate' => $this->businessDate,
                'paymentAccountId' => (int) $this->paymentAccountId,
                'amount' => $amount,
                'reference' => trim($this->reference) === '' ? null : trim($this->reference),
            ], Principal::load((int) auth()->id())->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }

        session()->flash('status', 'Payment recorded. What you owe and your account balance were both updated.');
        $this->redirect("/suppliers/{$this->supplierId}");
    }

    public function voidPayment(int $paymentId): void
    {
        $this->authorize('suppliers.void');
        $this->resetErrorBag();
        $reason = trim((string) ($this->voidReasons[$paymentId] ?? ''));
        if (mb_strlen($reason) < 3) {
            $this->addError("void.{$paymentId}", 'Give a reason for voiding this payment.');

            return;
        }
        // Only this supplier's payments are offered; anything else is refused, not voided.
        $ours = SupplierPaymentService::paymentBelongsToSupplier($paymentId, $this->supplierId);
        if (! $ours) {
            $this->addError("void.{$paymentId}", 'That payment could not be found.');

            return;
        }
        try {
            SupplierPaymentService::voidSupplierPayment($paymentId, $reason, Principal::load((int) auth()->id())->actor());
        } catch (DomainError $e) {
            $this->addError("void.{$paymentId}", $e->userMessage);

            return;
        }

        session()->flash('status', 'Payment voided. The debt has been restored.');
        $this->redirect("/suppliers/{$this->supplierId}");
    }

    public function render(): View
    {
        $supplier = SupplierService::getSupplier($this->supplierId);
        $purchases = PurchaseService::listPurchases(['supplierId' => $this->supplierId, 'limit' => 100]);
        $payments = SupplierPaymentService::listSupplierPayments($this->supplierId, 100);

        return view('livewire.suppliers.show', [
            'supplier' => $supplier,
            'currency' => SettingsService::get()->currency_code ?? 'GHS',
            'purchases' => $purchases,
            'payments' => $payments,
            ...SupplierService::summariseActivity($purchases, $payments),
            'accounts' => PaymentAccountService::listPaymentAccounts(),
        ])->title($supplier['name']);
    }
}
