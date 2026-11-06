<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Domain\Errors\NotFoundError;
use App\Services\Reporting\LedgerService;
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
            $ledger = LedgerService::getGeneralLedger($this->accountId, $list['filters']);
        } catch (NotFoundError) {
            abort(404);
        }

        return view('livewire.accounting.ledger', [
            ...$ledger,
            'from' => $list['from'],
            'toShown' => $list['toShown'],
            'list' => $list,
        ]);
    }
}
