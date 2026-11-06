<?php

declare(strict_types=1);

namespace App\Livewire\Quotations;

use App\Services\CustomerService;
use App\Services\QuotationService;
use App\Services\SettingsService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The quotations list: the old (app)/quotations/page.tsx. Reads only. */
#[Title('Quotations')]
final class Index extends Component
{
    public array $params = [];

    public function mount(Request $request): void
    {
        $this->params = $request->query();
    }

    public function render(): View
    {
        $today = Clock::today();
        $list = ListFilters::parseQuotationFilters($this->params, $today);
        $total = QuotationService::countQuotations($list['filters'], $today);
        $list['page'] = Filters::clampPage($list['page'], $total, $list['pageSize']);
        $rows = QuotationService::listQuotations([...$list['filters'], 'limit' => $list['pageSize'], 'offset' => ($list['page'] - 1) * $list['pageSize']], $today);

        return view('livewire.quotations.index', [
            'list' => $list,
            'rows' => array_map(fn (array $q): array => [...$q, 'expired' => QuotationService::isExpired($q, $today)], $rows),
            'total' => $total,
            'customers' => CustomerService::listCustomerOptions(),
            'currency' => SettingsService::get()->currency_code ?? 'GHS',
        ]);
    }
}
