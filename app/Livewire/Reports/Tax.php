<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Services\Reporting\TaxReturnService;
use App\Services\TaxService;
use App\Support\Filters\ListFilters;
use Illuminate\View\View;
use Livewire\Attributes\Title;

/**
 * The old (app)/reports/tax/page.tsx: what the shop owes the tax authority,
 * ready to be copied onto a return. Deliberately not laid out as a GRA form.
 */
#[Title('Tax return')]
final class Tax extends ReportPage
{
    public function render(): View
    {
        $list = ListFilters::parseReportPeriod($this->params);
        $tax = TaxReturnService::getTaxReturn($list['range']);

        return view('livewire.reports.tax', [
            'list' => $list,
            'tax' => $tax,
            'paid' => array_column($tax['components'], 'paidOnPurchasesMinor'),
            'totalPaid' => $tax['totalPaidOnPurchases'],
            'netUnsigned' => $tax['netUnsigned'],
            'owed' => $tax['netPayable'] > 0,
            'reclaimable' => $tax['netPayable'] < 0,
            'enabled' => TaxService::profile()['enabled'],
        ]);
    }
}
