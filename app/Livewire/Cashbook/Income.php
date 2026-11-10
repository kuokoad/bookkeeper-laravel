<?php

declare(strict_types=1);

namespace App\Livewire\Cashbook;

use App\Domain\Errors\DomainError;
use App\Domain\Money;
use App\Services\CashbookService;
use App\Services\PaymentAccountService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use Illuminate\Http\Request;
use Livewire\Attributes\Title;

/** The old (app)/income/page.tsx: the list, and the record form beside it. */
#[Title('Other income')]
final class Income extends CashbookPage
{
    public string $businessDate = '';

    public string $categoryAccountId = '';

    public string $description = '';

    public string $amount = '';

    public string $paymentAccountId = '';

    public string $reference = '';

    public string $note = '';

    public function mount(Request $request): void
    {
        parent::mount($request);
        $this->businessDate = Clock::today();
        $accounts = PaymentAccountService::listPaymentAccounts();
        $default = array_values(array_filter($accounts, fn (array $a): bool => $a['isDefault']))[0] ?? $accounts[0] ?? null;
        $this->paymentAccountId = $default === null ? '' : (string) $default['id'];
    }

    public function record(): void
    {
        $this->authorize('income.create');
        $this->resetErrorBag();

        // The old zod schema's shape checks, with its messages.
        $errors = array_filter([
            'businessDate' => Filters::isValidBusinessDate($this->businessDate) ? null : 'Enter a valid date.',
            'categoryAccountId' => (int) $this->categoryAccountId > 0 ? null : 'Choose a category.',
            'description' => trim($this->description) === '' ? 'Enter a short description.' : (mb_strlen(trim($this->description)) > 200 ? 'Description must be 200 characters or fewer.' : null),
            'amount' => trim($this->amount) === '' ? 'Enter an amount.' : null,
            'paymentAccountId' => (int) $this->paymentAccountId > 0 ? null : 'Choose an account.',
            'reference' => mb_strlen(trim($this->reference)) > 80 ? 'Reference must be 80 characters or fewer.' : null,
            'note' => mb_strlen(trim($this->note)) > 300 ? 'Note must be 300 characters or fewer.' : null,
        ]);
        foreach ($errors as $field => $message) {
            $this->addError($field, $message);
        }
        if ($errors !== []) {
            return;
        }

        try {
            $amount = Money::parse($this->amount);
        } catch (DomainError $e) {
            $this->addError('amount', $e->userMessage);

            return;
        }

        try {
            CashbookService::recordIncome([
                'businessDate' => $this->businessDate,
                'categoryAccountId' => (int) $this->categoryAccountId,
                'description' => trim($this->description),
                'amount' => $amount,
                'paymentAccountId' => (int) $this->paymentAccountId,
                'reference' => trim($this->reference) === '' ? null : trim($this->reference),
                'note' => trim($this->note) === '' ? null : trim($this->note),
            ], $this->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }

        $this->back('created');
    }

    protected function side(): array
    {
        return [
            'module' => 'income',
            'path' => '/income',
            'csv' => 'income',
            'categoryKind' => 'INCOME',
            'title' => 'Other income',
            'description' => 'Money in that is not a product sale — commission, services, anything else.',
            'created' => 'Income recorded. It went into the account you chose, kept separate from sales.',
            'voided' => 'Income voided. The original record was kept and a reversing entry posted.',
            'countLabel' => 'Transactions',
            'totalLabel' => 'Total received',
            'averageLabel' => 'Average',
            'icon' => 'income',
            'todayLabel' => "Today's income",
            'accountLabel' => 'Received into',
            'emptyFiltered' => 'No income matches these filters',
            'emptyTitle' => 'No other income yet',
            'emptyDescription' => 'Use this for money that is not from selling stock, so it does not get mixed into your sales figures.',
            'formHeading' => 'Record income',
            'submit' => 'Record income',
            'descriptionPlaceholder' => 'e.g. Airtime commission',
            'newCategoryLabel' => 'New income category',
            'newCategoryPlaceholder' => 'e.g. Table rental',
            'noRight' => 'You do not have permission to record income.',
            'noun' => 'entry',
            'nounPlural' => 'entries',
            'staffFilter' => false,
            'otherIncomeLink' => false,
            'list' => CashbookService::listIncomes(...),
            'count' => CashbookService::countIncomes(...),
            'summary' => CashbookService::getFilteredIncomesSummary(...),
            'byCategory' => null,
            'categories' => PaymentAccountService::listIncomeCategories(...),
            'void' => CashbookService::voidIncome(...),
            'edit' => CashbookService::editIncome(...),
            'table' => 'incomes',
            'entity' => 'income',
            'edited' => 'Income edited. Its old entry was reversed and the corrected one posted under the same number.',
        ];
    }
}
