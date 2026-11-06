<?php

declare(strict_types=1);

namespace App\Livewire\Accounts;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Services\SettingsService;
use App\Services\TransferService;
use App\Support\Filters\Filters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The old (app)/accounts/transfers/page.tsx: every transfer, voidable in its row. */
#[Title('Transfers')]
final class Transfers extends Component
{
    private const PAGE_SIZE = 50;

    public array $params = [];

    /** @var array<int|string, string> */
    public array $reasons = [];

    public function mount(Request $request): void
    {
        $this->params = $request->query();
    }

    /** @return array{page: int, pageSize: int, carried: array<string, string>} */
    private function paging(int $total): array
    {
        $page = Filters::clampPage(Filters::parsePage(is_string($this->params['page'] ?? null) ? $this->params['page'] : null, self::PAGE_SIZE)['page'], $total, self::PAGE_SIZE);

        return ['page' => $page, 'pageSize' => self::PAGE_SIZE, 'carried' => $page > 1 ? ['page' => (string) $page] : []];
    }

    public function void(int $id): void
    {
        $this->authorize('accounts.void');
        $this->resetErrorBag();
        $reason = trim($this->reasons[$id] ?? '');
        if (mb_strlen($reason) < 3) {
            $this->addError("reason.{$id}", 'Give a reason for voiding this.');

            return;
        }
        try {
            TransferService::voidTransfer($id, $reason, (Principal::load((int) auth()->id()) ?? abort(403))->actor());
        } catch (DomainError $e) {
            $this->addError("void.{$id}", $e->userMessage);

            return;
        }
        $this->redirect('/accounts/transfers?'.http_build_query([...$this->paging(TransferService::countTransfers())['carried'], 'voided' => '1']));
    }

    public function render(): View
    {
        $total = TransferService::countTransfers();
        $list = $this->paging($total);

        return view('livewire.accounts.transfers', [
            'currency' => SettingsService::get()->currency_code ?? 'GHS',
            'list' => $list,
            'total' => $total,
            'rows' => TransferService::listTransfers(['limit' => self::PAGE_SIZE, 'offset' => ($list['page'] - 1) * self::PAGE_SIZE]),
            'canVoid' => Gate::allows('accounts.void'),
            'voided' => ($this->params['voided'] ?? null) === '1',
        ]);
    }
}
