<?php

declare(strict_types=1);

use App\Livewire\Suppliers\Form;
use App\Livewire\Suppliers\Index;
use App\Livewire\Suppliers\Show;
use App\Models\User;
use App\Services\Actor;
use App\Services\SupplierPaymentService;
use App\Services\SupplierService;
use App\Support\Clock;
use Livewire\Livewire;
use Tests\Support\Shop;

beforeEach(function () {
    $this->ownerId = owner();
    $this->actor = new Actor($this->ownerId, 'kwame');
    $this->cash = Shop::paymentAccount('CASH');
    $this->supplierId = Shop::supplier($this->actor, 'Makola Depot', ['phone' => '0244000000']);
    $this->other = Shop::supplier($this->actor, 'Kumasi Wholesale');
    $product = Shop::product($this->actor, 'Rice 5kg', 5_000, 8_000);
    // GHS 100.00 on credit from Makola Depot.
    Shop::purchase($this->actor, $this->supplierId, [[$product, 2_000, 5_000]]);
});

it('lists suppliers for somebody with the right and refuses everyone else', function () {
    $this->get('/suppliers')->assertRedirect('/login');
    signedIn(staff(['products' => viewOnly()]))->get('/suppliers')->assertRedirect('/no-access?area=suppliers');
    signedIn(staff(['suppliers' => viewOnly()], 'efua'))->get('/suppliers')->assertOk()
        ->assertSee('Who you buy from, and what you owe them.')->assertSee('Makola Depot')->assertSee('GHS 100.00')
        ->assertDontSee('Add supplier');
});

it('filters by balance and shows the resolved filter values', function () {
    Livewire::withQueryParams(['balance' => 'owing', 'q' => 'depot'])->actingAs(User::query()->findOrFail($this->ownerId))->test(Index::class)
        ->assertSee('Makola Depot')->assertDontSee('Kumasi Wholesale')
        ->assertSeeHtml('<option value="owing" selected>')->assertSeeHtml('value="depot"');
    // A junk value resolves to "any balance", and the control says so.
    Livewire::withQueryParams(['balance' => 'junk'])->actingAs(User::query()->findOrFail($this->ownerId))->test(Index::class)
        ->assertSee('Kumasi Wholesale')->assertDontSeeHtml('<option value="owing" selected>');
});

it('passes the same filters to the CSV button', function () {
    Livewire::withQueryParams(['balance' => 'owing', 'page' => '1'])->actingAs(User::query()->findOrFail($this->ownerId))->test(Index::class)
        ->assertSeeHtml('href="/export/list/suppliers?balance=owing"');
});

it('adds a supplier through the service and shows its validation message', function () {
    signedIn(staff(['suppliers' => viewOnly()]))->get('/suppliers/new')->assertRedirect('/no-access?area=suppliers');
    signedIn($this->ownerId)->get('/suppliers/new')->assertOk()->assertSee('Someone you buy stock from.');

    Livewire::actingAs(User::query()->findOrFail($this->ownerId))->test(Form::class)
        ->set('name', '  ')->call('save')->assertHasErrors(['name' => 'Enter the supplier’s name.']);
    Livewire::actingAs(User::query()->findOrFail($this->ownerId))->test(Form::class)
        ->set('name', 'Tema Traders')->set('phone', '0201234567')->call('save')
        ->assertHasNoErrors()->assertRedirect();
    expect(collect(SupplierService::listSuppliers(['search' => 'Tema']))->pluck('phone')->all())->toBe(['0201234567']);
});

it('refuses the save action without the right', function () {
    Livewire::actingAs(User::query()->findOrFail(staff(['suppliers' => viewOnly()])))->test(Form::class)
        ->set('name', 'Sneaky')->call('save')->assertForbidden();
    Livewire::actingAs(User::query()->findOrFail(staff(['suppliers' => ['canView' => true, 'canCreate' => true, 'canEdit' => false, 'canVoid' => false]], 'yaw')))
        ->test(Form::class, ['id' => (string) $this->supplierId])->call('save')->assertForbidden();
});

