<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Services\Reporting\FinancialService;
use App\Services\SettingsService;
use App\Support\Filters\ListFilters;
use Illuminate\View\View;
use Livewire\Attributes\Title;

/** The old (app)/reports/profit-and-loss/page.tsx. */
#[Title('Profit & Loss')]
final class ProfitAndLoss extends ReportPage
{
    public function render(): View
    {
        $list = ListFilters::parseReportPeriod($this->params);

        return view('livewire.reports.profit-and-loss', [
            'list' => $list,
            'pl' => FinancialService::getProfitAndLoss($list['range']),
            'businessName' => SettingsService::get()->business_name ?? 'Shop',
        ]);
    }
}
