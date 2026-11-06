<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\LedgerService;
use App\Services\SupplierService;
use Livewire\Attributes\Title;

/** The old (app)/accounting/payables/page.tsx. */
#[Title('Who you owe')]
final class Payables extends AgeingPage
{
    protected function side(): array
    {
        return [
            'key' => 'supplier',
            'report' => 'payables',
            'title' => 'Who you owe',
            'description' => 'Supplier balances, grouped by how long they have been outstanding.',
            'balancesWord' => 'supplier',
            'controlName' => 'Accounts Payable',
            'totalIcon' => 'owes',
            'totalLabel' => 'Total you owe',
            'countIcon' => 'suppliers',
            'countLabel' => 'Suppliers owed',
            'overdueHint' => 'Long overdue',
            'partyLabel' => 'Supplier',
            'allLabel' => 'All suppliers',
            'hrefBase' => '/suppliers',
            'dateHeading' => 'Oldest delivery',
            'emptyTitle' => 'You do not owe anyone',
            'emptyDescription' => 'Purchases that have not been fully paid will appear here, grouped by age.',
            'footnote' => 'Age is measured from the date of each delivery. These figures come from the same records as each supplier’s profile, so the two always agree.',
            'ageing' => LedgerService::getPayablesAgeing(...),
            'subledger' => SupplierService::getTotalPayables(...),
            'control' => fn (): int => BalancesService::getAccountBalanceByCode(ChartOfAccounts::ACCOUNTS_PAYABLE),
            'parties' => fn (): array => SupplierService::listSuppliers(['balanceState' => 'owing', 'limit' => 500]),
        ];
    }
}
