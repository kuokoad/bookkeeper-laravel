<?php

declare(strict_types=1);

namespace App\Livewire\Cashbook;

use App\Services\CashbookService;
use App\Services\PaymentAccountService;
use Livewire\Attributes\Title;

/** The old (app)/income/page.tsx. */
#[Title('Other income')]
final class Income extends CashbookPage
{
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
            'record' => CashbookService::recordIncome(...),
            'void' => CashbookService::voidIncome(...),
        ];
    }
}
