<?php

declare(strict_types=1);

namespace App\Livewire\Cashbook;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Money;
use App\Services\CashbookService;
use App\Services\PaymentAccountService;
use App\Services\SettingsService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Record several expenses at once: one date and one paying account for the
 * batch, a category, description, amount and optional reference per row.
 * Each row becomes its own expense; all are saved or none.
 */
#[Title('Record expenses')]
final class NewExpenses extends Component
{
    public string $businessDate = '';

    public string $paymentAccountId = '';

    /** @var list<array{categoryAccountId: string, description: string, amount: string, reference: string}> */
    public array $rows = [];

    public function mount(): void
    {
        $this->businessDate = Clock::today();
        $accounts = PaymentAccountService::listPaymentAccounts();
        $default = array_values(array_filter($accounts, fn (array $a): bool => $a['isDefault']))[0] ?? $accounts[0] ?? null;
        $this->paymentAccountId = $default === null ? '' : (string) $default['id'];
        $this->addRow();
    }

    public function addRow(): void
    {
        $this->rows[] = ['categoryAccountId' => '', 'description' => '', 'amount' => '', 'reference' => ''];
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
        if ($this->rows === []) {
            $this->addRow();
        }
    }

    public function save(): void
    {
        $this->authorize('expenses.create');
        $this->resetErrorBag();

        // The same shape checks and messages as the single-expense form.
        $errors = array_filter([
            'businessDate' => Filters::isValidBusinessDate($this->businessDate) ? null : 'Enter a valid date.',
            'paymentAccountId' => (int) $this->paymentAccountId > 0 ? null : 'Choose an account.',
        ]);
        $inputs = [];
        foreach ($this->rows as $i => $row) {
            $description = trim((string) $row['description']);
            $amount = trim((string) $row['amount']);
            $reference = trim((string) $row['reference']);
            if ((int) $row['categoryAccountId'] <= 0 && $description === '' && $amount === '' && $reference === '') {
                continue; // a spare empty row is not a mistake
            }
            $rowErrors = array_filter([
                'categoryAccountId' => (int) $row['categoryAccountId'] > 0 ? null : 'Choose a category.',
                'description' => $description === '' ? 'Enter a short description.' : (mb_strlen($description) > 200 ? 'Description must be 200 characters or fewer.' : null),
                'amount' => $amount === '' ? 'Enter an amount.' : null,
                'reference' => mb_strlen($reference) > 80 ? 'Reference must be 80 characters or fewer.' : null,
            ]);
            $minor = null;
            if ($amount !== '') {
                try {
                    $minor = Money::parse($amount);
                } catch (DomainError $e) {
                    $rowErrors['amount'] = $e->userMessage;
                }
            }
            foreach ($rowErrors as $field => $message) {
                $errors["rows.{$i}.{$field}"] = $message;
            }
            $inputs[$i] = [
                'businessDate' => $this->businessDate,
                'categoryAccountId' => (int) $row['categoryAccountId'],
                'description' => $description,
                'amount' => $minor ?? 0,
                'paymentAccountId' => (int) $this->paymentAccountId,
                'reference' => $reference === '' ? null : $reference,
                'note' => null,
            ];
        }
        if ($inputs === [] && $errors === []) {
            $errors['rows.0.description'] = 'Enter a short description.';
        }
        if ($errors !== []) {
            foreach ($errors as $field => $message) {
                $this->addError($field, $message);
            }

            return;
        }

        try {
            CashbookService::recordExpenses($inputs, (Principal::load((int) auth()->id()) ?? abort(403))->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }

        $this->redirect('/expenses?created=1');
    }

    public function render(): View
    {
        $total = 0;
        foreach ($this->rows as $row) {
            try {
                $total += trim((string) $row['amount']) === '' ? 0 : Money::parse(trim((string) $row['amount']));
            } catch (DomainError) {
                // An amount still being typed counts as nothing until it parses.
            }
        }

        return view('livewire.cashbook.new-expenses', [
            'categories' => PaymentAccountService::listExpenseCategories(),
            'accounts' => PaymentAccountService::listPaymentAccounts(),
            'currency' => SettingsService::get()->currency_code ?? 'GHS',
            'total' => $total,
        ]);
    }
}
