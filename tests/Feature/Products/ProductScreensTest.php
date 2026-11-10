<?php

declare(strict_types=1);

use App\Livewire\Products\Categories;
use App\Livewire\Products\Create;
use App\Livewire\Products\Form;
use App\Livewire\Products\Import;
use App\Livewire\Products\Index;
use App\Livewire\Products\Replace;
use App\Livewire\Products\Units;
use App\Models\User;
use App\Services\Actor;
use App\Services\CatalogService;
use App\Services\UnitsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\Shop;

beforeEach(function () {
    $this->ownerId = owner();
    $this->owner = User::query()->findOrFail($this->ownerId);
    $this->actor = new Actor($this->ownerId, 'kwame');
    $this->drinks = CatalogService::createCategory(['name' => 'Drinks'], $this->actor);
    $this->rice = Shop::product($this->actor, 'Rice 5kg', 5_000, 8_000, ['sku' => 'RICE5']);
    // 10 bags at GHS 50.00 each: GHS 500.00 of stock.
    Shop::openingStock($this->actor, $this->rice, 10_000, 50_000);
    $this->malt = Shop::product($this->actor, 'Malt can', 600, 1_000, ['categoryId' => $this->drinks]);
});

it('lists products for somebody with the right and refuses everyone else', function () {
    $this->get('/products')->assertRedirect('/login');
    signedIn(staff(['sales' => viewOnly()]))->get('/products')->assertRedirect('/no-access?area=products');
    signedIn(staff(['products' => viewOnly()], 'efua'))->get('/products')->assertOk()
        ->assertSee('What you sell, what it costs you, and how much is on the shelf.')
        ->assertSee('Rice 5kg')->assertSee('GHS 500.00')->assertDontSee('Add product');
});

it('filters products, keeps the shop totals and shows the resolved filters', function () {
    Livewire::withQueryParams(['category' => (string) $this->drinks, 'q' => 'malt'])->actingAs($this->owner)->test(Index::class)
        ->assertSee('Malt can')->assertDontSee('Rice 5kg')
        ->assertSee('These 1 product(s) hold GHS 0.00 of stock')
        // The shop line does not shrink with the filter.
        ->assertSee('GHS 500.00')
        ->assertSeeHtml('<option value="'.$this->drinks.'" selected>')->assertSeeHtml('value="malt"')
        ->assertSeeHtml('href="/export/list/products?q=malt&amp;category='.$this->drinks.'"');
    Livewire::withQueryParams(['stock' => 'junk'])->actingAs($this->owner)->test(Index::class)
        ->assertSee('Malt can')->assertSee('Rice 5kg')->assertDontSee('These 2 product(s)');
});

it('adds a product through the service and shows its validation messages', function () {
    signedIn(staff(['products' => viewOnly()]))->get('/products/new')->assertRedirect('/no-access?area=products');
    signedIn($this->ownerId)->get('/products/new')->assertOk()->assertSee('Something new to sell.');

    Livewire::actingAs($this->owner)->test(Create::class)->assertSet('rows.0.unit', 'pcs')
        ->set('rows.0.name', 'Sugar')->set('rows.0.costPrice', 'abc')->call('save')
        ->assertHasErrors('rows.0.costPrice');
    Livewire::actingAs($this->owner)->test(Create::class)
        ->set('rows.0.name', 'Sugar')->set('rows.0.costPrice', '12.50')->set('rows.0.sellingPrice', '15')->call('save')
        ->assertHasNoErrors()->assertRedirect('/products');

    $sugar = CatalogService::listProducts(['search' => 'Sugar'])[0];
    expect([$sugar['costPrice'], $sugar['sellingPrice'], $sugar['qtyOnHand']])->toBe([1_250, 1_500, 0]);

    // The fields only Edit has keep their messages there.
    Livewire::actingAs($this->owner)->test(Form::class, ['id' => (string) $this->malt])
        ->set('name', '')->call('save')->assertHasErrors(['name' => 'Enter a stock item name.']);
    Livewire::actingAs($this->owner)->test(Form::class, ['id' => (string) $this->malt])
        ->set('costPrice', 'abc')->set('warnDays', '2.5')->call('save')
        ->assertHasErrors(['costPrice', 'warnDays' => 'Enter a whole number of days, or leave it blank.']);
    Livewire::actingAs($this->owner)->test(Form::class, ['id' => (string) $this->malt])
        ->set('sku', 'RICE5')->call('save')->assertHasErrors('form');
});

