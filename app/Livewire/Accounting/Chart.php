<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Services\Reporting\LedgerService;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The old (app)/accounting/chart/page.tsx. */
#[Title('Chart of accounts')]
final class Chart extends Component
{
    public const TYPE_LABELS = [
        'ASSET' => 'Asset', 'LIABILITY' => 'Liability', 'EQUITY' => 'Equity', 'CONTRA_EQUITY' => 'Equity (contra)',
        'REVENUE' => 'Revenue', 'CONTRA_REVENUE' => 'Revenue (contra)', 'COGS' => 'Cost of sales', 'EXPENSE' => 'Expense',
    ];

    public const TYPE_TONES = [
        'ASSET' => 'success', 'LIABILITY' => 'warning', 'EQUITY' => 'accent', 'CONTRA_EQUITY' => 'accent',
        'REVENUE' => 'success', 'CONTRA_REVENUE' => 'neutral', 'COGS' => 'warning', 'EXPENSE' => 'warning',
    ];

    public function render(): View
    {
        return view('livewire.accounting.chart', ['chart' => LedgerService::getChartOfAccounts()]);
    }
}
