<?php

declare(strict_types=1);

namespace App\Livewire\Cashbook;

use App\Services\CashbookService;
use App\Services\PaymentAccountService;
use Livewire\Attributes\Title;

/** The old (app)/expenses/page.tsx. */
#[Title('Expenses')]
final class Expenses extends CashbookPage
{
    protected function side(): array
    {
        return [
            'module' => 'expenses',
            'path' => '/expenses',
            'csv' => 'expenses',
            'categoryKind' => 'EXPENSE',
            'title' => 'Expenses',
            'description' => 'Money spent running the shop — rent, power, transport, wages.',
            'created' => 'Expense recorded. The money came out of the account you chose.',
            'voided' => 'Expense voided. The original record was kept and a reversing entry posted.',
            'countLabel' => 'Expenses',
            'totalLabel' => 'Total spent',
            'averageLabel' => 'Average expense',
            'icon' => 'expenses',
            'todayLabel' => "Today's expenses",
            'accountLabel' => 'Paid from',
            'emptyFiltered' => 'No expenses match these filters',
            'emptyTitle' => 'Nothing recorded yet',
            'emptyDescription' => 'Record what you spend so your profit figure is real and your cash balance can be trusted.',
            // Recorded on their own page, several at a time (NewExpenses).
            'recordPage' => '/expenses/new',
            'newCategoryLabel' => 'New expense category',
            'newCategoryPlaceholder' => 'e.g. Security guard',
            'noRight' => 'You do not have permission to record expenses.',
            'noun' => 'expense',
            'nounPlural' => null,
            'staffFilter' => true,
            'otherIncomeLink' => true,
            'list' => CashbookService::listExpenses(...),
            'count' => CashbookService::countExpenses(...),
            'summary' => CashbookService::getFilteredExpensesSummary(...),
            'byCategory' => CashbookService::getFilteredExpensesByCategory(...),
            'categories' => PaymentAccountService::listExpenseCategories(...),
            'void' => CashbookService::voidExpense(...),
            'edit' => CashbookService::editExpense(...),
            'table' => 'expenses',
            'entity' => 'expense',
            'edited' => 'Expense edited. Its old entry was reversed and the corrected one posted under the same number.',
        ];
    }
}
