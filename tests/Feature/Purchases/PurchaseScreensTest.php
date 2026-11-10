<?php

declare(strict_types=1);

use App\Domain\Errors\InsufficientStockError;
use App\Livewire\Purchases\Create;
use App\Livewire\Purchases\Index;
use App\Livewire\Purchases\Show;
use App\Models\User;
use App\Services\Actor;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\SupplierService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Shop;

beforeEach(function () {
    $this->ownerId = owner();
    $this->actor = new Actor($this->ownerId, 'kwame');
    $this->cash = Shop::paymentAccount('CASH');
    $this->supplierId = Shop::supplier($this->actor, 'Makola Depot');
    $this->other = Shop::supplier($this->actor, 'Kumasi Wholesale');
    $this->rice = Shop::product($this->actor, 'Rice 5kg', 5_000, 8_000);
    $this->oil = Shop::product($this->actor, 'Palm oil', 2_000, 3_000);
    // GHS 100.00 on credit from Makola, GHS 40.00 paid in cash to Kumasi.
    $this->credit = Shop::purchase($this->actor, $this->supplierId, [[$this->rice, 2_000, 5_000]]);
    $this->paid = Shop::purchase($this->actor, $this->other, [[$this->oil, 2_000, 2_000]], [['paymentAccountId' => $this->cash, 'amount' => 4_000]]);
});

it('lists purchases for somebody with the right and refuses everyone else', function () {
    $this->get('/purchases')->assertRedirect('/login');
    signedIn(staff(['products' => viewOnly()]))->get('/purchases')->assertRedirect('/no-access?area=purchases');
    signedIn(staff(['purchases' => viewOnly()], 'efua'))->get('/purchases?period=all')->assertOk()
        ->assertSee('What you bought, from whom, and what you still owe.')
        ->assertSee($this->credit['purchaseNo'])->assertSee('Makola Depot')->assertDontSee('/purchases/new', false);
});

it('narrows rows and totals together and shows the resolved filters', function () {
    $owner = User::query()->findOrFail($this->ownerId);
    Livewire::withQueryParams(['period' => 'all', 'paid' => 'outstanding'])->actingAs($owner)->test(Index::class)
        ->assertSee($this->credit['purchaseNo'])->assertDontSee($this->paid['purchaseNo'])
        ->assertViewHas('summary', fn (array $s): bool => $s['count'] === 1 && $s['total'] === 10_000 && $s['outstanding'] === 10_000)
        ->assertSeeHtml('<option value="all" selected>');
    Livewire::withQueryParams(['period' => 'all', 'supplier' => (string) $this->other, 'min' => '30'])->actingAs($owner)->test(Index::class)
        ->assertSee($this->paid['purchaseNo'])->assertDontSee($this->credit['purchaseNo'])
        ->assertSeeHtml('<option value="'.$this->other.'" selected>')->assertSeeHtml('value="30.00"')
        ->assertViewHas('summary', fn (array $s): bool => $s['total'] === 4_000 && $s['paid'] === 4_000);
    // The CSV gets the same query, without the page.
    Livewire::withQueryParams(['period' => 'all', 'paid' => 'outstanding', 'page' => '1'])->actingAs($owner)->test(Index::class)
        ->assertSeeHtml('href="/export/list/purchases?period=all&amp;paid=outstanding"');
});

it('records a purchase through the service, with the figures the screen showed', function () {
    signedIn(staff(['purchases' => viewOnly()]))->get('/purchases/new')->assertRedirect('/no-access?area=purchases');
    signedIn($this->ownerId)->get('/purchases/new')->assertOk()->assertSee('Record a delivery. Stock goes up at the price you actually paid.');

    $page = Livewire::actingAs(User::query()->findOrFail($this->ownerId))->test(Create::class)
        ->assertSee('Choose a supplier.')
        ->set('supplierId', (string) $this->supplierId)
        ->set('rows.0.productId', (string) $this->rice)
        // The catalog cost is filled in for the chosen product.
        ->assertSet('rows.0.unitCost', '50.00')
        ->set('rows.0.qty', '3')
        ->assertSee('Save purchase — GHS 150.00')
        ->set('payAmount', '200')
        ->assertSee('You cannot pay more than the purchase total.')
        ->set('payAmount', '50')
        ->assertSee('Still owed to supplier')
        ->call('save')->assertHasNoErrors();
    $saved = $page->get('savedNo');
    expect($saved)->not->toBeNull();
    $page->assertSee("Purchase saved — {$saved}")->assertSet('rows.0.productId', '');

    $row = collect(PurchaseService::listPurchases(['search' => $saved]))->first();
    expect($row['totalMinor'])->toBe(15_000)->and($row['outstandingMinor'])->toBe(10_000)
        ->and(SupplierService::getSupplierBalance($this->supplierId))->toBe(20_000);
});

