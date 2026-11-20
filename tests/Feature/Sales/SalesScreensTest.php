<?php

declare(strict_types=1);

/*
| The sales screens: the old (app)/sales list, the sale page (void, customer
| return) and its receipt and invoice. The till has its own file.
*/

use App\Livewire\Sales\Index;
use App\Livewire\Sales\Show;
use App\Models\User;
use App\Services\Actor;
use App\Services\SaleService;
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
});

/** Rice at 10.00 with 100 in stock. Not in beforeEach: the demo seed refuses a shop that already has products. */
function stockRice(object $test): int
{
    $test->rice = Shop::product($test->actor, 'Rice 5kg', 500, 1000);
    Shop::openingStock($test->actor, $test->rice, 100_000, 50_000, '2026-01-01');

    return $test->rice;
}

function salesAs(int $userId, string $page, array $params = [], array $query = []): mixed
{
    return Livewire::withQueryParams($query)->actingAs(User::query()->findOrFail($userId))->test($page, $params);
}

it('renders the list, a sale, its receipt and invoice over the demo shop', function () {
    (new DemoSeeder)->seedDemo();
    $list = ListFilters::parseSalesFilters(['period' => 'all']);
    $summary = SaleService::getFilteredSalesSummary($list['filters']);
    expect($summary['count'])->toBeGreaterThan(0);

    signedIn($this->ownerId)->get('/sales?period=all')->assertOk()
        ->assertSee('Every sale, what it earned and what is still owed.')
        ->assertSee(Format::money($summary['revenue']))
        ->assertSee('/export/list/sales?period=all', false);

    $credit = DB::table('sales')->whereNotNull('invoice_no')->where('status', 'POSTED')->value('id');
    $sale = SaleService::getSale((int) $credit);
    signedIn($this->ownerId)->get("/sales/{$credit}")->assertOk()->assertSee($sale['receiptNo'])->assertSee('Accounting entry')->assertSee('Debits equal credits — this entry balances.');
    signedIn($this->ownerId)->get("/sales/{$credit}/receipt")->assertOk()->assertSee($sale['receiptNo'])->assertSee('Thank you.');
    signedIn($this->ownerId)->get("/sales/{$credit}/invoice")->assertOk()->assertSee($sale['invoiceNo'])->assertSee('Amount due');
});

it('offers the Create Transaction menu on the sales list only to someone who may create', function () {
    signedIn(staff(['sales' => viewOnly()], 'abena'))->get('/sales')->assertOk()->assertDontSee('Create Transaction')->assertDontSee('New sale');
    signedIn($this->ownerId)->get('/sales')->assertOk()->assertSee('New sale')->assertDontSee('Create Transaction')->assertSee('/sales/new', false);
});

it('falls back to the generic Create Transaction menu on the sales list for someone who may not start a sale', function () {
    $create = ['canView' => true, 'canCreate' => true, 'canEdit' => false, 'canVoid' => false];
    signedIn(staff(['sales' => viewOnly(), 'purchases' => $create], 'yaw'))->get('/sales')->assertOk()
        ->assertDontSee('New sale')->assertSee('Create Transaction')->assertSee('/purchases/new', false);
});

it('refuses somebody without the right, and 404s a sale that does not exist', function () {
    $this->get('/sales')->assertRedirect('/login');
    $kofi = staff(['customers' => viewOnly()], 'kofi');
    foreach (['/sales', '/sales/new', '/sales/1', '/sales/1/receipt', '/sales/1/invoice'] as $path) {
        signedIn($kofi)->get($path)->assertRedirect('/no-access?area=sales');
    }
    signedIn(staff(['sales' => viewOnly()], 'yaw'))->get('/sales')->assertOk()->assertDontSee('/sales/new', false);
    signedIn($this->ownerId)->get('/sales/999999')->assertNotFound();
    signedIn($this->ownerId)->get('/sales/999999/receipt')->assertNotFound();
});

