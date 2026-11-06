<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Services\Reporting\FinancialService;
use App\Support\Clock;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The old (app)/reports/page.tsx: this month's headline figures and every report. */
#[Title('Reports')]
final class Index extends Component
{
    public const REPORTS = [
        ['group' => 'For your accountant', 'items' => [
            ['href' => '/reports/year-end', 'title' => 'Year-end pack', 'description' => 'A full set of statements for one financial year, with last year alongside. Print it or send the file.'],
        ]],
        ['group' => 'Money', 'items' => [
            ['href' => '/reports/profit-and-loss', 'title' => 'Profit & Loss', 'description' => 'What you earned, what it cost, and what is left.'],
            ['href' => '/reports/balance-sheet', 'title' => 'Balance sheet', 'description' => 'What the business owns, owes, and is worth.'],
            ['href' => '/reports/cash-flow', 'title' => 'Cash flow', 'description' => 'Where money came in and where it went out.'],
        ]],
        ['group' => 'Trading', 'items' => [
            ['href' => '/reports/sales', 'title' => 'Sales report', 'description' => 'By day, stock item, stock group, customer and payment method.'],
            ['href' => '/reports/purchases', 'title' => 'Purchase report', 'description' => 'By day, supplier and stock item.'],
            ['href' => '/reports/inventory', 'title' => 'Inventory report', 'description' => 'Stock valuation, movement, low stock and out of stock.'],
            ['href' => '/reports/tax', 'title' => 'Tax return', 'description' => 'What you charged, what you can reclaim, and what you owe.'],
        ]],
        ['group' => 'Money owed', 'items' => [
            ['href' => '/accounting/receivables', 'title' => 'Who owes you', 'description' => 'Customer debts by age.'],
            ['href' => '/accounting/payables', 'title' => 'Who you owe', 'description' => 'Supplier balances by age.'],
        ]],
    ];

    public function render(): View
    {
        $today = Clock::today();

        return view('livewire.reports.index', [
            'groups' => self::REPORTS,
            'pl' => FinancialService::getProfitAndLoss(['from' => substr($today, 0, 7).'-01', 'to' => $today]),
            'sheet' => FinancialService::getBalanceSheet($today),
        ]);
    }
}
