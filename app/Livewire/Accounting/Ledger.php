<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Domain\Errors\NotFoundError;
use App\Services\Reporting\LedgerService;
use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The old (app)/accounting/ledger/[id]/page.tsx: one account's movements with a running balance. */
#[Title('Account ledger')]
final class Ledger extends Component
{
    #[Locked]
    public int $accountId;

    public array $params = [];

    public function mount(Request $request, string $id): void
    {
        $value = filter_var($id, FILTER_VALIDATE_INT);
        abort_if($value === false || $value <= 0, 404);
        $this->accountId = $value;
        $this->params = $request->query();
    }

    public function render(): View
    {
        $list = ListFilters::parseLedgerFilters($this->params);
        try {
            $ledger = LedgerService::getGeneralLedger($this->accountId, [...$list['filters'], 'limit' => $list['pageSize'], 'offset' => ($list['page'] - 1) * $list['pageSize']]);
        } catch (NotFoundError) {
            abort(404);
        }
        // Past the last page: show the last page, which the totals above it already describe.
        $page = Filters::clampPage($list['page'], $ledger['total'], $list['pageSize']);
        if ($page !== $list['page']) {
            $list['page'] = $page;
            $ledger = LedgerService::getGeneralLedger($this->accountId, [...$list['filters'], 'limit' => $list['pageSize'], 'offset' => ($page - 1) * $list['pageSize']]);
        }

        return view('livewire.accounting.ledger', [
            ...$ledger,
            'from' => $list['from'],
            'toShown' => $list['toShown'],
            'list' => $list,
        ]);
    }
}