it('shows the resolved filters and narrows the rows and totals alike', function () {
    stockRice($this);
    $customer = Shop::customer($this->actor, 'Adwoa Mensah');
    Shop::sell($this->actor, [[$this->rice, 2_000]], [['paymentAccountId' => $this->cash, 'amount' => 2_000]], ['businessDate' => $this->today]);
    $credit = Shop::sell($this->actor, [[$this->rice, 3_000]], [], ['businessDate' => $this->today, 'customerId' => $customer]);

    $page = salesAs($this->ownerId, Index::class, [], ['period' => 'today', 'paid' => 'unpaid', 'customer' => (string) $customer, 'from' => 'junk']);
    $page->assertSee($credit['receiptNo'])
        ->assertSee(Format::money(3_000))
        ->assertSeeHtml('<option value="today" selected')
        ->assertSeeHtml('<option value="'.$customer.'" selected')
        // The quick chip reads as on, and the CSV carries the same filters.
        ->assertSeeHtml('aria-pressed="true"')
        ->assertSeeHtml('/export/list/sales?period=today&amp;from=junk&amp;customer='.$customer.'&amp;paid=unpaid');
    expect(SaleService::countSales(ListFilters::parseSalesFilters(['period' => 'today', 'paid' => 'unpaid'])['filters']))->toBe(1);

    salesAs($this->ownerId, Index::class, [], ['period' => 'today', 'q' => 'nothing-like-this'])
        ->assertSee('No sales match these filters')->assertSeeHtml('value="nothing-like-this"');
});

it('voids a sale with a reason through the service', function () {
    stockRice($this);
    $sale = Shop::sell($this->actor, [[$this->rice, 2_000]], [['paymentAccountId' => $this->cash, 'amount' => 2_000]], ['businessDate' => $this->today]);

    salesAs($this->ownerId, Show::class, ['id' => $sale['saleId']])->set('reason', 'x')->call('void')
        ->assertHasErrors(['reason'])->assertSee('Give a reason for voiding this sale.');

    salesAs($this->ownerId, Show::class, ['id' => $sale['saleId']])->set('reason', 'Rung up twice')->call('void')
        ->assertHasNoErrors()->assertRedirect("/sales/{$sale['saleId']}");
    expect(DB::table('sales')->where('id', $sale['saleId'])->value('status'))->toBe('VOIDED')
        ->and(Shop::productRow($this->rice)->qty_on_hand_milli)->toBe(100_000);

    // A second void is the service's refusal, shown on the form.
    salesAs($this->ownerId, Show::class, ['id' => $sale['saleId']])->set('reason', 'Again please')->call('void')->assertHasErrors(['form']);
});

it('refuses voiding and returns to somebody without those rights', function () {
    stockRice($this);
    $sale = Shop::sell($this->actor, [[$this->rice, 2_000]], [['paymentAccountId' => $this->cash, 'amount' => 2_000]], ['businessDate' => $this->today]);
    $viewer = staff(['sales' => viewOnly()], 'kofi');
    salesAs($viewer, Show::class, ['id' => $sale['saleId']])->assertDontSee('Void this sale')->assertDontSee('Customer return')
        ->set('reason', 'Rung up twice')->call('void')->assertForbidden();
    salesAs($viewer, Show::class, ['id' => $sale['saleId']])->call('recordReturn')->assertForbidden();
    expect(DB::table('sales')->where('id', $sale['saleId'])->value('status'))->toBe('POSTED');
});

