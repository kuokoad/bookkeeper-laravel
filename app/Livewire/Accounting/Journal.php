<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Services\Reporting\LedgerService;
use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The old (app)/accounting/journal/page.tsx: entries in a period, newest
 * first, 100 to a page. From/to/source, parsed by ListFilters::parseJournalFilters.
 */
#[Title('Journal')]
final class Journal extends Component
{
    public const SOURCE_LABELS = [
        'SALE' => 'Sale', 'SALE_RETURN' => 'Customer return', 'PURCHASE' => 'Purchase', 'PURCHASE_RETURN' => 'Supplier return',
        'CUSTOMER_PAYMENT' => 'Customer payment', 'SUPPLIER_PAYMENT' => 'Supplier payment', 'EXPENSE' => 'Expense', 'INCOME' => 'Other income',
        'STOCK_ADJUSTMENT' => 'Stock adjustment', 'RECONCILIATION' => 'Reconciliation', 'OPENING_BALANCE' => 'Opening balance',
        'CAPITAL' => 'Owner capital', 'DRAWINGS' => 'Owner drawings', 'TRANSFER' => 'Transfer', 'REVERSAL' => 'Reversal',
    ];

    public const KINDS = [
        'SALE' => 'Sales', 'PURCHASE' => 'Purchases', 'EXPENSE' => 'Expenses', 'INCOME' => 'Other income',
        'CUSTOMER_PAYMENT' => 'Customer payments', 'SUPPLIER_PAYMENT' => 'Supplier payments',
        'STOCK_ADJUSTMENT' => 'Stock adjustments', 'TRANSFER' => 'Transfers',
    ];

    public array $params = [];

    public function mount(Request $request): void
    {
        $this->params = $request->query();
    }

    public function render(): View
    {
        $list = ListFilters::parseJournalFilters($this->params);
        // A page at a time, with its count: a busy month runs past what one screen should hold.
        $total = LedgerService::countJournalEntries($list['filters']);
        $list['page'] = Filters::clampPage($list['page'], $total, $list['pageSize']);
        $entries = LedgerService::listJournalEntries([...$list['filters'], 'limit' => $list['pageSize'], 'offset' => ($list['page'] - 1) * $list['pageSize']]);

        return view('livewire.accounting.journal', [
            'entries' => $entries,
            'unbalanced' => array_values(array_filter($entries, fn (array $e): bool => ! $e['balanced'])),
            'from' => $list['from'],
            'to' => $list['to'],
            'source' => $list['source'],
            'list' => $list,
            'total' => $total,
        ]);
    }
}
