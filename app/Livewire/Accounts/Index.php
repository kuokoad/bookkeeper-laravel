<?php

declare(strict_types=1);

namespace App\Livewire\Accounts;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Money;
use App\Enums\PaymentAccountKind;
use App\Services\Actor;
use App\Services\CashbookService;
use App\Services\PaymentAccountService;
use App\Services\Reporting\BalancesService;
use App\Services\SettingsService;
use App\Services\TransferService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The old (app)/accounts/page.tsx with its three forms: add an account, move
 * money between accounts, and owner money in or out. Balances are the
 * ledger's (PaymentAccountService); nothing here keeps a total.
 */
#[Title('Accounts')]
final class Index extends Component
{
    public const KIND_LABELS = ['CASH' => 'Cash', 'MOBILE_MONEY' => 'Mobile money', 'BANK' => 'Bank', 'OTHER' => 'Other'];

    // Add an account.
    public string $name = '';

    public string $kind = 'MOBILE_MONEY';

    public string $provider = '';

    public string $accountNumber = '';

    public bool $isDefault = false;

    // Move money between accounts.
    public string $transferDate = '';

    public string $fromAccountId = '';

    public string $toAccountId = '';

    public string $transferAmount = '';

    public string $transferReference = '';

    public string $transferNote = '';

    public ?string $transferDone = null;

    // Owner money.
    public string $direction = 'IN';

    public string $ownerDate = '';

    public string $ownerAccountId = '';

    public string $ownerAmount = '';

    public string $ownerDescription = '';

    public function mount(): void
    {
        $this->transferDate = $this->ownerDate = Clock::today();
        $active = PaymentAccountService::listPaymentAccounts();
        $from = array_values(array_filter($active, fn (array $a): bool => $a['isDefault']))[0] ?? $active[0] ?? null;
        $others = array_values(array_filter($active, fn (array $a): bool => $a['id'] !== ($from['id'] ?? null)));
        $to = array_values(array_filter($others, fn (array $a): bool => $a['kind'] === 'BANK'))[0] ?? $others[0] ?? null;
        $this->fromAccountId = $this->ownerAccountId = $from === null ? '' : (string) $from['id'];
        $this->toAccountId = $to === null ? '' : (string) $to['id'];
    }

    private function actor(): Actor
    {
        return (Principal::load((int) auth()->id()) ?? abort(403))->actor();
    }

    /** @param array<string, ?string> $errors */
    private function fail(array $errors): bool
    {
        foreach (array_filter($errors) as $field => $message) {
            $this->addError($field, $message);
        }

        return array_filter($errors) !== [];
    }

    private function parseAmount(string $text, string $field): ?int
    {
        try {
            return Money::parse($text);
        } catch (DomainError $e) {
            $this->addError($field, $e->userMessage);

            return null;
        }
    }

    public function createAccount(): void
    {
        $this->authorize('accounts.create');
        $this->resetErrorBag();
        if ($this->fail([
            'name' => trim($this->name) === '' ? 'Enter a name.' : (mb_strlen(trim($this->name)) > 80 ? 'Name must be 80 characters or fewer.' : null),
            'kind' => PaymentAccountKind::tryFrom($this->kind) === null ? 'Choose a type.' : null,
            'provider' => mb_strlen(trim($this->provider)) > 60 ? 'Provider must be 60 characters or fewer.' : null,
            'accountNumber' => mb_strlen(trim($this->accountNumber)) > 60 ? 'Account number must be 60 characters or fewer.' : null,
        ])) {
            return;
        }
        try {
            PaymentAccountService::createPaymentAccount([
                'name' => trim($this->name),
                'kind' => $this->kind,
                'provider' => trim($this->provider) === '' ? null : trim($this->provider),
                'accountNumber' => trim($this->accountNumber) === '' ? null : trim($this->accountNumber),
                'isDefault' => $this->isDefault,
            ], $this->actor());
        } catch (DomainError $e) {
            $this->addError('account', $e->userMessage);

            return;
        }
        $this->reset('name', 'kind', 'provider', 'accountNumber', 'isDefault');
        $this->dispatch('account-added');
    }

