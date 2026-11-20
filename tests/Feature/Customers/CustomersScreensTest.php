<?php

declare(strict_types=1);

/*
| The customer screens: the old (app)/customers list, add/edit form, the
| customer page (receive payment, void a payment) and the statement.
*/

use App\Livewire\Customers\Form;
use App\Livewire\Customers\Index;
use App\Livewire\Customers\Show;
use App\Models\User;
use App\Services\Actor;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Support\Clock;
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
});

function customersAs(int $userId, string $page, array $params = [], array $query = []): mixed
{
    return Livewire::withQueryParams($query)->actingAs(User::query()->findOrFail($userId))->test($page, $params);
}

/** A customer owing 30.00 on one credit sale of rice. */
function owingCustomer(object $test): array
{
    $rice = Shop::product($test->actor, 'Rice 5kg', 500, 1000);
    Shop::openingStock($test->actor, $rice, 100_000, 50_000, '2026-01-01');
    $customer = Shop::customer($test->actor, 'Adwoa Mensah', null, ['phone' => '024 555 0101']);
    $sale = Shop::sell($test->actor, [[$rice, 3_000]], [], ['businessDate' => $test->today, 'customerId' => $customer]);

    return ['customer' => $customer, 'sale' => $sale];
}

it('renders the list, a customer and the statement over the demo shop', function () {
    (new DemoSeeder)->seedDemo();
    $owed = CustomerService::getTotalReceivables();
    expect($owed)->toBeGreaterThan(0);
    signedIn($this->ownerId)->get('/customers')->assertOk()
        ->assertSee('Who buys from you, and who owes you money.')
        ->assertSee(Format::money($owed))->assertSee('Adom Construction Ltd');

    $adom = (int) DB::table('customers')->where('name', 'Adom Construction Ltd')->value('id');
    signedIn($this->ownerId)->get("/customers/{$adom}")->assertOk()->assertSee('Currently owes')->assertSee('Payments received');
    signedIn($this->ownerId)->get("/customers/{$adom}/statement")->assertOk()->assertSee('Statement')
        ->assertSee(Format::money(CustomerService::getCustomerBalance($adom)))->assertDontSee('does not tie to the ledger');
    signedIn($this->ownerId)->get("/customers/{$adom}/edit")->assertOk()->assertSee('Edit customer');
    signedIn($this->ownerId)->get('/customers/new')->assertOk()->assertSee('Add customer');
});

it('refuses somebody without the right', function () {
    $this->get('/customers')->assertRedirect('/login');
    $kofi = staff(['sales' => viewOnly()], 'kofi');
    foreach (['/customers', '/customers/new', '/customers/1', '/customers/1/edit', '/customers/1/statement'] as $path) {
        signedIn($kofi)->get($path)->assertRedirect('/no-access?area=customers');
    }
    $viewer = staff(['customers' => viewOnly()], 'yaw');
    signedIn($viewer)->get('/customers/new')->assertRedirect('/no-access?area=customers');
    signedIn($viewer)->get('/customers')->assertOk()->assertDontSee('Add customer');
    customersAs($viewer, Form::class)->set('name', 'Sneaky')->call('save')->assertForbidden();
    signedIn($this->ownerId)->get('/customers/999999')->assertNotFound();
});

it('shows the resolved filters and narrows by balance', function () {
    ['customer' => $owing] = owingCustomer($this);
    Shop::customer($this->actor, 'Kojo Clear');

    customersAs($this->ownerId, Index::class, [], ['balance' => 'owing', 'q' => 'adwoa'])
        ->assertSee('Adwoa Mensah')->assertDontSee('Kojo Clear')
        ->assertSeeHtml('<option value="owing" selected')->assertSeeHtml('value="adwoa"')
        ->assertSeeHtml('/export/list/customers?q=adwoa&amp;balance=owing');
    customersAs($this->ownerId, Index::class, [], ['balance' => 'zero'])->assertSee('Kojo Clear')->assertDontSee('Adwoa Mensah');
    // The shop-wide figure does not narrow with the filter.
    customersAs($this->ownerId, Index::class, [], ['balance' => 'zero'])->assertSee(Format::money(3_000));
    customersAs($this->ownerId, Index::class, [], ['q' => 'nobody'])->assertSee('No customers match');
});