it('refuses the product writes without the right', function () {
    $viewer = User::query()->findOrFail(staff(['products' => viewOnly()]));
    Livewire::actingAs($viewer)->test(Create::class)->set('rows.0.name', 'Sneaky')->call('save')->assertForbidden();
    Livewire::actingAs($viewer)->test(Form::class, ['id' => (string) $this->malt])->set('name', 'Sneaky')->call('save')->assertForbidden();
    Livewire::actingAs($viewer)->test(Form::class, ['id' => (string) $this->malt])->call('setActive', false)->assertForbidden();
    Livewire::actingAs($viewer)->test(Form::class, ['id' => (string) $this->malt])->call('delete')->assertForbidden();
    Livewire::actingAs($viewer)->test(Categories::class)->set('name', 'X')->call('add')->assertForbidden();
    Livewire::actingAs($viewer)->test(Units::class)->call('saveUnit', 0)->assertForbidden();
    Livewire::actingAs($viewer)->test(Import::class)->call('read')->assertForbidden();
});

it('edits a product and keeps a locked unit', function () {
    signedIn($this->ownerId)->get("/products/{$this->rice}/edit")->assertOk()->assertSee('Fixed — this stock item already has stock movements.')
        ->assertSee('Replace with new units…');
    $this->get('/products/99999/edit')->assertNotFound();
    Livewire::actingAs($this->owner)->test(Form::class, ['id' => (string) $this->rice])
        ->assertSet('sellingPrice', '80.00')->set('sellingPrice', '85.00')->call('save')->assertHasNoErrors();
    expect(CatalogService::getProduct($this->rice)['sellingPrice'])->toBe(8_500);
});

it('archives a product with history and deletes one without', function () {
    // Rice holds stock, so it cannot be archived: the service's message is shown.
    Livewire::actingAs($this->owner)->test(Form::class, ['id' => (string) $this->rice])->call('setActive', false)->assertHasErrors('remove');
    Livewire::actingAs($this->owner)->test(Form::class, ['id' => (string) $this->malt])
        ->assertSee('so it can be deleted')->call('delete')->assertHasNoErrors()->assertRedirect('/products');
    expect(CatalogService::countProducts(['search' => 'Malt', 'includeInactive' => true]))->toBe(0);
});

it('replaces a product in new units at the same stock value', function () {
    signedIn(staff(['products' => ['canView' => true, 'canCreate' => true, 'canEdit' => true, 'canVoid' => false]]))
        ->get("/products/{$this->rice}/replace")->assertRedirect('/no-access?area=inventory');
    signedIn($this->ownerId)->get("/products/{$this->malt}/replace")->assertRedirect("/products/{$this->malt}/edit");
    UnitsService::createUnit(['symbol' => 'kg', 'name' => 'Kilograms', 'decimalPlaces' => 3], $this->actor);

    $page = Livewire::actingAs($this->owner)->test(Replace::class, ['id' => (string) $this->rice])->assertSee('The old stock item holds 10 pcs');
    $page->set('unit', 'kg')->set('newQty', 'lots')->call('save')->assertHasErrors('newQty');
    $page->set('newQty', '50')->call('save')->assertHasNoErrors()->assertRedirect('/products');

    $new = CatalogService::listProducts(['search' => 'Rice'])[0];
    expect([$new['unit'], $new['qtyOnHand'], $new['stockValue']])->toBe(['kg', 50_000, 50_000])
        ->and(CatalogService::getProduct($this->rice)['isActive'])->toBeFalse();
});

