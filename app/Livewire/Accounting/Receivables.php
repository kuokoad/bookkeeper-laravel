<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\CustomerService;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\LedgerService;
use Livewire\Attributes\Title;

/** The old (app)/accounting/receivables/page.tsx. */
#[Title('Who owes you')]
final class Receivables extends AgeingPage
{
    protected function side(): array
    {
        return [
            'key' => 'customer',
            'report' => 'receivables',
            'title' => 'Who owes you',
            'description' => 'Customer debts, grouped by how long they have been outstanding.',
            'balancesWord' => 'customer',
            'controlName' => 'Accounts Receivable',
            'totalIcon' => 'owed',
            'totalLabel' => 'Total owed to you',
            'countIcon' => 'customers',
            'countLabel' => 'Customers owing',
            'overdueHint' => 'Worth chasing',
            'partyLabel' => 'Customer',
            'allLabel' => 'All customers',
            'hrefBase' => '/customers',
            'dateHeading' => 'Oldest due',
            'emptyTitle' => 'Nobody owes you anything',
            'emptyDescription' => 'Credit sales that have not been fully paid will appear here, grouped by age.',
            'footnote' => 'Age is measured from the day each invoice fell due — the sale date plus the terms it was given — so a sale on 30-day terms is “Not due” for its first month rather than a month overdue. A sale that carried no terms ages from the day it was made. These figures come from the same records as each customer’s profile, so the two always agree.',
            'ageing' => LedgerService::getReceivablesAgeing(...),
            'subledger' => CustomerService::getTotalReceivables(...),
            'control' => fn (): int => BalancesService::getAccountBalanceByCode(ChartOfAccounts::ACCOUNTS_RECEIVABLE),
            'parties' => fn (): array => CustomerService::listCustomers(['balanceState' => 'owing', 'limit' => 500]),
        ];
    }
}