it('adds and edits a customer, keeping a blank limit apart from a zero one', function () {
    customersAs($this->ownerId, Form::class)->set('name', '  ')->call('save')
        ->assertHasErrors(['name'])->assertSee('Enter the customer’s name.');
    customersAs($this->ownerId, Form::class)->set('name', 'Ama')->set('creditLimit', '1.234')->call('save')
        ->assertHasErrors(['creditLimit'])->assertSee('Amounts can have at most 2 decimal places');

    customersAs($this->ownerId, Form::class)->set('name', 'Efua Boateng')->set('phone', '020 123 4567')->set('creditLimit', '')->call('save')->assertHasNoErrors();
    $id = (int) DB::table('customers')->where('name', 'Efua Boateng')->value('id');
    expect(DB::table('customers')->where('id', $id)->value('credit_limit_minor'))->toBeNull();

    customersAs($this->ownerId, Form::class, ['id' => $id])->assertSet('name', 'Efua Boateng')
        ->set('creditLimit', '0')->call('save')->assertHasNoErrors()->assertRedirect("/customers/{$id}");
    expect((int) DB::table('customers')->where('id', $id)->value('credit_limit_minor'))->toBe(0);
});

it('receives a payment against the oldest sale and voids it again', function () {
    ['customer' => $customer, 'sale' => $sale] = owingCustomer($this);

    customersAs($this->ownerId, Show::class, ['id' => $customer])
        ->assertSee('Applied to the oldest unpaid sale first: '.$sale['receiptNo'])
        ->set('amount', '')->call('receivePayment')->assertHasErrors(['amount'])->assertSee('Enter an amount.');
    customersAs($this->ownerId, Show::class, ['id' => $customer])->set('amount', '500')->call('receivePayment')->assertHasErrors(['payment']);

    customersAs($this->ownerId, Show::class, ['id' => $customer])->call('payAll')->assertSet('amount', '30.00')
        ->set('amount', '12.50')->call('receivePayment')->assertHasNoErrors()->assertRedirect("/customers/{$customer}");
    expect(CustomerService::getCustomerBalance($customer))->toBe(1_750);

    $payment = (int) DB::table('customer_payments')->value('id');
    customersAs($this->ownerId, Show::class, ['id' => $customer])->call('voidPayment', $payment)
        ->assertHasErrors(["voidReasons.{$payment}"])->assertSee('Give a reason for voiding this payment.');
    customersAs($this->ownerId, Show::class, ['id' => $customer])->set("voidReasons.{$payment}", 'Wrong customer')->call('voidPayment', $payment)->assertHasNoErrors();
    expect(CustomerService::getCustomerBalance($customer))->toBe(3_000);
});

it('records one payment when the button is pressed again after a lost reply, and refuses the form if it changed', function () {
    ['customer' => $customer] = owingCustomer($this);

    $page = customersAs($this->ownerId, Show::class, ['id' => $customer])->set('amount', '12.50');
    $page->call('receivePayment')->assertHasNoErrors();
    $page->call('receivePayment')->assertHasNoErrors()->assertRedirect("/customers/{$customer}");
    expect(DB::table('customer_payments')->count())->toBe(1)
        ->and(CustomerService::getCustomerBalance($customer))->toBe(1_750);

    $page->set('amount', '5.00')->call('receivePayment')->assertHasErrors(['payment'])->assertSee(DB::table('customer_payments')->value('payment_no'));
    expect(DB::table('customer_payments')->count())->toBe(1);
});

it('refuses a payment form sent again after its payment was voided, or with another reference', function () {
    ['customer' => $customer] = owingCustomer($this);
    $page = customersAs($this->ownerId, Show::class, ['id' => $customer])->set('amount', '12.50');
    $page->call('receivePayment')->assertHasNoErrors();

    $page->set('reference', 'MoMo 4471')->call('receivePayment')->assertHasErrors(['payment']);
    CustomerPaymentService::voidCustomerPayment((int) DB::table('customer_payments')->value('id'), 'Wrong customer', $this->actor);
    $page->set('reference', '')->call('receivePayment')->assertHasErrors(['payment']);

    expect(DB::table('customer_payments')->count())->toBe(1)
        ->and(CustomerService::getCustomerBalance($customer))->toBe(3_000);
});

