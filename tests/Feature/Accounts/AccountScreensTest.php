<?php

declare(strict_types=1);

/*
| The accounts screens: the old (app)/accounts page with its forms, the
| transfers list, and one account's statement (cashbook.actions).
*/

use App\Livewire\Accounts\Index;
use App\Livewire\Accounts\Show;
use App\Livewire\Accounts\Transfers;
use App\Models\User;
use App\Services\Actor;
use App\Services\CashbookService;
use App\Services\PaymentAccountService;
use App\Services\TransferService;
use App\Support\Clock;
use App\Support\Filters\ListFilters;
use App\View\Format;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Shop;

beforeEach(function () {
    $this->ownerId = owner();
    $this->actor = new Actor($this->ownerId, 'kwame');
    $this->today = Clock::today();
    $this->cash = Shop::paymentAccount('CASH');
    $this->bank = Shop::paymentAccount('BANK');
});

function accountsAs(int $userId, string $page, array $query = [], array $props = []): mixed
{
    return Livewire::withQueryParams($query)->actingAs(User::query()->findOrFail($userId))->test($page, $props);
}

it('renders every accounts page over the demo shop with the ledger figures', function () {
    (new DemoSeeder)->seedDemo();
    $cash = PaymentAccountService::getPaymentAccount($this->cash);

    signedIn($this->ownerId)->get('/accounts')->assertOk()
        ->assertSee('Every pot of money the shop holds')
        ->assertSee(Format::money($cash['balance'], bare: true));
    signedIn($this->ownerId)->get('/accounts/transfers')->assertOk()->assertSee('Transfers');

    $statement = PaymentAccountService::getAccountStatement($this->cash, ListFilters::parseAccountFilters(['period' => 'all'])['filters']);
    signedIn($this->ownerId)->get('/accounts/cash?period=all')->assertOk()
        ->assertSee($cash['name'])
        ->assertSee('Money in')
        ->assertSee(Format::money($statement['moneyIn'], bare: true))
        ->assertSee(Format::money($statement['closing'], bare: true));
    signedIn($this->ownerId)->get('/accounts/'.$this->bank)->assertOk();
    signedIn($this->ownerId)->get('/accounts/nope')->assertNotFound();
    signedIn($this->ownerId)->get('/accounts/999999')->assertNotFound();
});

it('refuses somebody without the right', function () {
    $this->get('/accounts')->assertRedirect('/login');
    $sales = staff(['sales' => viewOnly()]);
    foreach (['/accounts', '/accounts/transfers', '/accounts/cash'] as $path) {
        signedIn($sales)->get($path)->assertRedirect('/no-access?area=accounts');
    }
    signedIn(staff(['accounts' => viewOnly()], 'kofi'))->get('/accounts')->assertOk()->assertDontSee('Add an account');
});

it('refuses each write without its right', function () {
    $id = staff(['accounts' => viewOnly()]);
    accountsAs($id, Index::class)->call('createAccount')->assertForbidden();
    accountsAs($id, Index::class)->call('recordTransfer')->assertForbidden();
    accountsAs($id, Index::class)->call('recordOwnerMoney')->assertForbidden();
    accountsAs($id, Transfers::class)->call('void', 1)->assertForbidden();
    accountsAs($id, Show::class, [], ['id' => 'cash'])->call('save')->assertForbidden();
});

it('adds an account and shows the service refusal', function () {
    accountsAs($this->ownerId, Index::class)->call('createAccount')->assertHasErrors(['name' => 'Enter a name.'])
        ->set('name', 'Telecel Cash')->set('provider', 'Telecel')->call('createAccount')->assertHasNoErrors()
        ->set('name', 'telecel cash')->call('createAccount')->assertHasErrors('account')
        ->assertSee('An account called "telecel cash" already exists.');
    expect(DB::table('payment_accounts')->where('name', 'Telecel Cash')->value('provider'))->toBe('Telecel');
});

it('records a transfer and reports its number', function () {
    Shop::capital($this->actor, $this->cash, 50_000, $this->today);
    $page = accountsAs($this->ownerId, Index::class)
        ->set('fromAccountId', (string) $this->cash)->set('toAccountId', (string) $this->cash)->call('recordTransfer')
        ->assertHasErrors(['toAccountId' => 'Choose a different account from the one the money came out of.', 'transferAmount' => 'Enter an amount.']);
    $page->set('toAccountId', (string) $this->bank)->set('transferAmount', '200')->call('recordTransfer')->assertHasNoErrors()
        ->assertSee('recorded. Both balances have been updated.');
    expect(PaymentAccountService::getPaymentAccountBalance($this->bank))->toBe(20_000)
        ->and(PaymentAccountService::getPaymentAccountBalance($this->cash))->toBe(30_000);
});