    public function recordTransfer(): void
    {
        $this->authorize('accounts.create');
        $this->resetErrorBag();
        $this->transferDone = null;
        $from = (int) $this->fromAccountId;
        $to = (int) $this->toAccountId;
        if ($this->fail([
            'transferDate' => Filters::isValidBusinessDate($this->transferDate) ? null : 'Enter a valid date.',
            'fromAccountId' => $from > 0 ? null : 'Choose where the money came from.',
            'toAccountId' => $to <= 0 ? 'Choose where the money went.' : ($from === $to ? 'Choose a different account from the one the money came out of.' : null),
            'transferAmount' => trim($this->transferAmount) === '' ? 'Enter an amount.' : null,
            'transferReference' => mb_strlen(trim($this->transferReference)) > 80 ? 'Reference must be 80 characters or fewer.' : null,
            'transferNote' => mb_strlen(trim($this->transferNote)) > 300 ? 'Note must be 300 characters or fewer.' : null,
        ])) {
            return;
        }
        $amount = $this->parseAmount($this->transferAmount, 'transferAmount');
        if ($amount === null) {
            return;
        }
        try {
            $result = TransferService::recordTransfer([
                'businessDate' => $this->transferDate,
                'fromAccountId' => $from,
                'toAccountId' => $to,
                'amount' => $amount,
                'reference' => trim($this->transferReference) === '' ? null : trim($this->transferReference),
                'note' => trim($this->transferNote) === '' ? null : trim($this->transferNote),
            ], $this->actor());
        } catch (DomainError $e) {
            $this->addError('transfer', $e->userMessage);

            return;
        }
        // A fresh form after each transfer, so the next cannot be sent twice with the last amount.
        $this->reset('transferAmount', 'transferReference', 'transferNote');
        $this->transferDone = "Transfer {$result['transferNo']} recorded.";
    }

    public function recordOwnerMoney(): void
    {
        $this->authorize('accounts.create');
        $this->resetErrorBag();
        if ($this->fail([
            'ownerDate' => Filters::isValidBusinessDate($this->ownerDate) ? null : 'Enter a valid date.',
            'ownerAccountId' => (int) $this->ownerAccountId > 0 ? null : 'Choose an account.',
            'ownerAmount' => trim($this->ownerAmount) === '' ? 'Enter an amount.' : null,
            'ownerDescription' => mb_strlen(trim($this->ownerDescription)) > 200 ? 'Description must be 200 characters or fewer.' : null,
        ])) {
            return;
        }
        $amount = $this->parseAmount($this->ownerAmount, 'ownerAmount');
        if ($amount === null) {
            return;
        }
        $input = ['businessDate' => $this->ownerDate, 'paymentAccountId' => (int) $this->ownerAccountId, 'amount' => $amount, 'description' => trim($this->ownerDescription)];
        try {
            $this->direction === 'IN'
                ? CashbookService::recordOwnerCapital($input, $this->actor())
                : CashbookService::recordOwnerDrawings($input, $this->actor());
        } catch (DomainError $e) {
            $this->addError('owner', $e->userMessage);

            return;
        }
        $this->reset('ownerAmount', 'ownerDescription');
        // No redirect, so the flash is a toast (components/flash): the form clearing alone says nothing.
        $this->dispatch('toast', message: 'Recorded. The account balance was updated.', tone: 'success');
    }

    /** @var array<int, string> reason typed per owner-money row (livewire.cashbook.row-void) */
    public array $reasons = [];

    public function voidOwnerMoney(int $id): void
    {
        $this->authorize('accounts.void');
        $this->resetErrorBag();
        $reason = trim($this->reasons[$id] ?? '');
        if (mb_strlen($reason) < 3) {
            $this->addError("reason.{$id}", 'Give a reason for voiding this.');

            return;
        }
        try {
            CashbookService::voidOwnerMovement($id, $reason, $this->actor());
        } catch (DomainError $e) {
            $this->addError("void.{$id}", $e->userMessage);

            return;
        }
        unset($this->reasons[$id]);
        $this->dispatch('toast', message: 'Voided. A reversing entry was posted today; the record was kept.', tone: 'success');
    }

    public function setDirection(string $direction): void
    {
        $this->direction = $direction === 'OUT' ? 'OUT' : 'IN';
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $accounts = PaymentAccountService::listPaymentAccounts(true);
        $active = array_values(array_filter($accounts, fn (array $a): bool => $a['isActive']));

        return view('livewire.accounts.index', [
            'currency' => SettingsService::get()->currency_code ?? 'GHS',
            'accounts' => $accounts,
            'active' => $active,
            'totalHeld' => PaymentAccountService::getTotalHeld($accounts),
            'trial' => BalancesService::getTrialBalance(),
            'negative' => array_values(array_filter($active, fn (array $a): bool => $a['balance'] < 0)),
            'canCreate' => Gate::allows('accounts.create'),
            'canVoid' => Gate::allows('accounts.void'),
            'ownerMoney' => CashbookService::listOwnerMovements(),
        ]);
    }

    /** The old lib/account-routes accountHref: the three seeded accounts by name. */
    public static function href(int $id): string
    {
        return '/accounts/'.([1 => 'cash', 2 => 'momo', 3 => 'bank'][$id] ?? $id);
    }
}
