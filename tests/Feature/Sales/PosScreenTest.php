<?php

declare(strict_types=1);

/*
| The till: the old (app)/sales/new page, pos.tsx and createSaleAction. The
| figures are the service's; the screen only previews them.
*/

use App\Domain\BusinessDate;
use App\Livewire\Sales\Pos;
use App\Models\User;
use App\Services\Actor;
use App\Services\SaleService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Shop;

beforeEach(function () {
    $this->ownerId = owner();
    $this->actor = new Actor($this->ownerId, 'kwame');
    $this->cash = Shop::paymentAccount('CASH');
    $this->rice = Shop::product($this->actor, 'Rice 5kg', 500, 1000, ['barcode' => '6001234500001', 'sku' => 'RICE-5']);
    Shop::openingStock($this->actor, $this->rice, 10_000, 5_000, '2026-01-01');
});

function tillAs(int $userId): mixed
{
    return Livewire::actingAs(User::query()->findOrFail($userId))->test(Pos::class);
}

it('opens for somebody who may sell and refuses everyone else', function () {
    signedIn($this->ownerId)->get('/sales/new')->assertOk()->assertSee('Search or scan, take payment, done.')->assertSee('Find a product');
    signedIn(staff(['sales' => viewOnly()], 'kofi'))->get('/sales/new')->assertRedirect('/no-access?area=sales');
    $seller = staff(['sales' => ['canView' => true, 'canCreate' => true, 'canEdit' => false, 'canVoid' => false]], 'yaw');
    signedIn($seller)->get('/sales/new')->assertOk();
    tillAs(staff(['sales' => viewOnly()], 'esi'))->call('add', $this->rice)->assertForbidden();
    tillAs(staff(['sales' => viewOnly()], 'abena'))->call('complete')->assertForbidden();
});

it('finds a product by name and rings it up by scanning its barcode twice', function () {
    tillAs($this->ownerId)
        ->set('search', 'rice')->assertSee('Rice 5kg')->assertSee('10 pcs in stock')
        ->set('search', '6001234500001')->call('scan')
        ->set('search', 'rice-5')->call('scan')
        ->assertSet('search', '')
        ->assertSee('Cart (1)')
        ->assertSeeHtml('value="2"')
        ->assertSee('GHS 20.00');
});

it('completes a cash sale, gives change and starts a new cart', function () {
    $till = tillAs($this->ownerId)->call('add', $this->rice)->call('add', $this->rice)
        ->set('tenderAmount', '50')->assertSee('Change')
        ->call('complete')->assertHasNoErrors();

    $sale = DB::table('sales')->first();
    expect((int) $sale->total_minor)->toBe(2_000)
        ->and($sale->client_ref)->toEndWith('-0')
        ->and(Shop::productRow($this->rice)->qty_on_hand_milli)->toBe(8_000);
    $till->assertSee('Sale saved — '.$sale->receipt_no)->assertSee('Give change: GHS 30.00')
        ->assertSee('/sales/'.$sale->id.'/receipt?print=1')
        ->assertSet('lines', [])->assertSet('salesCompleted', 1);
});

it('names the cart once, so a retry or double submit replays the first sale', function () {
    $till = tillAs($this->ownerId)->call('add', $this->rice);
    // A refused attempt keeps the cart's name: the next attempt is the same sale.
    $till->call('complete')->assertHasErrors(['form']);
    $till->set('tenderAmount', '10')->call('complete')->assertHasNoErrors();
    $sale = DB::table('sales')->first();
    expect($sale->client_ref)->toBe($till->get('cartSeed').'-0');

    // The same cart arriving again (a retry, a second tab of the same submit) is handed the first sale back.
    $replay = SaleService::createSale([
        'businessDate' => Clock::today(),
        'items' => [['productId' => $this->rice, 'qty' => 1_000, 'unitPrice' => 1_000]],
        'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 1_000]],
        'clientRef' => $sale->client_ref,
    ], $this->actor);
    expect($replay['saleId'])->toBe((int) $sale->id)
        ->and(DB::table('sales')->count())->toBe(1)
        ->and(Shop::productRow($this->rice)->qty_on_hand_milli)->toBe(9_000);

    // A second press after the sale went through finds an empty cart and does nothing.
    $till->call('complete')->assertHasNoErrors();
    expect(DB::table('sales')->count())->toBe(1);
});

it('shows the service refusals: credit with no customer, and a cashier changing the price', function () {
    tillAs($this->ownerId)->call('add', $this->rice)->assertSee('Choose a customer so the balance can be recorded against them.')
        ->call('complete')->assertHasErrors(['form'])->assertSee('This sale is not fully paid.');

    $cashier = staff(['sales' => ['canView' => true, 'canCreate' => true, 'canEdit' => false, 'canVoid' => false]], 'yaw');
    $till = tillAs($cashier)->call('add', $this->rice)->assertDontSee('Discount on the whole sale');
    $key = array_key_first($till->get('lines'));
    $till->set("lines.{$key}.unitPrice", '5.00')->set('tenderAmount', '10')->call('complete')
        ->assertHasErrors(['form'])->assertSee('Only a supervisor can change a price.');
    expect(DB::table('sales')->count())->toBe(0);
});

it('refuses a discount bigger than the sale before it is sent', function () {
    tillAs($this->ownerId)->call('add', $this->rice)->set('invoiceDiscount', '100')
        ->assertSee('The discount is more than the sale is worth. Lower it to 10.00 or less');
});

it('records a backdated sale only for somebody who may backdate', function () {
    $yesterday = BusinessDate::addDays(Clock::today(), -1);
    tillAs($this->ownerId)->call('add', $this->rice)->set('pickedDate', $yesterday)->assertSee('not today.')
        ->set('tenderAmount', '10')->call('complete')->assertHasNoErrors();
    expect(DB::table('sales')->value('business_date'))->toBe($yesterday);

    $cashier = staff(['sales' => ['canView' => true, 'canCreate' => true, 'canEdit' => false, 'canVoid' => false]], 'yaw');
    tillAs($cashier)->assertDontSee('Sale date')->call('add', $this->rice)->set('pickedDate', $yesterday)
        ->set('tenderAmount', '10')->call('complete')->assertHasNoErrors();
    // Without the right the day is the server's, whatever the form held.
    expect(DB::table('sales')->orderByDesc('id')->value('business_date'))->toBe(Clock::today());
});

it('scans the value in the box when Enter beats the debounced search', function () {
    // The scanner typed the code and pressed Enter before the 150 ms search update was sent.
    tillAs($this->ownerId)->set('search', '600')->call('scan', '6001234500001')
        ->assertSet('search', '')->assertSee('Cart (1)')->assertSee('GHS 10.00');
});

it('loads the customer list once, not on every search keystroke, and again after a sale', function () {
    Shop::customer($this->actor, 'Adwoa Mensah');
    $till = tillAs($this->ownerId)->assertSee('Adwoa Mensah');

    $queries = 0;
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'from `customers`')) {
            $queries++;
        }
    });
    $till->set('search', 'r')->set('search', 'ri')->set('search', 'ric')->assertSee('Adwoa Mensah');
    expect($queries)->toBe(0);

    Shop::customer($this->actor, 'Kofi Boateng');
    $till->call('add', $this->rice)->set('tenderAmount', '10')->call('complete')->assertHasNoErrors()->assertSee('Kofi Boateng');
    expect($queries)->toBeGreaterThan(0);
});
