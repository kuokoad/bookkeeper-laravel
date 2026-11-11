<?php

declare(strict_types=1);

/*
| D34 on screen: the owner edits a saved document from its own page; nobody
| else sees the button or gets the form. The figures are DocumentEditTest's.
*/

use App\Auth\Permissions;
use App\Enums\AdjustmentReason;
use App\Livewire\Cashbook\Expenses;
use App\Livewire\Inventory\NewAdjustment;
use App\Livewire\Purchases\Create;
use App\Livewire\Sales\Pos;
use App\Models\User;
use App\Services\Actor;
use App\Services\CashbookService;
use App\Services\CatalogService;
use App\Services\StockAdjustmentService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Shop;

beforeEach(function () {
    Shop::setNoTax();
    $this->ownerId = owner();
    $this->owner = User::query()->findOrFail($this->ownerId);
    $this->actor = new Actor($this->ownerId, 'kwame');
    $this->cash = Shop::paymentAccount('CASH');
    $this->product = Shop::product($this->actor, 'Milo 400g', 500, 800);
    Shop::openingStock($this->actor, $this->product, 10_000, 5_000, '2026-08-16');
});

it('lets the owner edit a stock adjustment from its page, and nobody else', function () {
    $damage = StockAdjustmentService::createStockAdjustment(['businessDate' => '2026-08-16', 'reason' => AdjustmentReason::Damaged, 'items' => [['productId' => $this->product, 'direction' => 'OUT', 'qty' => 4_000]]], $this->actor);
    $id = $damage['adjustmentId'];

    signedIn($this->ownerId)->get("/inventory/adjustments/{$id}")->assertOk()->assertSee("/inventory/adjustments/{$id}/edit", false);
    $staff = staff(['inventory' => Permissions::FULL_ACCESS]);
    signedIn($staff)->get("/inventory/adjustments/{$id}")->assertOk()->assertDontSee("/inventory/adjustments/{$id}/edit", false);
    signedIn($staff)->get("/inventory/adjustments/{$id}/edit")->assertForbidden();

    Livewire::actingAs($this->owner)->test(NewAdjustment::class, ['id' => (string) $id])
        ->assertSet('rows.0.qty', '4')
        ->set('rows.0.qty', '2')
        ->call('save')->assertHasErrors('editReason')
        ->set('editReason', 'Only two were dented')
        ->call('save')->assertRedirect("/inventory/adjustments/{$id}");

    expect((int) DB::table('stock_adjustment_items')->where('adjustment_id', $id)->value('qty_milli'))->toBe(2_000);
    signedIn($this->ownerId)->get("/inventory/adjustments/{$id}")->assertSee('Edited')->assertSee('Only two were dented');
});

it('lets the owner edit an expense in its row', function () {
    $expense = CashbookService::recordExpense(['businessDate' => '2026-08-16', 'categoryAccountId' => Shop::expenseCategory('Transport'), 'description' => 'Taxi', 'amount' => 5_000, 'paymentAccountId' => $this->cash], $this->actor);

    Livewire::withQueryParams(['period' => 'all'])->actingAs($this->owner)->test(Expenses::class)
        ->call('startEdit', $expense['id'])
        ->assertSee('Why are you editing it?')
        ->assertSet('edit.amount', '50.00')
        ->set('edit.amount', '15')
        ->set('edit.reason', 'Typed 50 instead of 15')
        ->call('saveEdit')->assertHasNoErrors();

    expect((int) DB::table('expenses')->where('id', $expense['id'])->value('amount_minor'))->toBe(1_500);
});

it('refuses the expense edit to staff', function () {
    $expense = CashbookService::recordExpense(['businessDate' => '2026-08-16', 'categoryAccountId' => Shop::expenseCategory('Transport'), 'description' => 'Taxi', 'amount' => 5_000, 'paymentAccountId' => $this->cash], $this->actor);
    $staff = User::query()->findOrFail(staff(['expenses' => Permissions::FULL_ACCESS]));

    Livewire::withQueryParams(['period' => 'all'])->actingAs($staff)->test(Expenses::class)
        ->assertDontSee('wire:click="startEdit', false)
        ->call('startEdit', $expense['id'])->assertForbidden();
});

it('lets the owner edit a purchase in the purchase form', function () {
    $supplier = Shop::supplier($this->actor);
    $purchase = Shop::purchase($this->actor, $supplier, [[$this->product, 20_000, 600]], [], ['businessDate' => '2026-08-16']);
    $id = $purchase['purchaseId'];

    signedIn($this->ownerId)->get("/purchases/{$id}")->assertOk()->assertSee("/purchases/{$id}/edit", false);
    signedIn(staff(['purchases' => Permissions::FULL_ACCESS]))->get("/purchases/{$id}/edit")->assertForbidden();

    Livewire::actingAs($this->owner)->test(Create::class, ['id' => (string) $id])
        ->assertSet('editingNo', $purchase['purchaseNo'])
        ->set('rows.0.unitCost', '5.50')
        ->set('editReason', 'Invoice said 5.50')
        ->call('save')->assertRedirect("/purchases/{$id}");

    expect((int) DB::table('purchases')->where('id', $id)->value('total_minor'))->toBe(11_000);
});

it('lets the owner edit a sale on the till', function () {
    $sale = Shop::sell($this->actor, [[$this->product, 3_000]], [['paymentAccountId' => $this->cash, 'amount' => 2_400]]);
    $id = $sale['saleId'];

    signedIn($this->ownerId)->get("/sales/{$id}")->assertOk()->assertSee("/sales/{$id}/edit", false);
    signedIn(staff(['sales' => Permissions::FULL_ACCESS]))->get("/sales/{$id}/edit")->assertForbidden();

    Livewire::actingAs($this->owner)->test(Pos::class, ['id' => (string) $id])
        ->assertSet('editingNo', $sale['receiptNo'])
        ->assertSet('lines.1.qty', '3')
        ->set('lines.1.qty', '2')
        ->set('tenderAmount', '16')
        ->set('editReason', 'Rang up 3, sold 2')
        ->call('complete')->assertHasNoErrors()->assertRedirect("/sales/{$id}");

    expect((int) DB::table('sales')->where('id', $id)->value('total_minor'))->toBe(1_600)
        ->and(DB::table('sales')->count())->toBe(1);
});

it('keeps an archived product on a sale being edited, and refuses to save it', function () {
    $sale = Shop::sell($this->actor, [[$this->product, 10_000]], [['paymentAccountId' => $this->cash, 'amount' => 8_000]]);
    $id = $sale['saleId'];
    CatalogService::setProductActive($this->product, false, $this->actor);

    Livewire::actingAs($this->owner)->test(Pos::class, ['id' => (string) $id])
        ->assertSet('lines.1.qty', '10')
        ->assertHasErrors('form')
        ->set('editReason', 'Fix the note only')
        ->call('complete')->assertHasErrors()->assertNoRedirect();

    expect((int) DB::table('sales')->where('id', $id)->value('total_minor'))->toBe(8_000);
});

it('keeps an archived customer in the list when editing their paid sale, so the save does not drop them', function () {
    $customer = Shop::customer($this->actor, 'Ama Archived');
    $sale = Shop::sell($this->actor, [[$this->product, 1_000]], [['paymentAccountId' => $this->cash, 'amount' => 800]], ['customerId' => $customer]);
    DB::table('customers')->where('id', $customer)->update(['is_active' => false]);

    Livewire::actingAs($this->owner)->test(Pos::class, ['id' => (string) $sale['saleId']])
        ->assertSet('customerId', (string) $customer)->assertSeeHtml('<option value="'.$customer.'">Ama Archived');
});