it('adds a category and shows the list', function () {
    signedIn(staff(['products' => viewOnly()]))->get('/products/categories')->assertOk()->assertSee('Drinks')->assertDontSee('Add category');
    Livewire::actingAs($this->owner)->test(Categories::class)
        ->call('add')->assertHasErrors(['name' => 'Enter a stock group name.'])
        ->set('name', 'Food')->call('add')->assertHasNoErrors()->assertSet('name', '')->assertSee('Food');
});

it('adds, renames and deletes units, showing the service messages', function () {
    signedIn(staff(['products' => viewOnly()]))->get('/products/units')->assertOk()->assertSee('pcs')->assertDontSee('Add unit');
    $page = Livewire::actingAs($this->owner)->test(Units::class);
    $page->call('saveUnit', 0)->assertHasErrors(['forms.0.symbol' => 'Enter a symbol, e.g. pcs.']);
    $page->set('forms.0.symbol', 'pcs')->set('forms.0.name', 'Again')->call('saveUnit', 0)->assertHasErrors('forms.0');
    $page->set('forms.0.symbol', 'ctn')->set('forms.0.name', 'Carton')->call('saveUnit', 0)->assertHasNoErrors();
    expect(UnitsService::getUnit('ctn')->name)->toBe('Carton');

    $index = collect($page->get('forms'))->search(fn (array $f): bool => $f['original'] === 'ctn');
    $page->set("forms.{$index}.name", 'Cartons')->call('saveUnit', $index)->assertHasNoErrors();
    expect(UnitsService::getUnit('ctn')->name)->toBe('Cartons');
    $index = collect($page->get('forms'))->search(fn (array $f): bool => $f['original'] === 'ctn');
    $page->call('deleteUnit', $index)->assertHasNoErrors();
    expect(collect(UnitsService::listUnits())->pluck('symbol')->all())->not->toContain('ctn');
});

it('imports products from a CSV after the preview, and only what was previewed', function () {
    $csv = "Product name,Cost price,Selling price,Opening quantity\nBeans,10,15,4\nMilk,2,3,\n,,,\n";
    $page = Livewire::actingAs($this->owner)->test(Import::class)
        ->set('file', UploadedFile::fake()->createWithContent('list.csv', $csv))->call('read')
        ->assertHasNoErrors()->assertSee('Columns in list.csv')->assertSee('ready to import')->assertSee('Import 2 products');

    // A column changed after the preview is refused, with the fresh preview shown.
    $page->set('map.openingQty', '')->call('import')->assertHasErrors(['form' => 'The columns changed since the preview. Check the preview below, then import.']);
    $page->set('map.openingQty', '3')->call('preview')->call('import')->assertHasNoErrors()->assertRedirect('/products');

    $beans = CatalogService::listProducts(['search' => 'Beans'])[0];
    expect([$beans['qtyOnHand'], $beans['stockValue']])->toBe([4_000, 4_000]);
});

it('keeps the read sheet on the server, sending only a key to the browser, and forgets it once imported', function () {
    $csv = "Product name,Cost price,Selling price\nZobo concentrate 1L,10,15\n";
    $page = Livewire::actingAs($this->owner)->test(Import::class)
        ->set('file', UploadedFile::fake()->createWithContent('list.csv', $csv))->call('read')->assertSee('Import 1 product');
    $key = $page->get('sheet');

    // The rows wait in the cache, not in every click's payload.
    expect(json_encode($page->snapshot))->not->toContain('Zobo concentrate')
        ->and(Cache::get($key)[1][0])->toBe('Zobo concentrate 1L');
    $page->call('import')->assertRedirect('/products');
    expect(Cache::has($key))->toBeFalse();
});

it('asks for the file again when the read sheet has gone from the cache', function () {
    $page = Livewire::actingAs($this->owner)->test(Import::class)
        ->set('file', UploadedFile::fake()->createWithContent('list.csv', "Product name\nBeans\n"))->call('read');
    Cache::forget($page->get('sheet'));
    $page->call('import')->assertHasErrors(['form' => 'The uploaded sheet was lost. Choose the file again.']);
    expect(CatalogService::countProducts(['search' => 'Beans']))->toBe(0);
});

