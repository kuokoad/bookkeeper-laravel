<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Services\PaymentAccountService;
use App\Services\Reporting\FinancialService;
use App\Support\Filters\ListFilters;
use Illuminate\View\View;
use Livewire\Attributes\Title;

/** The old (app)/reports/cash-flow/page.tsx. */
#[Title('Cash flow')]
final class CashFlow extends ReportPage
{
    public function render(): View
    {
        $list = ListFilters::parseCashFlowFilters($this->params);
        // The opening balance is every entry dated before the window, not today's balance worked backwards.
        $flow = FinancialService::getCashFlow($list['range'], $list['accountId']);

        return view('livewire.reports.cash-flow', [
            'list' => $list,
            'flow' => $flow,
            'accounts' => Report::options(PaymentAccountService::listPaymentAccountOptions(true)),
            'moneyIn' => array_filter($flow['lines'], fn (array $line): bool => $line['inMinor'] > 0),
            'moneyOut' => array_filter($flow['lines'], fn (array $line): bool => $line['outMinor'] > 0),
        ]);
    }
}
