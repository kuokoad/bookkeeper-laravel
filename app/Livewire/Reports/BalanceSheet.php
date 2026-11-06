<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Services\Reporting\FinancialService;
use App\Services\SettingsService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use Illuminate\View\View;
use Livewire\Attributes\Title;

/** The old (app)/reports/balance-sheet/page.tsx: a position as at one day. */
#[Title('Balance sheet')]
final class BalanceSheet extends ReportPage
{
    public function render(): View
    {
        $requested = is_string($this->params['asAt'] ?? null) ? $this->params['asAt'] : null;
        // The old page passed ?asAt through unchecked; junk now falls back to today (filter rule: validate every date).
        $asAt = $requested !== null && Filters::isValidBusinessDate($requested) ? $requested : Clock::today();

        return view('livewire.reports.balance-sheet', [
            'asAt' => $asAt,
            'sheet' => FinancialService::getBalanceSheet($asAt),
            'businessName' => SettingsService::get()->business_name ?? 'Shop',
        ]);
    }
}