it('refuses a bad upload and opening stock without the stock right', function () {
    Livewire::actingAs($this->owner)->test(Import::class)->call('read')->assertHasErrors(['form' => 'Choose a file to import.']);
    Livewire::actingAs($this->owner)->test(Import::class)
        ->set('file', UploadedFile::fake()->createWithContent('list.csv', "Product name\n"))->call('read')
        ->assertHasErrors(['form' => 'That sheet has no rows under the headings.']);

    $clerk = User::query()->findOrFail(staff(['products' => ['canView' => true, 'canCreate' => true, 'canEdit' => false, 'canVoid' => false]]));
    Livewire::actingAs($clerk)->test(Import::class)
        ->set('file', UploadedFile::fake()->createWithContent('list.csv', "Name,Stock\nBeans,4\n"))->call('read')->call('import')
        ->assertHasErrors(['form' => 'You can add stock items but not record stock. Set Opening quantity to "Not imported" to import the stock items alone.']);
    expect(CatalogService::countProducts(['search' => 'Beans']))->toBe(0);
});

it('downloads the import template for somebody who may import', function () {
    signedIn(staff(['products' => viewOnly()]))->get('/products/import/template')->assertRedirect('/no-access?area=products');
    $response = signedIn($this->ownerId)->get('/products/import/template')->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="bookkeeper_product-import-template.csv"');
    expect($response->getContent())->toBe("\u{FEFF}Stock item name,SKU,Barcode,Unit,Cost price,Selling price,Units per pack,Cost per pack,Selling price per pack,Reorder level,Stock group,Track stock,Opening quantity,Opening value\r\n");
    signedIn($this->ownerId)->get('/products/import')->assertOk()->assertSee('Download the template');
});

it('refuses to switch stock tracking on a product with history, and says why on the edit screen', function () {
    Livewire::actingAs($this->owner)->test(Form::class, ['id' => (string) $this->rice])
        ->set('trackInventory', false)->call('save')
        ->assertHasErrors('form')->assertSee('whether it tracks stock cannot change');
    expect(Shop::productRow($this->rice)->track_inventory)->toBeTruthy();
});

it('adds several stock items from the grid, all or none', function () {
    $before = count(CatalogService::listProducts([]));

    // A bad row stops every row, and says which one.
    Livewire::actingAs($this->owner)->test(Create::class)
        ->set('rows.0.name', 'Sugar 1kg')
        ->call('addRow')->set('rows.1.name', 'Salt')->set('rows.1.unit', 'no-such-unit')
        ->call('save')->assertHasErrors('form')->assertSee('Row 2:');
    expect(count(CatalogService::listProducts([])))->toBe($before);

    Livewire::actingAs($this->owner)->test(Create::class)
        ->set('rows.0.name', '')->set('rows.0.costPrice', '3')->call('save')
        ->assertHasErrors(['rows.0.name' => 'Enter a stock item name.']);

    // The second row inherits the first row's stock group; a spare empty row is ignored.
    Livewire::actingAs($this->owner)->test(Create::class)
        ->set('rows.0.categoryId', (string) $this->drinks)
        ->set('rows.0.name', 'Sugar 1kg')->set('rows.0.costPrice', '12.50')->set('rows.0.sellingPrice', '15')
        ->call('addRow')->set('rows.1.name', 'Delivery')->set('rows.1.trackInventory', false)
        ->call('addRow')
        ->call('save')->assertHasNoErrors()->assertRedirect('/products');

    $sugar = CatalogService::listProducts(['search' => 'Sugar 1kg'])[0];
    $delivery = CatalogService::listProducts(['search' => 'Delivery'])[0];
    expect([$sugar['costPrice'], $sugar['sellingPrice'], $sugar['qtyOnHand'], $sugar['categoryId']])->toBe([1_250, 1_500, 0, $this->drinks])
        ->and([$delivery['categoryId'], $delivery['trackInventory']])->toBe([$this->drinks, false])
        ->and(count(CatalogService::listProducts([])))->toBe($before + 2);

});
