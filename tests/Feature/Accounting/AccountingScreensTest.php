<?php

declare(strict_types=1);

/*
| The accounting screens: the old (app)/accounting pages, the books lock
| (accounting.actions) and year-end close/reopen (year-end-close.actions).
*/

use App\Domain\BusinessDate;
use App\Livewire\Accounting\Index;
use App\Livewire\Accounting\Journal;
use App\Livewire\Accounting\Ledger;
use App\Livewire\Accounting\Receivables;
use App\Livewire\Accounting\TrialBalance;
use App\Models\User;
use App\Services\Actor;
use App\Services\CustomerService;
use App\Services\PaymentAccountService;
use App\Services\PeriodLockService;
use App\Services\Reporting\LedgerService;
use App\Services\YearEndCloseService;
use App\Support\Clock;
use App\View\Format;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Shop;

beforeEach(function () {
    $this->ownerId = owner();
    $this->actor = new Actor($this->ownerId, 'kwame');
    $this->today = Clock::today();
});

function accountingAs(int $userId, string $page, array $query = [], array $params = []): mixed
{
    return Livewire::withQueryParams($query)->actingAs(User::query()->findOrFail($userId))->test($page, $params);
}

it('renders every page over the demo shop', function () {
    (new DemoSeeder)->seedDemo();
    $entry = (int) DB::table('journal_entries')->value('id');
    $account = (int) DB::table('journal_lines')->value('account_id');
    $as = signedIn($this->ownerId);

    $as->get('/accounting')->assertOk()->assertSee('The books are healthy')
        ->assertSee(Format::money(LedgerService::checkBooksIntegrity()['totalDebit']))
        ->assertSee(Format::money(CustomerService::getTotalReceivables()));
    $as->get('/accounting/chart')->assertOk()->assertSee('Accounts Receivable');
    $as->get('/accounting/journal?from=2000-01-01')->assertOk()->assertSee('JE-');
    $as->get("/accounting/journal/{$entry}")->assertOk()->assertSee('Business date');
    $as->get("/accounting/ledger/{$account}")->assertOk()->assertSee('Opening');
    $as->get('/accounting/trial-balance')->assertOk()->assertSee('The books balance.');
    $as->get('/accounting/receivables')->assertOk()->assertSee('Who owes you');
    $as->get('/accounting/payables')->assertOk()->assertSee('Who you owe');
    $as->get('/accounting/journal/999999')->assertNotFound();
    $as->get('/accounting/ledger/abc')->assertNotFound();
});

it('refuses somebody without the accounts right', function () {
    $this->get('/accounting')->assertRedirect('/login');
    $id = staff(['sales' => viewOnly()]);
    foreach (['/accounting', '/accounting/chart', '/accounting/journal', '/accounting/trial-balance', '/accounting/receivables', '/accounting/payables'] as $path) {
        signedIn($id)->get($path)->assertRedirect('/no-access?area=accounts');
    }
});

it('hides the owner controls and refuses their writes without settings edit', function () {
    $id = staff(['accounts' => viewOnly()]);
    accountingAs($id, Index::class)->assertDontSee('Books lock')->assertDontSee('Year-end close')
        ->call('setLock')->assertForbidden();
    accountingAs($id, Index::class)->call('closeYear')->assertForbidden();
    accountingAs($id, Index::class)->call('reopenYear', 2025)->assertForbidden();
});

it('closes the books, then reopens them, through the service', function () {
    Shop::expense($this->actor, '2026-01-10', Shop::expenseCategory('Transport'), 1_000);
    $page = accountingAs($this->ownerId, Index::class)->assertSee('Nothing is locked.');

    $page->set('lockedBefore', 'junk')->call('setLock')->assertHasErrors(['lockedBefore' => 'Enter a valid date.']);
    $page->set('lockedBefore', '2026-01-31')->call('setLock')->assertHasNoErrors()
        ->assertSee('The books are closed up to')->assertSee('1 entry(ies) are protected.')
        ->assertSee('Yes, reopen the books');
    expect(PeriodLockService::status()['lockedBefore'])->toBe('2026-01-31');

    $page->set('lockedBefore', '')->call('setLock')->assertHasNoErrors();
    expect(PeriodLockService::status()['lockedBefore'])->toBeNull()
        ->and(DB::table('audit_logs')->where('entity_type', 'books_lock')->orderByDesc('id')->value('summary'))->toContain('REOPENED');
});

