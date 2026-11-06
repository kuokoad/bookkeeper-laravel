<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Services\Reporting\LedgerService;
use App\Support\Filters\ListFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Who owes you / who you owe: the old (app)/accounting/receivables and
 * payables pages, which differ only in their side. The ageing is one row per
 * party, narrowed after the service built its buckets (as the old page did),
 * and the ledger check runs on the WHOLE report before any narrowing.
 */
abstract class AgeingPage extends Component
{
    /** @return array<string, mixed> */
    abstract protected function side(): array;

    public array $params = [];

    public function mount(Request $request): void
    {
        $this->params = $request->query();
    }

    public function render(): View
    {
        $side = $this->side();
        $list = ListFilters::parseAgeingFilters($this->params, $side['key']);

        $all = $side['ageing']($list['asAt']);
        $rows = LedgerService::narrowAgeing($all, $list['partyId'], $list['status']);
        $ageingTotal = LedgerService::getAgeingTotals($all)['total'];
        $subledgerTotal = $side['subledger']();
        $controlTotal = $side['control']();

        return view('livewire.accounting.ageing', [
            'side' => $side,
            'rows' => $rows,
            'totals' => LedgerService::getAgeingTotals($rows),
            'ageingTotal' => $ageingTotal,
            'subledgerTotal' => $subledgerTotal,
            'controlTotal' => $controlTotal,
            // Only meaningful when looking at today: an "as at" in the past legitimately differs from the live control account.
            'agrees' => ! $list['isToday'] || ($ageingTotal === $subledgerTotal && $subledgerTotal === $controlTotal),
            'filtered' => $list['filtered'],
            'asAt' => $list['asAt'],
            'partyId' => $list['partyId'],
            'status' => $list['status'],
            'parties' => $side['parties'](),
            'list' => $list,
        ]);
    }
}
