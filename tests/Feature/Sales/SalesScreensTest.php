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

it('refuses somebody without the right, and 404s a sale that does not exist', function () {
    $this->get('/sales')->assertRedirect('/login');
    $kofi = staff(['customers' => viewOnly()], 'kofi');
    foreach (['/sales', '/sales/new', '/sales/1', '/sales/1/receipt', '/sales/1/invoice'] as $path) {
        signedIn($kofi)->get($path)->assertRedirect('/no-access?area=sales');
    }
    signedIn(staff(['sales' => viewOnly()], 'yaw'))->get('/sales')->assertOk()->assertDontSee('New sale');
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

    expect(Shop::productRow($this->rice)->qty_on_hand_milli)->toBe(98_000)
        ->and((int) DB::table('sale_items')->where('id', $item)->value('returned_qty_milli'))->toBe(1_000);
});

it('prints a receipt with change, and says a cash sale has no invoice', function () {
    stockRice($this);
    $sale = Shop::sell($this->actor, [[$this->rice, 2_000]], [['paymentAccountId' => $this->cash, 'amount' => 5_000]], ['businessDate' => $this->today]);
    signedIn($this->ownerId)->get("/sales/{$sale['saleId']}/receipt?print=1")->assertOk()
        ->assertSee('Paid by')->assertSee('window.print()', false)->assertSee('history.replaceState', false);
    signedIn($this->ownerId)->get("/sales/{$sale['saleId']}/invoice")->assertOk()->assertSee('This sale has no invoice');
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