it('records a customer return with a cash refund through the service', function () {
    stockRice($this);
    $sale = Shop::sell($this->actor, [[$this->rice, 3_000]], [['paymentAccountId' => $this->cash, 'amount' => 3_000]], ['businessDate' => $this->today]);
    $item = (int) DB::table('sale_items')->where('sale_id', $sale['saleId'])->value('id');

    salesAs($this->ownerId, Show::class, ['id' => $sale['saleId']])->call('recordReturn')
        ->assertHasErrors(['returnForm'])->assertSee('Enter a quantity for at least one item.');
    salesAs($this->ownerId, Show::class, ['id' => $sale['saleId']])->set("returnQty.{$item}", '5')->call('recordReturn')
        ->assertHasErrors(['returnForm'])->assertSee('can still be returned');

    salesAs($this->ownerId, Show::class, ['id' => $sale['saleId']])
        ->set("returnQty.{$item}", '1')->assertSee('Value of goods being returned: GHS 10.00')
        ->set('refundAmount', '10.00')->set('returnReason', 'Torn bag')
        ->call('recordReturn')->assertHasNoErrors()->assertRedirect("/sales/{$sale['saleId']}");
    // D21: the page it lands on says the return was saved; a walk-in sale has no customer balance to mention.
    expect(session('status'))->toBe('Return recorded. Stock was updated.');

    expect(Shop::productRow($this->rice)->qty_on_hand_milli)->toBe(98_000)
        ->and((int) DB::table('sale_items')->where('id', $item)->value('returned_qty_milli'))->toBe(1_000);
});

it('refuses a refund from somebody who may sell but not void, and still lets them take the goods back', function () {
    stockRice($this);
    $sale = Shop::sell($this->actor, [[$this->rice, 3_000]], [], ['businessDate' => $this->today, 'customerId' => Shop::customer($this->actor, 'Adwoa Mensah')]);
    $item = (int) DB::table('sale_items')->where('sale_id', $sale['saleId'])->value('id');
    $cashier = staff(['sales' => ['canView' => true, 'canCreate' => true, 'canEdit' => false, 'canVoid' => false]], 'kofi');

    salesAs($cashier, Show::class, ['id' => $sale['saleId']])->set("returnQty.{$item}", '1')->set('refundAmount', '10.00')
        ->call('recordReturn')->assertHasErrors(['refundAmount']);
    expect(Shop::productRow($this->rice)->qty_on_hand_milli)->toBe(97_000);

    salesAs($cashier, Show::class, ['id' => $sale['saleId']])->set("returnQty.{$item}", '1')->call('recordReturn')->assertHasNoErrors();
    expect(Shop::productRow($this->rice)->qty_on_hand_milli)->toBe(98_000);
});

it('says the customer balance was updated only when the sale had a customer', function () {
    stockRice($this);
    $sale = Shop::sell($this->actor, [[$this->rice, 3_000]], [], ['businessDate' => $this->today, 'customerId' => Shop::customer($this->actor, 'Adwoa Mensah')]);
    $item = (int) DB::table('sale_items')->where('sale_id', $sale['saleId'])->value('id');

    salesAs($this->ownerId, Show::class, ['id' => $sale['saleId']])->set("returnQty.{$item}", '1')->call('recordReturn')->assertHasNoErrors();
    expect(session('status'))->toBe("Return recorded. Stock and the customer's balance were updated.");
});

it('prints a receipt with the change given, and says a cash sale has no invoice', function () {
    stockRice($this);
    $sale = Shop::sell($this->actor, [[$this->rice, 2_000]], [['paymentAccountId' => $this->cash, 'amount' => 5_000]], ['businessDate' => $this->today]);
    $total = (int) DB::table('sales')->where('id', $sale['saleId'])->value('total_minor');
    expect((int) DB::table('sales')->where('id', $sale['saleId'])->value('change_minor'))->toBe(5_000 - $total);
    signedIn($this->ownerId)->get("/sales/{$sale['saleId']}/receipt?print=1")->assertOk()
        ->assertSeeInOrder(['Cash received', '50.00', 'Change', Format::money(5_000 - $total, bare: true)])->assertDontSee('Paid by Cash')
        ->assertSee('window.print()', false)->assertSee('history.replaceState', false);
    signedIn($this->ownerId)->get("/sales/{$sale['saleId']}/invoice")->assertOk()->assertSee('This sale has no invoice');
});

