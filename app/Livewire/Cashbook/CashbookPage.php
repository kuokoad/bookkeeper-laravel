<?php

declare(strict_types=1);

namespace App\Livewire\Cashbook;

use App\Auth\Principal;
use App\Auth\RoleGuard;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\ValidationError;
use App\Domain\Money;
use App\Services\Actor;
use App\Services\AuditService;
use App\Services\PaymentAccountService;
use App\Services\SettingsService;
use App\Services\UserService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Expenses and other income: the old (app)/expenses and (app)/income pages,
 * which ask for exactly the same things in different words. Each subclass
 * names its side; everything else (list, filters, void, add a category) is
 * shared. Income records inline; expenses on their own page (NewExpenses). Figures come from CashbookService as they are.
 */
abstract class CashbookPage extends Component
{
    /** @return array<string, mixed> wording, permission module and service calls for one side */
    abstract protected function side(): array;

    public array $params = [];

    public string $newCategory = '';

    /** @var array<int|string, string> the reason typed in each row's void form */
    public array $reasons = [];

    /** D34: the row the owner is editing, and its form. */
    #[Locked]
    public ?int $editId = null;

    /** @var array{businessDate?: string, categoryAccountId?: string, description?: string, amount?: string, paymentAccountId?: string, reference?: string, note?: string, reason?: string} */
    public array $edit = [];

    public function startEdit(int $id): void
    {
        abort_unless(RoleGuard::actorIsOwner((int) auth()->id()), 403);
        $this->resetErrorBag();
        $row = DB::table($this->side()['table'])->where('id', $id)->where('status', 'POSTED')->first() ?? abort(404);
        $this->editId = $id;
        $this->edit = [
            'businessDate' => (string) $row->business_date,
            'categoryAccountId' => (string) $row->category_account_id,
            'description' => $row->description,
            'amount' => Money::toInputString((int) $row->amount_minor),
            'paymentAccountId' => (string) $row->payment_account_id,
            'reference' => (string) ($row->reference ?? ''),
            'note' => (string) ($row->note ?? ''),
            'reason' => '',
        ];
    }

    public function cancelEdit(): void
    {
        $this->editId = null;
        $this->edit = [];
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        abort_unless(RoleGuard::actorIsOwner((int) auth()->id()), 403);
        if ($this->editId === null) {
            return;
        }
        $this->resetErrorBag();
        $e = fn (string $key): string => trim((string) ($this->edit[$key] ?? ''));
        // The record form's own checks, with its messages.
        $errors = array_filter([
            'edit.businessDate' => Filters::isValidBusinessDate($e('businessDate')) ? null : 'Enter a valid date.',
            'edit.categoryAccountId' => (int) $e('categoryAccountId') > 0 ? null : 'Choose a category.',
            'edit.description' => $e('description') === '' ? 'Enter a short description.' : (mb_strlen($e('description')) > 200 ? 'Description must be 200 characters or fewer.' : null),
            'edit.amount' => $e('amount') === '' ? 'Enter an amount.' : null,
            'edit.paymentAccountId' => (int) $e('paymentAccountId') > 0 ? null : 'Choose an account.',
            'edit.reference' => mb_strlen($e('reference')) > 80 ? 'Reference must be 80 characters or fewer.' : null,
            'edit.note' => mb_strlen($e('note')) > 300 ? 'Note must be 300 characters or fewer.' : null,
            'edit.reason' => mb_strlen($e('reason')) < 3 ? 'Say why this is being edited.' : null,
        ]);
        foreach ($errors as $field => $message) {
            $this->addError($field, $message);
        }
        if ($errors !== []) {
            return;
        }

        try {
            $this->side()['edit']($this->editId, [
                'businessDate' => $e('businessDate'),
                'categoryAccountId' => (int) $e('categoryAccountId'),
                'description' => $e('description'),
                'amount' => Money::parse($e('amount')),
                'paymentAccountId' => (int) $e('paymentAccountId'),
                'reference' => $e('reference') === '' ? null : $e('reference'),
                'note' => $e('note') === '' ? null : $e('note'),
            ], $e('reason'), $this->actor());
        } catch (DomainError $ex) {
            $this->addError('edit.form', $ex->userMessage);

            return;
        }

        $this->back('edited');
    }

    public function mount(Request $request): void
    {
        $this->params = $request->query();
    }

    protected function actor(): Actor
    {
        return (Principal::load((int) auth()->id()) ?? abort(403))->actor();
    }

    /** Back to the list as it was filtered, with the old success flag. */
    protected function back(string $flag): void
    {
        $list = ListFilters::parseCashbookFilters($this->params);
        $this->redirect($this->side()['path'].'?'.http_build_query([...$list['carried'], $flag => '1']));
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
            'canEdit' => RoleGuard::actorIsOwner((int) auth()->id()),
            'editHistory' => $this->editId === null ? [] : AuditService::editHistory($side['entity'], $this->editId),
            'edited' => ($this->params['edited'] ?? null) === '1',
            'created' => ($this->params['created'] ?? null) === '1',
            'voided' => ($this->params['voided'] ?? null) === '1',
        ]);
    }
}
