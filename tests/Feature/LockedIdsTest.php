<?php

declare(strict_types=1);

/*
| A page's record id is set once, in mount(), from the URL the route already
| checked. Livewire must refuse a browser that sends a different one, or a
| save on /suppliers/1/edit could land on supplier 2.
*/

use App\Livewire;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire as LivewireTest;

it('locks every record id and slug a page takes from its URL', function (string $class, string $property) {
    $attributes = (new ReflectionProperty($class, $property))->getAttributes(Locked::class);
    expect($attributes)->not->toBeEmpty("{$class}::\${$property} is not #[Locked]");
})->with([
    [Livewire\Products\Form::class, 'productId'],
    [Livewire\Products\Replace::class, 'productId'],
    [Livewire\Products\Replace::class, 'expectedQty'],
    [Livewire\Suppliers\Form::class, 'supplierId'],
    [Livewire\Suppliers\Show::class, 'supplierId'],
    [Livewire\Purchases\Show::class, 'purchaseId'],
    [Livewire\Accounts\Show::class, 'accountId'],
    [Livewire\Inventory\Batch::class, 'batchId'],
    [Livewire\Inventory\Adjustment::class, 'adjustmentId'],
    [Livewire\Accounting\Entry::class, 'entryId'],
    [Livewire\Accounting\Ledger::class, 'accountId'],
    [Livewire\Help\Show::class, 'slug'],
    [Livewire\Customers\Form::class, 'customerId'],
    [Livewire\Customers\Show::class, 'customerId'],
    [Livewire\Quotations\Form::class, 'quotationId'],
    [Livewire\Quotations\Show::class, 'quotationId'],
    [Livewire\Sales\Show::class, 'saleId'],
    [Livewire\Users\Show::class, 'userId'],
]);

it('refuses a locked id sent from the browser', function () {
    LivewireTest::actingAs(User::query()->findOrFail(owner()))
        ->test(Livewire\Help\Show::class, ['slug' => 'getting-started'])
        ->set('slug', 'backups');
})->throws(CannotUpdateLockedPropertyException::class);