it('shows the line messages the server would give, and refuses Save without a right', function () {
    Livewire::actingAs(User::query()->findOrFail($this->ownerId))->test(Create::class)
        ->set('rows.0.productId', (string) $this->rice)->set('rows.0.qty', '1.5')
        ->assertSee('is counted in whole numbers')
        ->set('rows.0.qty', '1')->set('rows.0.unitCost', '')
        ->assertSee('Enter what each one cost.')
        ->set('supplierId', (string) $this->supplierId)->call('save')->assertHasErrors('form');
    Livewire::actingAs(User::query()->findOrFail(staff(['purchases' => viewOnly()])))->test(Create::class)->call('save')->assertForbidden();
});

it('shows a purchase with its entry, and voids it with a reason', function () {
    $id = $this->credit['purchaseId'];
    signedIn(staff(['purchases' => viewOnly()]))->get("/purchases/{$id}")->assertOk()
        ->assertSee('Accounting entry')->assertSee('Debits equal credits — this entry balances.')->assertDontSee('Void this purchase');
    $this->get('/purchases/99999')->assertNotFound();

    $page = Livewire::actingAs(User::query()->findOrFail($this->ownerId))->test(Show::class, ['id' => (string) $id])->assertSee('Void this purchase');
    $page->set('reason', 'no')->call('void')->assertHasErrors(['reason' => 'Give a reason for voiding this purchase.']);
    $page->set('reason', 'Delivered to the wrong shop')->call('void')->assertHasNoErrors()->assertRedirect("/purchases/{$id}");
    expect(PurchaseService::getPurchase($id)['status'])->toBe('VOIDED')
        ->and(SupplierService::getSupplierBalance($this->supplierId))->toBe(0);
});

it('corrects a purchase: a prefilled copy, and the original voided only when the copy is saved', function () {
    $owner = User::query()->findOrFail($this->ownerId);
    $id = $this->paid['purchaseId'];
    Livewire::actingAs($owner)->test(Show::class, ['id' => (string) $id])->assertSeeHtml('href="/purchases/new?from='.$id.'"');

    // The same supplier, date, line and cash payment, ready to fix; nothing voided yet.
    $form = Livewire::withQueryParams(['from' => (string) $id])->actingAs($owner)->test(Create::class)
        ->assertSet('correctingId', $id)->assertSeeText('Correcting '.$this->paid['purchaseNo'])
        ->assertSet('supplierId', (string) $this->other)->assertSet('businessDate', '2026-08-17')
        ->assertSet('rows.0.productId', (string) $this->oil)->assertSet('rows.0.qty', '2')->assertSet('rows.0.unitCost', '20.00')
        ->assertSet('payAccountId', (string) $this->cash)->assertSet('payAmount', '40.00');
    expect(PurchaseService::getPurchase($id)['status'])->toBe('POSTED');

    // A reason is required, and without one nothing is saved or voided.
    $before = DB::table('purchases')->count();
    $form->set('rows.0.productId', (string) $this->rice)->assertSet('rows.0.unitCost', '20.00')
        ->call('save')->assertHasErrors(['correctReason']);
    expect(DB::table('purchases')->count())->toBe($before)->and(PurchaseService::getPurchase($id)['status'])->toBe('POSTED');

    $form->set('correctReason', 'Wrong stock item chosen')->call('save')->assertHasNoErrors()
        ->assertSee($this->paid['purchaseNo'].' was voided in its place.')->assertSet('correctingId', null);
    $fixed = PurchaseService::getPurchase((int) $form->get('savedId'));
    expect(PurchaseService::getPurchase($id)['status'])->toBe('VOIDED')
        ->and($fixed['items'][0]['productId'])->toBe($this->rice)
        ->and($fixed['totalMinor'])->toBe(4_000)->and($fixed['paidMinor'])->toBe(4_000)
        ->and(SupplierService::getSupplierBalance($this->other))->toBe(0);
    Shop::assertBooksHealthy('after a correction');
});

