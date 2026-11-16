<?php

declare(strict_types=1);

use App\Livewire\Inventory\Adjustment;
use App\Livewire\Inventory\Batch;
use App\Livewire\Inventory\Index;
use App\Livewire\Inventory\NewAdjustment;
use App\Models\User;
use App\Services\Actor;
use App\Services\StockAdjustmentService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Shop;

beforeEach(function () {
    $this->ownerId = owner();
    $this->actor = new Actor($this->ownerId, 'kwame');
    $this->rice = Shop::product($this->actor, 'Rice 5kg', 5_000, 8_000);
    $this->oil = Shop::product($this->actor, 'Cooking oil', 2_000, 3_000);
    // 10 bags of rice in at GHS 500.00.
    $this->opening = Shop::openingStock($this->actor, $this->rice, 10_000, 50_000);
    Shop::openingStock($this->actor, $this->oil, 4_000, 8_000);
});

it('shows the stock ledger with the right and refuses everyone else', function () {
    $this->get('/inventory')->assertRedirect('/login');
    signedIn(staff(['products' => viewOnly()]))->get('/inventory')->assertRedirect('/no-access?area=inventory');
    signedIn(staff(['inventory' => viewOnly()], 'efua'))->get('/inventory')->assertOk()
        ->assertSee('Every movement of stock, and why it moved.')->assertSee('Rice 5kg')->assertSee('GHS 580.00')
        ->assertDontSee('New adjustment');
});

it('narrows the ledger by product, shows the resolved filters, and carries them to the CSV', function () {
    Livewire::withQueryParams(['product' => (string) $this->rice, 'movement' => 'OPENING_STOCK'])
        ->actingAs(User::query()->findOrFail($this->ownerId))->test(Index::class)
        ->assertSee('— Rice 5kg')->assertSee('500.00')->assertSee('Showing 1–1 of 1 movement')
        ->assertSeeHtml('<option value="'.$this->rice.'" selected>')->assertSeeHtml('<option value="OPENING_STOCK" selected>')
        ->assertSeeHtml('href="/export/list/stock-movements?product='.$this->rice.'&amp;movement=OPENING_STOCK"');
    // Junk resolves to "all movements", and the control says so.
    Livewire::withQueryParams(['movement' => 'junk'])->actingAs(User::query()->findOrFail($this->ownerId))->test(Index::class)
        ->assertSee('Cooking oil')->assertDontSeeHtml('<option value="OPENING_STOCK" selected>');
});

it('lists adjustments and shows one with its balanced journal entry', function () {
    signedIn(staff(['inventory' => viewOnly()]))->get('/inventory/adjustments')->assertOk()
        ->assertSee('Damage, loss, opening stock and count corrections.')->assertSee($this->opening['adjustmentNo'])->assertSee('Opening stock');
    $this->get("/inventory/adjustments/{$this->opening['adjustmentId']}")->assertOk()
        ->assertSee('Accounting entry')->assertSee('Debits equal credits — this entry balances.')->assertSee('500.00')
        ->assertDontSee('Void this adjustment');
    $this->get('/inventory/adjustments/99999')->assertNotFound();
});

it('voids an adjustment with a reason, and refuses without the right', function () {
    $id = $this->opening['adjustmentId'];
    Livewire::actingAs(User::query()->findOrFail(staff(['inventory' => viewOnly()])))->test(Adjustment::class, ['id' => (string) $id])
        ->set('reason', 'Wrong product')->call('void')->assertForbidden();

    $page = Livewire::actingAs(User::query()->findOrFail($this->ownerId))->test(Adjustment::class, ['id' => (string) $id]);
    $page->set('reason', 'no')->call('void')->assertHasErrors(['reason' => 'Give a reason for voiding this adjustment.']);
    $page->set('reason', 'Entered against the wrong product')->call('void')->assertHasNoErrors()->assertRedirect('/inventory/adjustments');

    expect(StockAdjustmentService::getStockAdjustment($id)['adjustment']->status)->toBe('VOIDED')
        ->and((int) Shop::productRow($this->rice)->qty_on_hand_milli)->toBe(0)
        ->and((int) Shop::productRow($this->rice)->stock_value_minor)->toBe(0);
});

