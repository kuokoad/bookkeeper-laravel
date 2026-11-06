<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Services\Actor;
use App\Services\CustomerService;
use App\Services\PeriodLockService;
use App\Services\Reporting\LedgerService;
use App\Services\Reporting\YearEndService;
use App\Services\SupplierService;
use App\Services\YearEndCloseService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use App\View\Format;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The old (app)/accounting/page.tsx with its books-lock-form and
 * year-end-form: the books check, the two owner-level controls (books lock,
 * year-end close and reopen) and links to the accounting pages.
 */
#[Title('Accounting')]
final class Index extends Component
{
    /** The books-lock date box ('' removes the lock). */
    public string $lockedBefore = '';

    public string $startYear = '';

    public ?string $yearMessage = null;

    public function mount(): void
    {
        $this->lockedBefore = (string) (PeriodLockService::status()['lockedBefore'] ?? '');
        $closable = array_values(array_filter($this->years(), fn (array $y): bool => ! $y['closed'] && $y['finished']));
        $this->startYear = (string) ($closable[0]['startYear'] ?? '');
    }

    private function actor(): Actor
    {
        return (Principal::load((int) auth()->id()) ?? abort(403))->actor();
    }

    /** @return list<array{startYear: int, label: string, end: string, closed: bool, finished: bool}> */
    private function years(): array
    {
        $today = Clock::today();

        return array_map(fn ($year): array => [
            'startYear' => $year->startYear,
            'label' => $year->label,
            'end' => $year->end,
            'closed' => YearEndCloseService::isYearClosed($year->startYear),
            'finished' => $today > $year->end,
        ], YearEndService::availableFinancialYears());
    }

    public function setLock(): void
    {
        // Owner-level, as the old setBooksLockAction: the settings right, not accounts.
        $this->authorize('settings.edit');
        $this->resetErrorBag();
        $raw = trim($this->lockedBefore);
        $date = $raw === '' ? null : $raw;
        if ($date !== null && ! Filters::isValidBusinessDate($date)) {
            $this->addError('lockedBefore', 'Enter a valid date.');

            return;
        }

        try {
            PeriodLockService::setLock($date, $this->actor());
        } catch (DomainError $e) {
            $this->addError('lock', $e->userMessage);

            return;
        }
        $this->dispatch('books-lock-saved');
    }

    private function readYear(int|string|null $value): ?int
    {
        $year = filter_var($value, FILTER_VALIDATE_INT);

        return $year !== false && $year > 1900 && $year < 9999 ? $year : null;
    }

    public function closeYear(): void
    {
        $this->authorize('settings.edit');
        $this->resetErrorBag();
        $this->yearMessage = null;
        $year = $this->readYear($this->startYear);
        if ($year === null) {
            $this->addError('year', 'Choose a year to close.');

            return;
        }

        try {
            $result = YearEndCloseService::closeFinancialYear($year, $this->actor());
        } catch (DomainError $e) {
            $this->addError('year', $e->userMessage);

            return;
        }
        $this->yearMessage = 'Year closed. Profit of '.Format::money($result['profit']).' carried to Retained Earnings'
            .($result['drawings'] > 0 ? ', after drawings of '.Format::money($result['drawings']) : '').'. '
            .'The books are now locked to the year end.';
        $this->lockedBefore = (string) (PeriodLockService::status()['lockedBefore'] ?? '');
    }

    public function reopenYear(int|string $startYear): void
    {
        $this->authorize('settings.edit');
        $this->resetErrorBag();
        $this->yearMessage = null;
        $year = $this->readYear($startYear);
        if ($year === null) {
            $this->addError('year', 'Choose a year to reopen.');

            return;
        }

        try {
            YearEndCloseService::reopenFinancialYear($year, $this->actor());
        } catch (DomainError $e) {
            $this->addError('year', $e->userMessage);

            return;
        }
        $this->yearMessage = 'Year reopened. The closing entry was reversed rather than deleted, and the books lock '
            .'has moved back. Close it again when you are done.';
        $this->lockedBefore = (string) (PeriodLockService::status()['lockedBefore'] ?? '');
    }

    public function render(): View
    {
        $integrity = LedgerService::checkBooksIntegrity();
        $problems = [];
        if (! $integrity['trialBalanced']) {
            $problems[] = 'total debits do not equal total credits';
        }
        if ($integrity['unbalancedEntries'] !== []) {
            $problems[] = count($integrity['unbalancedEntries']).' entry/entries do not balance';
        }
        if (! $integrity['receivablesMatch']) {
            $problems[] = 'customer balances do not match the A/R account';
        }
        if (! $integrity['payablesMatch']) {
            $problems[] = 'supplier balances do not match the A/P account';
        }
        if ($integrity['untracedEntries'] > 0) {
            $problems[] = "{$integrity['untracedEntries']} entry/entries cannot be traced to a transaction";
        }
        $years = $this->years();

        return view('livewire.accounting.index', [
            'integrity' => $integrity,
            'problems' => $problems,
            'receivables' => CustomerService::getTotalReceivables(),
            'payables' => SupplierService::getTotalPayables(),
            'lock' => PeriodLockService::status(),
            'today' => Clock::today(),
            'canControl' => Gate::allows('settings.edit'),
            'closable' => array_values(array_filter($years, fn (array $y): bool => ! $y['closed'] && $y['finished'])),
            'closed' => array_values(array_filter($years, fn (array $y): bool => $y['closed'])),
            'sections' => [
                ['href' => '/reconciliation', 'title' => 'Reconciliation', 'description' => 'Count the cash, mobile money and bank against what the books say.'],
                ['href' => '/accounting/journal', 'title' => 'Journal', 'description' => 'Every accounting entry the shop has ever made, newest first.'],
                ['href' => '/accounting/chart', 'title' => 'Chart of accounts', 'description' => 'The accounts the books are kept in, with their balances.'],
                ['href' => '/accounting/trial-balance', 'title' => 'Trial balance', 'description' => 'Proof that total debits equal total credits.'],
                ['href' => '/accounting/receivables', 'title' => 'Who owes you', 'description' => 'Customer debts by how long they have been outstanding.'],
                ['href' => '/accounting/payables', 'title' => 'Who you owe', 'description' => 'Supplier balances by how long they have been outstanding.'],
            ],
        ]);
    }
}