it('records owner money in and out through the cashbook', function () {
    accountsAs($this->ownerId, Index::class)
        ->set('ownerAccountId', (string) $this->cash)->set('ownerAmount', 'abc')->call('recordOwnerMoney')->assertHasErrors('ownerAmount')
        ->assertNotDispatched('toast')
        ->set('ownerAmount', '100')->call('recordOwnerMoney')->assertHasNoErrors()
        // D21: the form clears AND says it was saved.
        ->assertDispatched('toast', message: 'Recorded. The account balance was updated.', tone: 'success')
        ->call('setDirection', 'OUT')->assertSee('Record money out')
        ->set('ownerAmount', '30')->call('recordOwnerMoney')->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Recorded. The account balance was updated.', tone: 'success');
    expect(PaymentAccountService::getPaymentAccountBalance($this->cash))->toBe(7_000);
});

it('lists recent owner money and voids a mistake in its row', function () {
    $drawing = CashbookService::recordOwnerDrawings(['businessDate' => $this->today, 'paymentAccountId' => $this->cash, 'amount' => 5_000_000, 'description' => 'School fees'], $this->actor);
    accountsAs($this->ownerId, Index::class)->assertSee('Recent owner money')->assertSee($drawing['documentNo'])
        ->call('voidOwnerMoney', $drawing['id'])->assertHasErrors(['reason.'.$drawing['id'] => 'Give a reason for voiding this.'])
        ->set('reasons.'.$drawing['id'], 'Typed 50,000 for 500')->call('voidOwnerMoney', $drawing['id'])->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Voided. A reversing entry was posted today; the record was kept.', tone: 'success');
    expect(DB::table('owner_movements')->where('id', $drawing['id'])->value('status'))->toBe('VOIDED');
});

it('voids a transfer with a reason', function () {
    Shop::capital($this->actor, $this->cash, 50_000, $this->today);
    $t = TransferService::recordTransfer(['businessDate' => $this->today, 'fromAccountId' => $this->cash, 'toAccountId' => $this->bank, 'amount' => 10_000], $this->actor);
    accountsAs($this->ownerId, Transfers::class)->assertSee($t['transferNo'])
        ->call('void', $t['id'])->assertHasErrors(['reason.'.$t['id'] => 'Give a reason for voiding this.'])
        ->set('reasons.'.$t['id'], 'Wrong amount')->call('void', $t['id'])->assertRedirect('/accounts/transfers?voided=1');
    expect(DB::table('account_transfers')->value('status'))->toBe('VOIDED')
        ->and(PaymentAccountService::getPaymentAccountBalance($this->bank))->toBe(0);
});

it('edits an account name and keeps its kind', function () {
    accountsAs($this->ownerId, Show::class, [], ['id' => 'cash'])->set('name', '')->call('save')->assertHasErrors(['name' => 'Enter a name.'])
        ->set('name', 'Till drawer')->call('save')->assertHasNoErrors()->assertRedirect('/accounts/cash?updated=1');
    expect(DB::table('payment_accounts')->where('id', $this->cash)->value('name'))->toBe('Till drawer')
        ->and(DB::table('payment_accounts')->where('id', $this->cash)->value('kind'))->toBe('CASH');
});

it('shows the resolved statement filters and labels a narrowed view', function () {
    Shop::capital($this->actor, $this->cash, 50_000, $this->today);
    Shop::expense($this->actor, $this->today, Shop::expenseCategory('Transport'), 1_000);

    $html = accountsAs($this->ownerId, Show::class, ['flow' => 'out', 'type' => 'BOGUS', 'from' => 'junk'], ['id' => 'cash'])->html();
    expect($html)->toContain('Filtered money out')->toContain('Filtered running total')
        ->toMatch('/<option value="out"\s+selected/')
        ->not->toMatch('/<option value="BOGUS"/')
        ->toContain('value="'.substr($this->today, 0, 7).'-01"')
        ->toContain('/export/list/account?from=junk&amp;type=BOGUS&amp;flow=out&amp;id='.$this->cash);
});

it('lets owner money older than the newest twenty be reached, so it can still be voided', function () {
    for ($i = 1; $i <= 21; $i++) {
        CashbookService::recordOwnerCapital(['businessDate' => $this->today, 'paymentAccountId' => $this->cash, 'amount' => 1_000 * $i, 'description' => "Float {$i}"], $this->actor);
    }
    $oldest = DB::table('owner_movements')->orderBy('id')->value('movement_no');

    $page = accountsAs($this->ownerId, Index::class)->assertSee('Showing 1–20 of 21')->assertDontSee($oldest);
    $page->call('olderOwnerMoney')->assertSee('Showing 21–21 of 21')->assertSee($oldest);
});