it('edits a supplier', function () {
    signedIn($this->ownerId)->get("/suppliers/{$this->supplierId}/edit")->assertOk()->assertSee('Edit supplier');
    $this->get('/suppliers/99999/edit')->assertNotFound();
    Livewire::actingAs(User::query()->findOrFail($this->ownerId))->test(Form::class, ['id' => (string) $this->supplierId])
        ->assertSet('name', 'Makola Depot')->set('name', 'Makola Depot Ltd')->call('save')->assertHasNoErrors();
    expect(SupplierService::getSupplier($this->supplierId)['name'])->toBe('Makola Depot Ltd');
});

it('shows a supplier and pays what is owed', function () {
    signedIn(staff(['suppliers' => viewOnly()]))->get("/suppliers/{$this->supplierId}")->assertOk()
        ->assertSee('You currently owe')->assertSee('GHS 100.00')->assertDontSee('Pay this supplier');

    $page = Livewire::actingAs(User::query()->findOrFail($this->ownerId))->test(Show::class, ['id' => (string) $this->supplierId])->assertSee('Pay this supplier');
    $page->set('amount', '')->call('pay')->assertHasErrors(['amount' => 'Enter an amount.']);
    $page->set('amount', 'abc')->call('pay')->assertHasErrors('amount');
    $page->set('amount', '500')->call('pay')->assertHasErrors('form');
    $page->set('amount', '40.00')->call('pay')->assertHasNoErrors()->assertRedirect("/suppliers/{$this->supplierId}");

    expect(SupplierService::getSupplierBalance($this->supplierId))->toBe(6_000);
});

it('refuses paying and voiding without the right', function () {
    $viewer = User::query()->findOrFail(staff(['suppliers' => viewOnly()]));
    Livewire::actingAs($viewer)->test(Show::class, ['id' => (string) $this->supplierId])->set('amount', '10')->call('pay')->assertForbidden();
    Livewire::actingAs($viewer)->test(Show::class, ['id' => (string) $this->supplierId])->call('voidPayment', 1)->assertForbidden();
});

it('voids a payment with a reason and restores the debt', function () {
    $payment = SupplierPaymentService::recordSupplierPayment(['supplierId' => $this->supplierId, 'businessDate' => Clock::today(), 'paymentAccountId' => $this->cash, 'amount' => 3_000], $this->actor);
    $page = Livewire::actingAs(User::query()->findOrFail($this->ownerId))->test(Show::class, ['id' => (string) $this->supplierId]);
    $page->call('voidPayment', $payment['paymentId'])->assertHasErrors(["void.{$payment['paymentId']}" => 'Give a reason for voiding this payment.']);
    $page->set("voidReasons.{$payment['paymentId']}", 'Paid the wrong supplier')->call('voidPayment', $payment['paymentId'])->assertHasNoErrors();

    expect(SupplierService::getSupplierBalance($this->supplierId))->toBe(10_000);
});

it('checks payment ownership with one query, so a payment of any age can be voided but not one of another supplier', function () {
    $payment = SupplierPaymentService::recordSupplierPayment(['supplierId' => $this->supplierId, 'businessDate' => Clock::today(), 'paymentAccountId' => $this->cash, 'amount' => 3_000], $this->actor);
    expect(SupplierPaymentService::paymentBelongsToSupplier($payment['paymentId'], $this->supplierId))->toBeTrue()
        ->and(SupplierPaymentService::paymentBelongsToSupplier($payment['paymentId'], $this->other))->toBeFalse();

    Livewire::actingAs(User::query()->findOrFail($this->ownerId))->test(Show::class, ['id' => (string) $this->other])
        ->set("voidReasons.{$payment['paymentId']}", 'Paid the wrong supplier')->call('voidPayment', $payment['paymentId'])
        ->assertHasErrors(["void.{$payment['paymentId']}" => 'That payment could not be found.']);
    expect(SupplierService::getSupplierBalance($this->supplierId))->toBe(7_000);
});
