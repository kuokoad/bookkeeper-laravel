<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Services\Reporting\LedgerService;
use App\Support\Filters\ListFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The old (app)/accounting/trial-balance/page.tsx: raw debit and credit
 * totals per leaf account with movement, as at a date.
 */
#[Title('Trial balance')]
final class TrialBalance extends Component
{
    public array $params = [];

    public function mount(Request $request): void
    {
        $this->params = $request->query();
    }

    public function render(): View
    {
        $list = ListFilters::parseTrialBalanceFilters($this->params);

        return view('livewire.accounting.trial-balance', [
            ...LedgerService::getGrossTrialBalance($list['filters']),
            'from' => $list['from'],
            'to' => $list['to'],
            'list' => $list,
        ]);
    }
}