it('records stock in from a unit cost, posting unit times quantity', function () {
    signedIn(staff(['inventory' => viewOnly()]))->get('/inventory/adjustments/new')->assertRedirect('/no-access?area=inventory');
    signedIn($this->ownerId)->get('/inventory/adjustments/new')->assertOk()->assertSee('Record stock that came in or went out');

    Livewire::actingAs(User::query()->findOrFail($this->ownerId))->test(NewAdjustment::class)
        ->set('reason', 'FOUND')
        ->set('rows.0.productId', (string) $this->oil)
        ->set('rows.0.qty', '3')
        ->call('setCost', 0, 'unit', '2.50')
        ->assertSeeHtml('value="7.50"')
        ->call('save')->assertHasNoErrors()->assertRedirect('/inventory/adjustments');

    // 4 at GHS 80.00 plus 3 at GHS 7.50.
    expect((int) Shop::productRow($this->oil)->qty_on_hand_milli)->toBe(7_000)
        ->and((int) Shop::productRow($this->oil)->stock_value_minor)->toBe(8_750);
});

it('takes stock out at the weighted average and shows the service messages', function () {
    $page = Livewire::actingAs(User::query()->findOrFail($this->ownerId))->test(NewAdjustment::class);
    $page->call('save')->assertHasErrors(['form' => 'Add at least one stock item to the adjustment.']);
    $page->set('reason', 'FOUND')->set('rows.0.productId', (string) $this->rice)->set('rows.0.qty', '1')
        ->call('save')->assertHasErrors('form')->assertSee('Line 1: enter the total value of the stock being added.');
    $page->set('reason', 'DAMAGED')->set('rows.0.qty', '50')->call('save')->assertHasErrors('form');
    $page->set('rows.0.qty', '2')->call('save')->assertHasNoErrors();

    // Two of ten bags out at the average: GHS 100.00 off GHS 500.00.
    expect((int) Shop::productRow($this->rice)->stock_value_minor)->toBe(40_000);
});

it('offers an archived stock item only while it still holds stock, so that stock can be taken off', function () {
    DB::table('products')->where('id', $this->rice)->update(['is_active' => false]);
    $empty = Shop::product($this->actor, 'Empty and archived', 1_000, 2_000);
    DB::table('products')->where('id', $empty)->update(['is_active' => false]);

    Livewire::actingAs(User::query()->findOrFail($this->ownerId))->test(NewAdjustment::class)
        ->assertSee('Rice 5kg (10 pcs on hand, archived)')->assertDontSee('Empty and archived')
        ->set('reason', 'OTHER')->set('rows.0.productId', (string) $this->rice)->set('rows.0.direction', 'OUT')->set('rows.0.qty', '10')
        ->call('save')->assertHasNoErrors();

    expect((int) Shop::productRow($this->rice)->qty_on_hand_milli)->toBe(0)
        ->and((int) Shop::productRow($this->rice)->stock_value_minor)->toBe(0);
});

it('refuses saving an adjustment without the right', function () {
    Livewire::actingAs(User::query()->findOrFail(staff(['inventory' => viewOnly()])))->test(NewAdjustment::class)
        ->set('rows.0.productId', (string) $this->rice)->set('rows.0.qty', '1')->call('save')->assertForbidden();
});

it('shows a batch and corrects its date with inventory edit', function () {
    $batchId = (int) DB::table('product_batches')->where('product_id', $this->rice)->value('id');
    signedIn(staff(['inventory' => viewOnly()]))->get("/inventory/batches/{$batchId}")->assertRedirect('/no-access?area=products');
    signedIn(staff(['products' => viewOnly()], 'efua'))->get("/inventory/batches/{$batchId}")->assertOk()
        ->assertSee('Everywhere it went')->assertSee('10 pcs')->assertDontSee('Set a date');
    $this->get('/inventory/batches/99999')->assertNotFound();

    Livewire::actingAs(User::query()->findOrFail(staff(['products' => viewOnly()], 'yaw')))->test(Batch::class, ['id' => (string) $batchId])
        ->set('expiryDate', '2027-01-31')->call('saveExpiry')->assertForbidden();

    $page = Livewire::actingAs(User::query()->findOrFail($this->ownerId))->test(Batch::class, ['id' => (string) $batchId]);
    $page->set('expiryDate', '2027-02-31')->call('saveExpiry')->assertHasErrors(['expiryDate' => 'Enter a valid date, or leave it blank for no date.']);
    $page->set('expiryDate', '2027-01-31')->call('saveExpiry')->assertHasNoErrors()->assertDispatched('toast');

    expect(DB::table('product_batches')->where('id', $batchId)->value('expiry_date'))->toBe('2027-01-31');
});