it('closes a finished year and reopens it, with the old messages', function () {
    Shop::capital($this->actor, Shop::paymentAccount('CASH'), 100_000, '2025-03-01');
    Shop::income($this->actor, '2025-03-02', PaymentAccountService::listIncomeCategories()[0]['id'], 5_000);

    $page = accountingAs($this->ownerId, Index::class)->assertSee('Year to close');
    $startYear = (int) $page->get('startYear');
    $page->call('closeYear')->assertHasNoErrors()
        ->assertSee('Year closed. Profit of GHS 50.00 carried to Retained Earnings. The books are now locked to the year end.')
        ->assertSee('Closed years');
    expect(YearEndCloseService::isYearClosed($startYear))->toBeTrue();

    $page->call('reopenYear', $startYear)->assertHasNoErrors()
        ->assertSee('Year reopened. The closing entry was reversed rather than deleted');
    expect(YearEndCloseService::isYearClosed($startYear))->toBeFalse();

    $page->call('reopenYear', 'junk')->assertHasErrors(['year' => 'Choose a year to reopen.']);
    $page->call('reopenYear', $startYear)->assertHasErrors('year');
});

it('says when no finished year is waiting', function () {
    accountingAs($this->ownerId, Index::class)
        ->assertSee('There is no finished year waiting to be closed.')
        ->set('startYear', '')->call('closeYear')->assertHasErrors(['year' => 'Choose a year to close.']);
});

it('shows the trial balance totals the chart holds, as at the resolved date', function () {
    Shop::capital($this->actor, Shop::paymentAccount('CASH'), 100_000, $this->today);
    Shop::expense($this->actor, $this->today, Shop::expenseCategory('Transport'), 2_500);

    $html = accountingAs($this->ownerId, TrialBalance::class, ['to' => 'nonsense'])->html();
    $debits = array_sum(array_map(fn (array $a): int => $a['isHeader'] ? 0 : $a['totalDebit'], LedgerService::getChartOfAccounts(['to' => $this->today])));
    expect($debits)->toBe(102_500)
        ->and($html)->toContain('GHS 1,025.00')->toContain('value="'.$this->today.'"')->toContain('The books balance.');
});

it('shows the journal and ledger filters resolved', function () {
    Shop::expense($this->actor, $this->today, Shop::expenseCategory('Transport'), 2_500, 'Taxi');
    accountingAs($this->ownerId, Journal::class, ['from' => 'bad', 'source' => 'NOPE'])
        ->assertSeeHtml('value="'.substr($this->today, 0, 7).'-01"')->assertSee('Taxi');
    accountingAs($this->ownerId, Journal::class, ['source' => 'SALE'])->assertSee('No entries in this period');

    $cash = (int) DB::table('payment_accounts')->where('id', Shop::paymentAccount('CASH'))->value('gl_account_id');
    accountingAs($this->ownerId, Ledger::class, ['to' => 'junk'], ['id' => (string) $cash])
        ->assertSeeHtml('value="'.$this->today.'"')->assertSee('-25.00');
});

it('shows a trial balance asked for backwards with its dates swapped in the controls', function () {
    $html = accountingAs($this->ownerId, TrialBalance::class, ['from' => '2026-08-10', 'to' => '2026-08-01'])->html();
    expect($html)->toMatch('/<input[^>]*value="2026-08-01"[^>]*name="from"/')->toMatch('/<input[^>]*value="2026-08-10"[^>]*name="to"/');
});

it('starts the ledger at the 1st of the month and carries earlier lines in the opening balance', function () {
    $monthStart = substr($this->today, 0, 7).'-01';
    Shop::expense($this->actor, BusinessDate::addDays($monthStart, -1), Shop::expenseCategory('Transport'), 1_500, 'Old taxi');
    Shop::expense($this->actor, $this->today, Shop::expenseCategory('Transport'), 2_500, 'New taxi');

    $cash = (int) DB::table('payment_accounts')->where('id', Shop::paymentAccount('CASH'))->value('gl_account_id');
    $page = accountingAs($this->ownerId, Ledger::class, [], ['id' => (string) $cash]);
    $page->assertViewHas('lines', fn (array $lines): bool => array_column($lines, 'entryDate') === [$this->today])->assertSeeHtml('value="'.$monthStart.'"')
        ->assertViewHas('openingBalance', -1_500)->assertViewHas('closingBalance', -4_000);
});

