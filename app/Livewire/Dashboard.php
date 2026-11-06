<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Auth\Principal;
use App\Domain\Dashboard as Windows;
use App\Domain\Money;
use App\Enums\PermissionModule as M;
use App\Models\User;
use App\Services\CashbookService;
use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\ReconciliationService;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\FinancialService;
use App\Services\Reporting\LedgerService;
use App\Services\Reporting\MoneyTrend;
use App\Services\Reporting\OperationsService;
use App\Services\SaleService;
use App\Services\SupplierService;
use App\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The dashboard: the old (app)/dashboard/page.tsx. Every figure is a service
 * read; the windows and classifications are App\Domain\Dashboard. Each panel
 * is read only when the person may see it, so a refused area is never
 * queried at all. Reads only: nothing here writes.
 */
#[Title('Dashboard')]
final class Dashboard extends Component
{
    public string $sales = 'month';

    public string $spend = 'month';

    public function mount(Request $request): void
    {
        $this->sales = Windows::dashboardRange(is_string($request->query('sales')) ? $request->query('sales') : null);
        $this->spend = Windows::dashboardRange(is_string($request->query('spend')) ? $request->query('spend') : null);
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();
        $principal = Principal::load((int) $user->id) ?? abort(403);
        $can = fn (M $module, string $action = 'view'): bool => $principal->can($module, $action);

        $shows = [
            'money' => $can(M::Accounts),
            'sales' => $can(M::Sales),
            'spending' => $can(M::Expenses),
            'profit' => $can(M::Reports),
            'owed' => $can(M::Customers),
            'suppliers' => $can(M::Suppliers),
            'stock' => $can(M::Inventory),
            'reconciliation' => $can(M::Reconciliation),
        ];
        $today = Clock::today();
        $salesPeriod = Windows::dashboardPeriod($today, $this->sales);
        $spendPeriod = Windows::dashboardPeriod($today, $this->spend);
        $profitPeriod = ['from' => substr($today, 0, 7).'-01', 'to' => $today];

        $paymentAccounts = $shows['money'] ? BalancesService::getPaymentAccountBalances() : [];
        $booksCheck = $shows['money'] ? LedgerService::checkBooksIntegrity() : null;
        $receivables = $shows['owed'] ? CustomerService::getTotalReceivables() : null;
        $ageing = $shows['owed'] ? LedgerService::getReceivablesAgeing($today) : [];
        $reconciliation = $shows['reconciliation'] ? ReconciliationService::getReconciliationOverview() : [];
        $checks = $booksCheck === null ? [] : Windows::integrityChecks($booksCheck);

        return view('livewire.dashboard', [
            'user' => $user,
            'shows' => $shows,
            'nothingToShow' => ! in_array(true, $shows, true),
            'today' => $today,
            'salesPeriod' => $salesPeriod,
            'spendPeriod' => $spendPeriod,
            'profitPeriod' => $profitPeriod,
            'paymentAccounts' => $paymentAccounts,
            'cashHeld' => Money::sum(array_column($paymentAccounts, 'balance')),
            'booksCheck' => $booksCheck,
            'checks' => $checks,
            'entryCount' => $shows['money'] ? BalancesService::countJournalEntries() : 0,
            'stock' => $shows['stock'] ? CatalogService::getStockSummary() : null,
            'expiry' => $shows['stock'] ? CatalogService::getExpirySummary() : null,
            'rangeSales' => $shows['sales'] ? SaleService::getSalesSummary($salesPeriod['from'], $salesPeriod['to']) : null,
            'todaySales' => $shows['sales'] ? SaleService::getSalesSummary($today, $today) : null,
            'salesByDay' => $shows['sales'] ? OperationsService::getSalesByDay($salesPeriod) : [],
            'topProduct' => $shows['sales'] ? OperationsService::getTopProductByRevenue($salesPeriod) : null,
            'spendTotal' => $shows['spending'] ? CashbookService::getExpensesTotal($spendPeriod['from'], $spendPeriod['to']) : null,
            'expenseDays' => $shows['spending'] ? CashbookService::getExpensesByDay($spendPeriod['from'], $spendPeriod['to']) : [],
            'categories' => $shows['spending'] ? CashbookService::getExpensesByCategory($spendPeriod['from'], $spendPeriod['to']) : [],
            'pl' => $shows['profit'] ? FinancialService::getProfitAndLoss($profitPeriod) : null,
            'receivables' => $receivables,
            'payables' => $shows['suppliers'] ? SupplierService::getTotalPayables() : null,
            'aged' => $receivables === null ? null : Windows::summariseAgeing($ageing, $receivables),
            'reconciliation' => $reconciliation,
            'differences' => Windows::reconciliationIssues($reconciliation),
            'moneyMonths' => $shows['money'] ? MoneyTrend::getMoneyByMonth($today, 6) : [],
            'failedChecks' => array_values(array_filter($checks, fn (array $c): bool => ! $c['passed'])),
            'actions' => array_values(array_filter([
                ['label' => 'New Sale', 'icon' => 'add', 'href' => '/sales/new', 'allowed' => $can(M::Sales, 'create')],
                ['label' => 'Record Expense', 'icon' => 'receipt', 'href' => '/expenses#record-expense', 'allowed' => $can(M::Expenses, 'create')],
                ['label' => 'New Purchase', 'icon' => 'shoppingBag', 'href' => '/purchases/new', 'allowed' => $can(M::Purchases, 'create')],
                ['label' => 'Receive Payment', 'icon' => 'payment', 'href' => '/customers?owing=1', 'allowed' => $can(M::Customers, 'create')],
            ], fn (array $a): bool => $a['allowed'])),
        ]);
    }

    /** The old lib/account-routes accountHref: the three seeded accounts by name. */
    public static function accountHref(int $id): string
    {
        return '/accounts/'.([1 => 'cash', 2 => 'momo', 3 => 'bank'][$id] ?? $id);
    }
}
