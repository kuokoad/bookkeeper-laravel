<?php

declare(strict_types=1);

namespace App\Livewire\Quotations;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\Money;
use App\Services\Actor;
use App\Services\PaymentAccountService;
use App\Services\QuotationService;
use App\Services\SettingsService;
use App\Support\Clock;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One quote: the old (app)/quotations/[id]/page.tsx and convert-panel.tsx.
 * Converting is the only thing here that touches the books, and it is an
 * ordinary sale at the quoted prices, always on the server's day.
 */
#[Title('Quote')]
final class Show extends Component
{
    #[Locked]
    public int $quotationId;

    public string $accountId = '';

    public string $paying = '';

    public bool $createCustomer = true;

    public string $termsDays = '';

    public string $overrideReason = '';

    public string $reason = '';

    public function mount(int $id): void
    {
        $this->quotationId = $id;
        $quote = $this->quote();
        $accounts = PaymentAccountService::listPaymentAccounts();
        $this->accountId = (string) ($accounts[0]['id'] ?? '');
        $this->paying = Money::toInputString((int) $quote['totalMinor']);
        $this->termsDays = (string) (SettingsService::get()->default_terms_days ?? 30);
    }

    private function quote(): array
    {
        try {
            return QuotationService::getQuotation($this->quotationId);
        } catch (NotFoundError) {
            abort(404);
        }
    }

    private function actor(): Actor
    {
        return (Principal::load((int) auth()->id()) ?? abort(403))->actor();
    }

    public function convert(): void
    {
        $this->authorize('quotations.create');
        $terms = trim($this->termsDays);
        if ($terms !== '' && (preg_match('/^\d+$/', $terms) !== 1 || (int) $terms > 365)) {
            $this->addError('termsDays', 'Enter between 0 and 365 days.');

            return;
        }
        $this->validate(['overrideReason' => ['max:300']]);

        try {
            $tenders = [];
            if (trim($this->paying) !== '' && Money::parse($this->paying) !== 0) {
                $amount = Money::parse($this->paying);
                if ($amount < 0) {
                    throw new ValidationError('A payment cannot be negative.');
                }
                $tenders[] = ['paymentAccountId' => (int) $this->accountId, 'amount' => $amount];
            }
            // "Add to your customers" and its terms are on screen only for a part-paid quote with nobody on the books.
            $quote = $this->quote();
            $asks = $quote['customerId'] === null && Money::sum(array_column($tenders, 'amount')) < (int) $quote['totalMinor'];
            $converted = QuotationService::convertQuotation($this->quotationId, [
                // Always the server's day: a date from the form would be a way round the till's backdating right.
                'businessDate' => Clock::today(),
                'customerId' => null,
                'createCustomer' => $asks && $this->createCustomer,
                'tenders' => $tenders,
                ...($asks && $terms !== '' ? ['termsDays' => (int) $terms] : []),
                ...(trim($this->overrideReason) !== '' ? ['overrideReason' => trim($this->overrideReason)] : []),
            ], $this->actor());
        } catch (DomainError $e) {
            $this->addError('convert', $e->userMessage);

            return;
        }
        $this->redirect("/sales/{$converted['saleId']}");
    }

    public function cancel(): void
    {
        $this->authorize('quotations.void');
        if (trim($this->reason) === '') {
            $this->addError('reason', 'Say why the quote is being cancelled.');

            return;
        }
        try {
            QuotationService::cancelQuotation($this->quotationId, trim($this->reason), $this->actor());
        } catch (DomainError $e) {
            $this->addError('cancel', $e->userMessage);

            return;
        }
        session()->flash('status', 'Quote cancelled. It stays on file and can still be printed.');
        $this->redirect("/quotations/{$this->quotationId}");
    }

    public function render(): View
    {
        $quote = $this->quote();
        $expired = QuotationService::isExpired($quote, Clock::today());
        $paying = null;
        try {
            $paying = trim($this->paying) === '' ? 0 : Money::parse($this->paying);
        } catch (DomainError) {
        }

        return view('livewire.quotations.show', [
            'quote' => $quote,
            'expired' => $expired,
            'currency' => SettingsService::get()->currency_code ?? 'GHS',
            'accounts' => PaymentAccountService::listPaymentAccounts(),
            'owing' => $paying === null ? 0 : Money::atLeastZero(Money::subtract((int) $quote['totalMinor'], $paying)),
            'statusLabel' => match (true) {
                $quote['status'] === 'CONVERTED' => 'Became a sale',
                $quote['status'] === 'CANCELLED' => 'Cancelled',
                $expired => 'Open, expired',
                default => 'Open',
            },
        ]);
    }
}