it('corrects a purchase whose stock has partly sold, checking stock once after the corrected lines', function () {
    $owner = User::query()->findOrFail($this->ownerId);
    $perla = Shop::product($this->actor, 'Perla 500ml', 1_000, 1_500);
    $bought = Shop::purchase($this->actor, $this->supplierId, [[$perla, 150_000, 1_000]]);
    Shop::sell($this->actor, [[$perla, 2_000]], [['paymentAccountId' => $this->cash, 'amount' => 3_000]], ['businessDate' => '2026-08-18']);
    // A plain void cannot take 150 back out when only 148 are left.
    expect(fn () => PurchaseService::voidPurchase($bought['purchaseId'], 'Duplicate item', $this->actor))->toThrow(InsufficientStockError::class);

    // Swapped to another product: Perla would go below zero, so nothing is saved and nothing voided.
    $count = DB::table('purchases')->count();
    Livewire::withQueryParams(['from' => (string) $bought['purchaseId']])->actingAs($owner)->test(Create::class)
        ->set('correctReason', 'Duplicate item')->set('rows.0.productId', (string) $this->rice)
        ->call('save')->assertHasErrors(['form']);
    expect(DB::table('purchases')->count())->toBe($count)
        ->and(PurchaseService::getPurchase($bought['purchaseId'])['status'])->toBe('POSTED')
        ->and(InventoryService::getStockState($perla)->qty)->toBe(148_000);

    // Corrected to 140: 140 in, then 150 out, leaves 138.
    $form = Livewire::withQueryParams(['from' => (string) $bought['purchaseId']])->actingAs($owner)->test(Create::class)
        ->set('correctReason', 'Duplicate item')->set('rows.0.qty', '140')
        ->call('save')->assertHasNoErrors();
    expect(PurchaseService::getPurchase($bought['purchaseId'])['status'])->toBe('VOIDED')
        ->and(PurchaseService::getPurchase((int) $form->get('savedId'))['totalMinor'])->toBe(140_000)
        ->and(InventoryService::getStockState($perla)->qty)->toBe(138_000)
        ->and(SupplierService::getSupplierBalance($this->supplierId))->toBe(10_000 + 140_000);
    Shop::assertBooksHealthy('after correcting a partly sold delivery');
});

it('needs the right to void before a correction replaces a purchase', function () {
    $buyer = User::query()->findOrFail(staff(['purchases' => ['canView' => true, 'canCreate' => true, 'canEdit' => false, 'canVoid' => false]], 'efua'));
    Livewire::withQueryParams(['from' => (string) $this->credit['purchaseId']])->actingAs($buyer)->test(Create::class)
        ->set('correctReason', 'Wrong item')->call('save')->assertForbidden();
    expect(PurchaseService::getPurchase($this->credit['purchaseId'])['status'])->toBe('POSTED');
});

it('returns goods to the supplier, reducing what is owed', function () {
    $id = $this->credit['purchaseId'];
    $page = Livewire::actingAs(User::query()->findOrFail($this->ownerId))->test(Show::class, ['id' => (string) $id])
        ->assertSee('Return goods to this supplier');
    $page->call('recordReturn')->assertHasErrors(['returnForm' => 'Enter a quantity for at least one item.']);
    $item = PurchaseService::getPurchase($id)['items'][0]['id'];
    $page->set("returnQty.{$item}", '1')->assertSee('Value of goods being returned: GHS 50.00')
        ->call('recordReturn')->assertHasNoErrors();
    expect(SupplierService::getSupplierBalance($this->supplierId))->toBe(5_000);
});

it('refuses the void and return actions without their rights', function () {
    $viewer = User::query()->findOrFail(staff(['purchases' => viewOnly()]));
    Livewire::actingAs($viewer)->test(Show::class, ['id' => (string) $this->credit['purchaseId']])->set('reason', 'Not mine')->call('void')->assertForbidden();
    Livewire::actingAs($viewer)->test(Show::class, ['id' => (string) $this->credit['purchaseId']])->call('recordReturn')->assertForbidden();
});
