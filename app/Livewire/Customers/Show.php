<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Money;
use App\Domain\Sales\SaleDate;
use App\Services\Actor;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\PaymentAccountService;
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
 * One customer: the old (app)/customers/[id]/page.tsx, with the receive
 * payment form and voiding a payment from its row. Payments go through
 * CustomerPaymentService, applied to the oldest unpaid sale first.
 */
#[Title('Customer')]
final class Show extends Component
{
    #[Locked]
    public int $customerId;

    public string $businessDate = '';

    public string $paymentAccountId = '';

    public string $amount = '';

    public string $reference = '';

    /** @var array<int|string, string> reason typed against each payment row */
    public array $voidReasons = [];

    public function mount(int $id): void
    {
        $this->customerId = $id;
        $this->customer();
        $this->businessDate = Clock::today();
        $accounts = PaymentAccountService::listPaymentAccounts();
        $default = collect($accounts)->firstWhere('isDefault', true) ?? $accounts[0] ?? null;
        $this->paymentAccountId = $default === null ? '' : (string) $default['id'];
    }

    private function customer(): array
    {
        try {
            return CustomerService::getCustomer($this->customerId);
        } catch (NotFoundError) {
            abort(404);
        }
    }

    private function actor(): Actor
    {
        return (Principal::load((int) auth()->id()) ?? abort(403))->actor();
    }

    /** "All": the whole balance into the amount box. */
    public function payAll(): void
    {
        $this->amount = Money::toInputString($this->customer()['balance']);
    }

    public function receivePayment(): void
    {
        $this->authorize('customers.create');
        if (! Filters::isValidBusinessDate($this->businessDate)) {
            $this->addError('businessDate', 'Enter a valid date.');

            return;
        }
        if (trim($this->amount) === '') {
            $this->addError('amount', 'Enter an amount.');

            return;
        }
        try {
            $amount = Money::parse($this->amount);
        } catch (DomainError $e) {
            $this->addError('amount', $e->userMessage);

            return;
        }
        $this->validate(['reference' => ['max:80']]);

        try {
            SaleDate::assertAllowed($this->businessDate, Clock::today(), SettingsService::get()->books_locked_before, Gate::allows('customers.edit'), 'payment');
            CustomerPaymentService::recordCustomerPayment([
                'customerId' => $this->customerId,
                'businessDate' => $this->businessDate,
                'paymentAccountId' => (int) $this->paymentAccountId,
                'amount' => $amount,
                ...(trim($this->reference) !== '' ? ['reference' => trim($this->reference)] : []),
            ], $this->actor());
        } catch (DomainError $e) {
            $this->addError('payment', $e->userMessage);

            return;
        }
        session()->flash('status', 'Payment recorded. Their balance and your cash account were both updated.');
        $this->redirect("/customers/{$this->customerId}");
    }

    public function voidPayment(int $paymentId): void
    {
        $this->authorize('customers.void');
        $reason = trim((string) ($this->voidReasons[$paymentId] ?? ''));
        if (mb_strlen($reason) < 3) {
            $this->addError("voidReasons.{$paymentId}", 'Give a reason for voiding this payment.');

            return;
        }
        // Only this customer's own payments may be voided from this page.
        $mine = CustomerPaymentService::paymentBelongsToCustomer($paymentId, $this->customerId);
        if (! $mine) {
            abort(404);
        }
        try {
            CustomerPaymentService::voidCustomerPayment($paymentId, $reason, $this->actor());
        } catch (DomainError $e) {
            $this->addError("voidReasons.{$paymentId}", $e->userMessage);

            return;
        }
        session()->flash('status', 'Payment voided. The debt has been restored.');
        $this->redirect("/customers/{$this->customerId}");
    }

    public function render(): View
    {
        $customer = $this->customer();
        $sales = SaleService::listSales(['customerId' => $this->customerId, 'limit' => 100]);
        $payments = CustomerPaymentService::listCustomerPayments($this->customerId, 100);

        return view('livewire.customers.show', [
            'customer' => $customer,
            'currency' => SettingsService::get()->currency_code ?? 'GHS',
            'sales' => $sales,
            'payments' => $payments,
            'openSales' => CustomerPaymentService::getOpenSales($this->customerId),
            ...CustomerService::getActivityTotals($this->customerId),
            'accounts' => PaymentAccountService::listPaymentAccounts(),
        ]);
    }
}
