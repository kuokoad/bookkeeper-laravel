<?php

declare(strict_types=1);

namespace App\Livewire\Accounts;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Services\PaymentAccountService;
use App\Services\SettingsService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One account's statement: the old (app)/accounts/[id]/page.tsx. Opening,
 * in, out and closing are the service's (read from the ledger before the
 * window); the page only shows them.
 */
#[Title('Account')]
final class Show extends Component
{
    public const SOURCE_LABELS = [
        'SALE' => 'Sale',
        'SALE_RETURN' => 'Customer return',
        'PURCHASE' => 'Purchase',
        'PURCHASE_RETURN' => 'Return to supplier',
        'CUSTOMER_PAYMENT' => 'Customer payment',
        'SUPPLIER_PAYMENT' => 'Paid supplier',
        'EXPENSE' => 'Expense',
        'INCOME' => 'Other income',
        'STOCK_ADJUSTMENT' => 'Stock adjustment',
        'RECONCILIATION' => 'Reconciliation',
        'OPENING_BALANCE' => 'Opening balance',
        'CAPITAL' => 'Owner capital',
        'DRAWINGS' => 'Owner drawings',
        'TRANSFER' => 'Transfer',
        'REVERSAL' => 'Reversal',
        'YEAR_END_CLOSE' => 'Year-end close',
    ];

    private const SLUGS = ['cash' => 1, 'momo' => 2, 'bank' => 3];

    public int $accountId;

    public array $params = [];

    public string $name = '';

    public string $provider = '';

    public function mount(Request $request, string $id): void
    {
        $this->accountId = self::SLUGS[$id] ?? (preg_match('/^[1-9]\d{0,17}$/', $id) === 1 ? (int) $id : abort(404));
        $this->params = $request->query();
        $account = $this->account();
        $this->name = $account['name'];
        $this->provider = $account['provider'] ?? '';
    }

    private function account(): array
    {
        try {
            return PaymentAccountService::getPaymentAccount($this->accountId);
        } catch (NotFoundError) {
            abort(404);
        }
    }

    public function save(): void
    {
        $this->authorize('accounts.edit');
        $this->resetErrorBag();
        $name = trim($this->name);
        $errors = array_filter([
            'name' => $name === '' ? 'Enter a name.' : (mb_strlen($name) > 80 ? 'Name must be 80 characters or fewer.' : null),
            'provider' => mb_strlen(trim($this->provider)) > 60 ? 'Provider must be 60 characters or fewer.' : null,
        ]);
        foreach ($errors as $field => $message) {
            $this->addError($field, $message);
        }
        if ($errors !== []) {
            return;
        }
        // Kind, number and default ride along unchanged, as the old form's hidden fields did.
        $account = $this->account();
        try {
            PaymentAccountService::updatePaymentAccount($this->accountId, [
                'name' => $name,
                'kind' => $account['kind'],
                'provider' => trim($this->provider) === '' ? null : trim($this->provider),
                'accountNumber' => $account['accountNumber'],
                'isDefault' => $account['isDefault'],
            ], (Principal::load((int) auth()->id()) ?? abort(403))->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }
        $this->redirect(Index::href($this->accountId).'?updated=1');
    }

    /** Where clicking a movement should take you. */
    public static function sourceHref(string $sourceType, ?int $sourceId): ?string
    {
        if ($sourceId === null) {
            return null;
        }

        return match ($sourceType) {
            'SALE', 'SALE_RETURN' => "/sales/{$sourceId}",
            'PURCHASE', 'PURCHASE_RETURN' => "/purchases/{$sourceId}",
            'STOCK_ADJUSTMENT' => "/inventory/adjustments/{$sourceId}",
            'TRANSFER' => '/accounts/transfers',
            default => null,
        };
    }

    public function render(): View
    {
        $account = $this->account();
        $today = Clock::today();
        $list = ListFilters::parseAccountFilters($this->params, $today);
        $filters = $list['filters'];
        $total = PaymentAccountService::countAccountMovements($this->accountId, $filters);
        $list['page'] = Filters::clampPage($list['page'], $total, $list['pageSize']);
        $isFiltered = $list['preset'] !== 'month' || array_intersect_key($filters, array_flip(['search', 'sourceType', 'flow', 'minAmount', 'maxAmount'])) !== [];

        return view('livewire.accounts.show', [
            'account' => $account,
            'currency' => SettingsService::get()->currency_code ?? 'GHS',
            'list' => $list,
            'total' => $total,
            'statement' => PaymentAccountService::getAccountStatement($this->accountId, [...$filters, 'limit' => $list['pageSize'], 'offset' => ($list['page'] - 1) * $list['pageSize']]),
            'sourceTypes' => PaymentAccountService::listAccountSourceTypes($this->accountId),
            'narrowed' => ListFilters::accountFiltersHideMovements($filters),
            'isFiltered' => $isFiltered,
            'canEdit' => Gate::allows('accounts.edit'),
            'updated' => ($this->params['updated'] ?? null) === '1',
        ]);
    }
}
