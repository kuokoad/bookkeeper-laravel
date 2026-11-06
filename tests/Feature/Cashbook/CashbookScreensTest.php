<?php

declare(strict_types=1);

/*
| The expenses and other-income screens: the old (app)/expenses and
| (app)/income pages and their cashbook.actions.
*/

use App\Livewire\Cashbook\Expenses;
use App\Livewire\Cashbook\Income;
use App\Models\User;
use App\Services\Actor;
use App\Services\CashbookService;
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
});

function cashbookAs(int $userId, string $page, array $query = []): mixed
{
    return Livewire::withQueryParams($query)->actingAs(User::query()->findOrFail($userId))->test($page);
}

it('renders both pages over the demo shop with the service figures', function () {
    (new DemoSeeder)->seedDemo();
    $params = ['period' => 'all'];
    $filters = ListFilters::parseCashbookFilters($params)['filters'];
    $expenses = CashbookService::getFilteredExpensesSummary($filters);
    $incomes = CashbookService::getFilteredIncomesSummary($filters);
    expect($expenses['count'])->toBeGreaterThan(0);

    signedIn($this->ownerId)->get('/expenses?period=all')->assertOk()
        ->assertSee('Money spent running the shop')
        ->assertSee(Format::money($expenses['total']))
        ->assertSee('Where the money went');
    signedIn($this->ownerId)->get('/income?period=all')->assertOk()
        ->assertSee('Other income')
        ->assertSee(Format::money($incomes['total']));
});

it('refuses somebody without the right', function () {
    $this->get('/expenses')->assertRedirect('/login');
    signedIn(staff(['sales' => viewOnly()]))->get('/expenses')->assertRedirect('/no-access?area=expenses');
    signedIn(staff(['sales' => viewOnly()], 'kofi'))->get('/income')->assertRedirect('/no-access?area=income');
    signedIn(staff(['expenses' => viewOnly()], 'yaw'))->get('/expenses')->assertOk()->assertSee('You do not have permission to record expenses.');
});

it('records an expense through the service and returns to the filtered list', function () {
    $category = Shop::expenseCategory('Transport');
    cashbookAs($this->ownerId, Expenses::class, ['period' => 'today'])
        ->set('categoryAccountId', (string) $category)
        ->set('description', 'Taxi to the market')
        ->set('amount', '25.50')
        ->call('record')
        ->assertHasNoErrors()
        ->assertRedirect('/expenses?period=today&created=1');

    expect(DB::table('expenses')->value('amount_minor'))->toBe(2_550);
    cashbookAs($this->ownerId, Expenses::class, ['period' => 'today', 'created' => '1'])
        ->assertSee('Expense recorded. The money came out of the account you chose.')
        ->assertSee('GHS 25.50');
});

it('shows the form and service messages', function () {
    cashbookAs($this->ownerId, Expenses::class)
        ->set('description', '')
        ->call('record')
        ->assertHasErrors(['categoryAccountId' => 'Choose a category.', 'description' => 'Enter a short description.', 'amount' => 'Enter an amount.']);

    cashbookAs($this->ownerId, Income::class)
        ->set('categoryAccountId', (string) Shop::expenseCategory('Transport'))
        ->set('description', 'Wrong side')
        ->set('amount', '5')
        ->call('record')
        ->assertHasErrors('form')
        ->assertSee('is not a valid income category.');
    expect(DB::table('incomes')->count())->toBe(0);
});

it('refuses each write without its right', function () {
    $id = staff(['expenses' => viewOnly(), 'income' => viewOnly()]);
    cashbookAs($id, Expenses::class)->call('record')->assertForbidden();
    cashbookAs($id, Income::class)->call('addCategory')->assertForbidden();
    cashbookAs($id, Expenses::class)->call('void', 1)->assertForbidden();
});

it('voids a row with a reason, and asks for one', function () {
    $row = Shop::expense($this->actor, $this->today, Shop::expenseCategory('Transport'), 1_000);
    $page = cashbookAs($this->ownerId, Expenses::class)->call('void', $row['id'])
        ->assertHasErrors(['reason.'.$row['id'] => 'Give a reason for voiding this.']);
    $page->set('reasons.'.$row['id'], 'Entered twice')->call('void', $row['id'])->assertHasNoErrors()->assertRedirect('/expenses?voided=1');
    expect(DB::table('expenses')->value('status'))->toBe('VOIDED');
});

it('adds a category', function () {
    cashbookAs($this->ownerId, Income::class)->call('addCategory')->assertHasErrors(['name' => 'Enter a category name.'])
        ->set('newCategory', 'Table rental')->call('addCategory')->assertHasNoErrors();
    expect(Shop::incomeCategory('Table rental'))->toBeInt();
});

it('shows the resolved filters and narrows rows and totals alike', function () {
    $transport = Shop::expenseCategory('Transport');
    Shop::expense($this->actor, $this->today, $transport, 1_000, 'Taxi');
    Shop::expense($this->actor, $this->today, Shop::expenseCategory('Rent'), 50_000, 'Shop rent');

    $html = cashbookAs($this->ownerId, Expenses::class, ['category' => (string) $transport, 'min' => 'junk', 'from' => 'nonsense'])->html();
    expect($html)->toContain('Taxi')->not->toContain('Shop rent')
        ->toContain('GHS 10.00')->not->toContain('GHS 510.00')
        ->toMatch('/<option value="'.$transport.'"\s+selected/')
        ->toContain('value="'.substr($this->today, 0, 7).'-01"');
});