it('prints what the customer handed over on a split sale: the MoMo paid, the cash received and the change', function () {
    stockRice($this);
    $momo = Shop::paymentAccount('MOBILE_MONEY');
    $momoName = (string) DB::table('payment_accounts')->where('id', $momo)->value('name');
    // GHS 120.00 of rice; cash 100 and MoMo 50: MoMo is applied first, cash 70, change 30 from the drawer.
    $sale = Shop::sell($this->actor, [[$this->rice, 12_000]], [['paymentAccountId' => $this->cash, 'amount' => 10_000], ['paymentAccountId' => $momo, 'amount' => 5_000]], ['businessDate' => $this->today]);

    signedIn($this->ownerId)->get("/sales/{$sale['saleId']}/receipt")->assertOk()
        ->assertSeeInOrder(["Paid by {$momoName}", '50.00', 'Cash received', '100.00', 'Change', '30.00'])
        ->assertDontSee('70.00');
});

it('keeps "Paid by Cash" when no change was given', function () {
    stockRice($this);
    $sale = Shop::sell($this->actor, [[$this->rice, 2_000]], [['paymentAccountId' => $this->cash, 'amount' => 2_000]], ['businessDate' => $this->today]);

    signedIn($this->ownerId)->get("/sales/{$sale['saleId']}/receipt")->assertOk()->assertSee('Paid by Cash')->assertDontSee('Cash received');
});

it('asks for payment on a credit invoice and credits what was already paid', function () {
    stockRice($this);
    $customer = Shop::customer($this->actor, 'Adwoa Mensah');
    $sale = Shop::sell($this->actor, [[$this->rice, 3_000]], [['paymentAccountId' => $this->cash, 'amount' => 1_000]], ['businessDate' => $this->today, 'customerId' => $customer]);
    signedIn($this->ownerId)->get("/sales/{$sale['saleId']}/invoice")->assertOk()
        ->assertSee('Billed to')->assertSee('Adwoa Mensah')
        ->assertSee('Already paid')->assertSee('−10.00')
        ->assertSee('Please pay GHS 20.00');
});

it('filters sales the old compact way: search and dates up top, the rest behind Filters with a count', function () {
    $html = fn (string $query): string => signedIn($this->ownerId)->get('/sales'.$query)->assertOk()->getContent();
    $pressed = fn (string $page, string $label): bool => (bool) preg_match('#aria-pressed="true"[^>]*>\s*'.preg_quote($label, '#').'\s*<#', $page);

    $plain = $html('');
    expect($plain)->toContain('Date range')->toContain('>Search<')->toContain('Apply Filters')
        ->and($pressed($plain, 'All Sales'))->toBeTrue();

    // Two panel filters set: the button counts them, and a quick filter turns All Sales off.
    $filtered = $html('?status=POSTED&min=100&paid=unpaid&q=RCP');
    expect($filtered)->toContain('Filters (2)')
        ->and($pressed($filtered, 'All Sales'))->toBeFalse()
        ->and($pressed($filtered, 'Credit sales'))->toBeTrue()
        // The search form carries every other filter, so searching keeps them.
        ->and($filtered)->toMatch('#<input type="hidden" name="status" value="POSTED">#')
        ->toMatch('#<input type="hidden" name="paid" value="unpaid">#');

    // All Sales clears the quick filters, and Voided only when the Voided button set it (the old statusSource=quick).
    $allHref = function (string $query) use ($html): string {
        preg_match('#href="([^"]*)"[^>]*>\s*All Sales#', $html($query), $all);

        return html_entity_decode($all[1]);
    };
    expect($allHref('?status=VOIDED&statusSource=quick&method=CASH&q=RCP'))->toBe('?q=RCP')
        ->and($allHref('?status=VOIDED&method=CASH&q=RCP'))->toBe('?q=RCP&status=VOIDED')
        // A Voided the quick button set is not counted as a panel filter.
        ->and($html('?status=VOIDED&statusSource=quick'))->not->toContain('Filters (1)')
        ->and($html('?status=VOIDED'))->toContain('Filters (1)');
});