it('ends the ledger at the To date it shows, leaving out entries dated later', function () {
    Shop::expense($this->actor, $this->today, Shop::expenseCategory('Transport'), 2_500, 'Taxi today');
    // Entered on its own day, ten days from now: a future date is refused today.
    $later = BusinessDate::addDays($this->today, 10);
    Shop::expense($this->actor, $later, Shop::expenseCategory('Transport'), 9_000, 'Rent next week', ['occurredAt' => Carbon::parse($later.' 12:00', 'Africa/Accra')]);

    $cash = (int) DB::table('payment_accounts')->where('id', Shop::paymentAccount('CASH'))->value('gl_account_id');
    accountingAs($this->ownerId, Ledger::class, [], ['id' => (string) $cash])
        ->assertSeeHtml('value="'.$this->today.'"')->assertDontSee('Rent next week')->assertViewHas('closingBalance', -2_500);
});

it('shows a ledger page past the end as the last page, with no opening row after page 1', function () {
    Shop::expense($this->actor, $this->today, Shop::expenseCategory('Transport'), 2_500, 'Taxi');

    $cash = (int) DB::table('payment_accounts')->where('id', Shop::paymentAccount('CASH'))->value('gl_account_id');
    accountingAs($this->ownerId, Ledger::class, ['page' => '9'], ['id' => (string) $cash])
        ->assertSee('-25.00')->assertSee('Showing 1–1 of 1 line')->assertViewHas('list', fn (array $list): bool => $list['page'] === 1);
});

it('pages the journal, saying how many entries there are, and shows a page past the end as the last', function () {
    foreach (range(1, 101) as $i) {
        Shop::expense($this->actor, $this->today, Shop::expenseCategory('Transport'), 100, "Taxi {$i}");
    }

    accountingAs($this->ownerId, Journal::class)->assertSee('Showing 1–100 of 101 entries')->assertSee('Page 1 of 2');
    accountingAs($this->ownerId, Journal::class, ['page' => '9'])->assertSee('Showing 101–101 of 101 entries')
        ->assertViewHas('entries', fn (array $entries): bool => count($entries) === 1);
});

it('warns of an entry that does not balance even when it is on a later page', function () {
    foreach (range(1, 101) as $i) {
        Shop::expense($this->actor, $this->today, Shop::expenseCategory('Transport'), 100, "Taxi {$i}");
    }
    // The oldest entry, on page 2, with a line that breaks its balance (written past the app, as a fault would be).
    $oldest = DB::table('journal_entries')->orderBy('id')->first();
    $line = (array) DB::table('journal_lines')->where('entry_id', $oldest->id)->first();
    DB::table('journal_lines')->insert([...array_diff_key($line, ['id' => true]), 'line_no' => 99, 'debit_minor' => 1, 'credit_minor' => 0]);

    accountingAs($this->ownerId, Journal::class)->assertSee('Page 1 of 2')->assertSee($oldest->entry_no);
});

it('narrows who owes you without breaking the ledger check', function () {
    $product = Shop::product($this->actor, 'Cement', 500, 1_000);
    Shop::openingStock($this->actor, $product, 10_000, 5_000, $this->today);
    $ama = Shop::customer($this->actor, 'Ama Serwaa');
    $kofi = Shop::customer($this->actor, 'Kofi Mensah');
    Shop::sell($this->actor, [[$product, 1_000]], [], ['customerId' => $ama, 'businessDate' => $this->today]);
    Shop::sell($this->actor, [[$product, 2_000]], [], ['customerId' => $kofi, 'businessDate' => $this->today]);

    accountingAs($this->ownerId, Receivables::class, ['customer' => (string) $ama])
        ->assertSee('Owed, as filtered')->assertSee('of GHS 30.00 in total')->assertSee('GHS 10.00')
        ->assertDontSee('Kofi Mensah</a>', false)->assertDontSee('does not agree with the ledger')
        ->assertSeeHtml('/export/report/receivables?to='.$this->today);
});

it('offers the who-owes-you file only to somebody the export will not refuse', function () {
    // The file needs the reports right as well as the accounts one; the page needs only accounts.
    signedIn(staff(['accounts' => viewOnly()]))->get('/accounting/receivables')->assertOk()->assertDontSee('/export/report/receivables', false);
    signedIn(staff(['accounts' => viewOnly(), 'reports' => viewOnly()], 'kofi'))->get('/accounting/receivables')->assertOk()->assertSee('/export/report/receivables', false);
});
