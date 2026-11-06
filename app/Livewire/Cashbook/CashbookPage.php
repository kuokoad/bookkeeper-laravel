<?php

declare(strict_types=1);

namespace App\Livewire\Cashbook;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\ValidationError;
use App\Domain\Money;
use App\Services\Actor;
use App\Services\PaymentAccountService;
use App\Services\SettingsService;
use App\Services\UserService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Expenses and other income: the old (app)/expenses and (app)/income pages,
 * which ask for exactly the same things in different words. Each subclass
 * names its side; everything else (list, filters, record, void, add a
 * category) is shared. Figures come from CashbookService as they are.
 */
abstract class CashbookPage extends Component
{
    /** @return array<string, mixed> wording, permission module and service calls for one side */
    abstract protected function side(): array;

    public array $params = [];

    public string $businessDate = '';

    public string $categoryAccountId = '';

    public string $description = '';

    public string $amount = '';

    public string $paymentAccountId = '';

    public string $reference = '';

    public string $note = '';

    public string $newCategory = '';

    /** @var array<int|string, string> the reason typed in each row's void form */
    public array $reasons = [];

    public function mount(Request $request): void
    {
        $this->params = $request->query();
        $this->businessDate = Clock::today();
        $accounts = PaymentAccountService::listPaymentAccounts();
        $default = array_values(array_filter($accounts, fn (array $a): bool => $a['isDefault']))[0] ?? $accounts[0] ?? null;
        $this->paymentAccountId = $default === null ? '' : (string) $default['id'];
    }

    private function actor(): Actor
    {
        return (Principal::load((int) auth()->id()) ?? abort(403))->actor();
    }

    /** Back to the list as it was filtered, with the old success flag. */
    private function back(string $flag): void
    {
        $list = ListFilters::parseCashbookFilters($this->params);
        $this->redirect($this->side()['path'].'?'.http_build_query([...$list['carried'], $flag => '1']));
    }

    public function record(): void
    {
        $side = $this->side();
        $this->authorize("{$side['module']}.create");
        $this->resetErrorBag();

        // The old zod schema's shape checks, with its messages.
        $errors = array_filter([
            'businessDate' => Filters::isValidBusinessDate($this->businessDate) ? null : 'Enter a valid date.',
            'categoryAccountId' => (int) $this->categoryAccountId > 0 ? null : 'Choose a category.',
            'description' => trim($this->description) === '' ? 'Enter a short description.' : (mb_strlen(trim($this->description)) > 200 ? 'Description must be 200 characters or fewer.' : null),
            'amount' => trim($this->amount) === '' ? 'Enter an amount.' : null,
            'paymentAccountId' => (int) $this->paymentAccountId > 0 ? null : 'Choose an account.',
            'reference' => mb_strlen(trim($this->reference)) > 80 ? 'Reference must be 80 characters or fewer.' : null,
            'note' => mb_strlen(trim($this->note)) > 300 ? 'Note must be 300 characters or fewer.' : null,
        ]);
        foreach ($errors as $field => $message) {
            $this->addError($field, $message);
        }
        if ($errors !== []) {
            return;
        }

        try {
            $amount = Money::parse($this->amount);
        } catch (DomainError $e) {
            $this->addError('amount', $e->userMessage);

            return;
        }

        try {
            $side['record']([
                'businessDate' => $this->businessDate,
                'categoryAccountId' => (int) $this->categoryAccountId,
                'description' => trim($this->description),
                'amount' => $amount,
                'paymentAccountId' => (int) $this->paymentAccountId,
                'reference' => trim($this->reference) === '' ? null : trim($this->reference),
                'note' => trim($this->note) === '' ? null : trim($this->note),
            ], $this->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }

        $this->back('created');
    }

    public function void(int $id): void
    {
        $side = $this->side();
        $this->authorize("{$side['module']}.void");
        $this->resetErrorBag();
        $reason = trim($this->reasons[$id] ?? '');
        if (mb_strlen($reason) < 3) {
            $this->addError("reason.{$id}", 'Give a reason for voiding this.');

            return;
        }

        try {
            $side['void']($id, $reason, $this->actor());
        } catch (DomainError $e) {
            $this->addError("void.{$id}", $e->userMessage);

            return;
        }

        $this->back('voided');
    }

    public function addCategory(): void
    {
        $side = $this->side();
        $this->authorize("{$side['module']}.create");
        $this->resetErrorBag();
        $name = trim($this->newCategory);
        if ($name === '') {
            $this->addError('name', 'Enter a category name.');

            return;
        }

        try {
            PaymentAccountService::createCategory($side['categoryKind'], $name, $this->actor());
        } catch (ValidationError $e) {
            $this->addError('name', $e->userMessage);

            return;
        } catch (DomainError $e) {
            $this->addError('category', $e->userMessage);

            return;
        }
        $this->newCategory = '';
        $this->dispatch('category-added');
    }

    public function render(): View
    {
        $side = $this->side();
        $today = Clock::today();
        $list = ListFilters::parseCashbookFilters($this->params, $today);
        $total = $side['count']($list['filters']);
        $list['page'] = Filters::clampPage($list['page'], $total, $list['pageSize']);
        $page = ['limit' => $list['pageSize'], 'offset' => ($list['page'] - 1) * $list['pageSize']];
        $filters = $list['filters'];
        $isFiltered = $list['preset'] !== 'month'
            || array_intersect_key($filters, array_flip(['search', 'categoryAccountId', 'paymentAccountId', 'staffId', 'status', 'minAmount', 'maxAmount'])) !== [];

        return view('livewire.cashbook.index', [
            'side' => $side,
            'list' => $list,
            'today' => $today,
            'currency' => SettingsService::get()->currency_code ?? 'GHS',
            'rows' => $side['list']([...$filters, ...$page]),
            'total' => $total,
            'summary' => $side['summary']($filters),
            'byCategory' => $side['byCategory'] === null ? [] : $side['byCategory']($filters),
            'categories' => $side['categories'](),
            'accounts' => PaymentAccountService::listPaymentAccounts(),
            'staff' => $side['staffFilter'] ? UserService::listUsers() : [],
            'periodHint' => Filters::describeDateRange($list['range'], $list['preset'], $today),
            'isFiltered' => $isFiltered,
            'canCreate' => Gate::allows("{$side['module']}.create"),
            'canVoid' => Gate::allows("{$side['module']}.void"),
            'created' => ($this->params['created'] ?? null) === '1',
            'voided' => ($this->params['voided'] ?? null) === '1',
        ]);
    }
}
