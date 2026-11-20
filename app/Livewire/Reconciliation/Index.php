<?php

declare(strict_types=1);

namespace App\Livewire\Reconciliation;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Money;
use App\Services\Actor;
use App\Services\ReconciliationService;
use App\Services\SettingsService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The old (app)/reconciliation/page.tsx and its count form. The difference
 * is shown as the owner types, before they commit, so they can recount; the
 * figure saved is the service's own (createReconciliation), never this preview.
 */
#[Title('Reconciliation')]
final class Index extends Component
{
    public array $params = [];

    public string $paymentAccountId = '';

    public string $businessDate = '';

    public string $actual = '';

    public string $explanation = '';

    public bool $adjust = true;

    /** @var array{reconciliationNo: string, difference: int, adjusted: bool}|null */
    public ?array $saved = null;

    /** @var array<int|string, string> */
    public array $reasons = [];

    private const HISTORY_PAGE_SIZE = 100;

    /** Which page of past counts is on screen, newest first. */
    public int $historyPage = 1;

    public function olderCounts(): void
    {
        $this->historyPage++;
    }

    public function newerCounts(): void
    {
        $this->historyPage = max(1, $this->historyPage - 1);
    }

    public function mount(Request $request): void
    {
        $this->params = $request->query();
        $this->businessDate = Clock::today();
        $first = ReconciliationService::getReconciliationOverview()[0] ?? null;
        $this->paymentAccountId = $first === null ? '' : (string) $first['paymentAccountId'];
    }

    private function actor(): Actor
    {
        return (Principal::load((int) auth()->id()) ?? abort(403))->actor();
    }

    public function reconcile(): void
    {
        $this->authorize('reconciliation.create');
        $this->resetErrorBag();
        $this->saved = null;
        $errors = array_filter([
            'paymentAccountId' => (int) $this->paymentAccountId > 0 ? null : 'Choose an account.',
            'businessDate' => Filters::isValidBusinessDate($this->businessDate) ? null : 'Enter a valid date.',
            'actual' => trim($this->actual) === '' ? 'Enter the amount you counted.' : null,
            'explanation' => mb_strlen(trim($this->explanation)) > 500 ? 'Explanation must be 500 characters or fewer.' : null,
        ]);
        foreach ($errors as $field => $message) {
            $this->addError($field, $message);
        }
        if ($errors !== []) {
            return;
        }
        try {
            $actual = Money::parse($this->actual);
        } catch (DomainError $e) {
            $this->addError('actual', $e->userMessage);

            return;
        }
        if ($actual < 0) {
            $this->addError('actual', 'A counted amount cannot be negative.');

            return;
        }
        try {
            $result = ReconciliationService::createReconciliation([
                'paymentAccountId' => (int) $this->paymentAccountId,
                'businessDate' => $this->businessDate,
                'actual' => $actual,
                'explanation' => trim($this->explanation) === '' ? null : trim($this->explanation),
                'adjust' => $this->adjust,
            ], $this->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }
        $this->saved = ['reconciliationNo' => $result['reconciliationNo'], 'difference' => $result['difference'], 'adjusted' => $result['adjusted']];
        $this->reset('actual', 'explanation');
    }

    public function void(int $id): void
    {
        $this->authorize('reconciliation.void');
        $this->resetErrorBag();
        $reason = trim($this->reasons[$id] ?? '');
        if (mb_strlen($reason) < 3) {
            $this->addError("reason.{$id}", 'Give a reason for voiding this count.');

            return;
        }
        try {
            ReconciliationService::voidReconciliation($id, $reason, $this->actor());
        } catch (DomainError $e) {
            $this->addError("void.{$id}", $e->userMessage);

            return;
        }
        $this->redirect('/reconciliation?voided=1');
    }

    public function render(): View
    {
        $today = Clock::today();
        $overview = ReconciliationService::getReconciliationOverview();
        $accountId = (int) $this->paymentAccountId;
        // The books' figure as at the day being counted, which is what the save compares against: counting
        // Sunday's till on Monday must not preview against Monday's sales.
        $asAt = Filters::isValidBusinessDate($this->businessDate) ? min($this->businessDate, $today) : $today;
        $expected = $accountId > 0 && in_array($accountId, array_column($overview, 'paymentAccountId'), true)
            ? ReconciliationService::getReconciliationContext($accountId, $asAt)['expected'] : 0;
        $counted = null;
        if (trim($this->actual) !== '') {
            try {
                $counted = Money::parse($this->actual);
            } catch (DomainError) {
                $counted = null;
            }
        }
        $historyTotal = ReconciliationService::countReconciliations();
        $this->historyPage = Filters::clampPage($this->historyPage, $historyTotal, self::HISTORY_PAGE_SIZE);
        $history = ReconciliationService::listReconciliations(null, self::HISTORY_PAGE_SIZE, null, ($this->historyPage - 1) * self::HISTORY_PAGE_SIZE);

        return view('livewire.reconciliation.index', [
            'currency' => SettingsService::get()->currency_code ?? 'GHS',
            'overview' => $overview,
            'history' => $history,
            'historyTotal' => $historyTotal,
            'historyList' => ['page' => $this->historyPage, 'pageSize' => self::HISTORY_PAGE_SIZE],
            ...ReconciliationService::getOverviewTotals($overview),
            'expected' => $expected,
            'badAmount' => trim($this->actual) !== '' && $counted === null,
            // A preview only: the saved difference is the service's.
            'difference' => $counted === null ? null : ReconciliationService::countDifference($counted, $expected),
            'canCount' => Gate::allows('reconciliation.create'),
            'canVoid' => Gate::allows('reconciliation.void'),
            'voided' => ($this->params['voided'] ?? null) === '1',
        ]);
    }
}