it('refuses receiving and voiding payments without those rights', function () {
    ['customer' => $customer] = owingCustomer($this);
    $viewer = staff(['customers' => viewOnly()], 'yaw');
    customersAs($viewer, Show::class, ['id' => $customer])->assertDontSee('Receive payment')
        ->set('amount', '10')->call('receivePayment')->assertForbidden();
    customersAs($viewer, Show::class, ['id' => $customer])->call('voidPayment', 1)->assertForbidden();
    expect(DB::table('customer_payments')->count())->toBe(0);
});

it('lists every unpaid invoice on the statement and totals the ledger balance', function () {
    ['customer' => $customer, 'sale' => $sale] = owingCustomer($this);
    $invoice = DB::table('sales')->where('id', $sale['saleId'])->value('invoice_no');
    signedIn($this->ownerId)->get("/customers/{$customer}/statement")->assertOk()
        ->assertSee($invoice)->assertSee('Total owed')->assertSee('GHS 30.00')
        ->assertSee('Back to Adwoa Mensah');

    $clear = Shop::customer($this->actor, 'Kojo Clear');
    signedIn($this->ownerId)->get("/customers/{$clear}/statement")->assertOk()->assertSee('Nothing outstanding');
});

it('prints the statement as at a chosen earlier day, showing what was owed then (D33)', function () {
    $rice = Shop::product($this->actor, 'Rice 5kg', 6_000, 10_000, ['unit' => 'bag']);
    Shop::openingStock($this->actor, $rice, 10_000, 60_000, '2026-01-01');
    $customer = Shop::customer($this->actor, 'Mensah Provisions');
    // An invoice of 100.00 on 5 Jan, 60.00 paid on 20 Jan.
    $sale = Shop::sell($this->actor, [[$rice, 1_000]], [], ['businessDate' => '2026-01-05', 'customerId' => $customer])['saleId'];
    CustomerPaymentService::recordCustomerPayment([
        'customerId' => $customer, 'businessDate' => '2026-01-20', 'paymentAccountId' => $this->cash, 'amount' => 6_000,
        'allocations' => [['saleId' => $sale, 'amount' => 6_000]],
    ], $this->actor);
    $url = "/customers/{$customer}/statement";

    signedIn($this->ownerId)->get("{$url}?asAt=2026-01-15")->assertOk()
        ->assertSee('value="2026-01-15"', false)->assertSee('As at '.Format::date('2026-01-15'))
        ->assertSee('GHS 100.00')->assertDontSee('GHS 40.00');
    signedIn($this->ownerId)->get("{$url}?asAt=2026-01-20")->assertOk()->assertSee('GHS 40.00')->assertDontSee('GHS 100.00');
    signedIn($this->ownerId)->get($url)->assertOk()->assertSee("value=\"{$this->today}\"", false)->assertSee('GHS 40.00');
});

it('falls back to today for a statement date that is not a real day or is still to come, and shows the date used', function (string $asAt) {
    ['customer' => $customer] = owingCustomer($this);

    signedIn($this->ownerId)->get("/customers/{$customer}/statement?asAt={$asAt}")->assertOk()
        ->assertSee("value=\"{$this->today}\"", false)->assertSee('As at '.Format::date($this->today))->assertSee('GHS 30.00');
})->with(['not a day' => ['2026-02-30'], 'not a date' => ['soon'], 'after today' => ['2999-01-01']]);

it('checks payment ownership with one query, so a payment of any age can be voided but not one of another customer', function () {
    ['customer' => $customer] = owingCustomer($this);
    $other = Shop::customer($this->actor, 'Kofi Boateng');
    customersAs($this->ownerId, Show::class, ['id' => $customer])->set('amount', '10')->call('receivePayment')->assertHasNoErrors();
    $payment = (int) DB::table('customer_payments')->value('id');
    expect(CustomerPaymentService::paymentBelongsToCustomer($payment, $customer))->toBeTrue()
        ->and(CustomerPaymentService::paymentBelongsToCustomer($payment, $other))->toBeFalse();

    customersAs($this->ownerId, Show::class, ['id' => $other])->set("voidReasons.{$payment}", 'Wrong customer')->call('voidPayment', $payment)->assertNotFound();
    expect(CustomerService::getCustomerBalance($customer))->toBe(2_000);
});
