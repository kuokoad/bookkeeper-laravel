<?php

declare(strict_types=1);

/*
| The quotation screens: the old (app)/quotations list, the quote editor (new
| and change), the quote page (convert, cancel) and the printed copy.
*/

use App\Domain\BusinessDate;
use App\Livewire\Quotations\Form;
use App\Livewire\Quotations\Index;
use App\Livewire\Quotations\Show;
use App\Models\User;
use App\Services\Actor;
use App\Services\QuotationService;
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
});

function quotesAs(int $userId, string $page, array $params = [], array $query = []): mixed
{
    return Livewire::withQueryParams($query)->actingAs(User::query()->findOrFail($userId))->test($page, $params);
}

/** An open quote for 3 bags of cement at 80.00, good for a month. */
function openQuote(object $test, array $extra = []): array
{
    $test->cement = Shop::product($test->actor, 'Cement 50kg', 6_000, 8_000);
    Shop::openingStock($test->actor, $test->cement, 100_000, 600_000, '2026-01-01');

    return QuotationService::createQuotation([
        'businessDate' => $test->today,
        'validUntil' => BusinessDate::addDays($test->today, 30),
        'customerName' => 'Adom Construction',
        'reference' => 'Adenta site',
        'lines' => [['productId' => $test->cement, 'qty' => 3_000, 'unitPrice' => 8_000]],
        ...$extra,
    ], $test->actor);
}

it('renders the list, a quote, its editor and print over the demo shop', function () {
    (new DemoSeeder)->seedDemo();
    $quote = QuotationService::listQuotations(['status' => 'OPEN'])[0];
    signedIn($this->ownerId)->get('/quotations?period=all')->assertOk()
        ->assertSee('Prices offered to customers. Nothing here has been sold.')->assertSee($quote['quoteNo']);
    signedIn($this->ownerId)->get("/quotations/{$quote['id']}")->assertOk()->assertSee('Quote '.$quote['quoteNo'])->assertSee('The customer accepted');
    signedIn($this->ownerId)->get("/quotations/{$quote['id']}/edit")->assertOk()->assertSee('Change quote '.$quote['quoteNo']);
    signedIn($this->ownerId)->get("/quotations/{$quote['id']}/print")->assertOk()->assertSee('This is a quotation, not a bill.');
    signedIn($this->ownerId)->get('/quotations/new')->assertOk()->assertSee('New quote');
});

it('refuses somebody without the right', function () {
    $this->get('/quotations')->assertRedirect('/login');
    $kofi = staff(['sales' => viewOnly()], 'kofi');
    foreach (['/quotations', '/quotations/new', '/quotations/1', '/quotations/1/edit', '/quotations/1/print'] as $path) {
        signedIn($kofi)->get($path)->assertRedirect('/no-access?area=quotations');
    }
    $viewer = staff(['quotations' => viewOnly()], 'yaw');
    signedIn($viewer)->get('/quotations')->assertOk()->assertDontSee('/quotations/new', false);
    quotesAs($viewer, Form::class)->call('save')->assertForbidden();
    signedIn($this->ownerId)->get('/quotations/999999')->assertNotFound();
});

it('shows the resolved filters and narrows the list', function () {
    $open = openQuote($this);
    QuotationService::cancelQuotation(QuotationService::createQuotation([
        'businessDate' => $this->today, 'validUntil' => $this->today, 'customerName' => 'Lost Lead',
        'lines' => [['productId' => $this->cement, 'qty' => 1_000]],
    ], $this->actor)['quotationId'], 'Went elsewhere', $this->actor);

    quotesAs($this->ownerId, Index::class, [], ['status' => 'OPEN', 'q' => 'adom'])
        ->assertSee($open['quoteNo'])->assertDontSee('Lost Lead')
        ->assertSeeHtml('value="adom"')->assertSeeHtml('aria-pressed="true"')
        ->assertSeeHtml('/export/list/quotations?q=adom&amp;status=OPEN')
        ->assertSee(Format::money(24_000));
    quotesAs($this->ownerId, Index::class, [], ['status' => 'CANCELLED'])->assertSee('Lost Lead')->assertSee('Cancelled');
    quotesAs($this->ownerId, Index::class, [], ['q' => 'nobody'])->assertSee('No quotes here');
});

it('writes a quote through the service and shows its validation messages', function () {
    $cement = Shop::product($this->actor, 'Cement 50kg', 6_000, 8_000);

    quotesAs($this->ownerId, Form::class)->call('save')
        ->assertHasErrors(['customerName', 'lines'])->assertSee('Say who the quote is for.')->assertSee('Add at least one item to the quote.');

    $form = quotesAs($this->ownerId, Form::class)->set('customerName', 'Mensah & Sons');
    $key = array_key_first($form->get('lines'));
    $form->set("lines.{$key}.productId", (string) $cement)->assertSet("lines.{$key}.unitPrice", '80.00')
        ->set("lines.{$key}.qty", '2')->assertSee('GHS 160.00')
        ->set('validUntil', BusinessDate::addDays($this->today, -1))->call('save')
        ->assertHasErrors(['form'])->assertSee('A quote cannot expire before the day it is issued.');

    $form->set('validUntil', BusinessDate::addDays($this->today, 14))->call('save')->assertHasNoErrors();
    $quote = DB::table('quotations')->first();
    expect((int) $quote->total_minor)->toBe(16_000)->and($quote->customer_name)->toBe('Mensah & Sons');
});

it('changes an open quote and sends a closed one back to its page', function () {
    $open = openQuote($this);
    $form = quotesAs($this->ownerId, Form::class, ['id' => $open['quotationId']])->assertSet('customerName', 'Adom Construction');
    $key = array_key_first($form->get('lines'));
    $form->assertSet("lines.{$key}.qty", '3')->set("lines.{$key}.qty", '5')->call('save')->assertHasNoErrors()
        ->assertRedirect("/quotations/{$open['quotationId']}");
    expect((int) DB::table('quotations')->where('id', $open['quotationId'])->value('total_minor'))->toBe(40_000)
        ->and(DB::table('quotations')->where('id', $open['quotationId'])->value('quote_no'))->toBe($open['quoteNo']);

    QuotationService::cancelQuotation($open['quotationId'], 'Went elsewhere', $this->actor);
    quotesAs($this->ownerId, Form::class, ['id' => $open['quotationId']])->assertRedirect("/quotations/{$open['quotationId']}");
});

it('turns a part-paid quote into a credit sale and adds the customer', function () {
    $open = openQuote($this);
    $cash = Shop::paymentAccount('CASH');
    quotesAs($this->ownerId, Show::class, ['id' => $open['quotationId']])
        ->assertSet('paying', '240.00')->set('accountId', (string) $cash)->set('paying', '100')
        ->assertSee('140.00 will be owed')->assertSee('Add Adom Construction to your customers')
        ->call('convert')->assertHasNoErrors();

    $sale = DB::table('sales')->first();
    expect((int) $sale->total_minor)->toBe(24_000)
        ->and(DB::table('customers')->where('id', $sale->customer_id)->value('name'))->toBe('Adom Construction')
        ->and(DB::table('quotations')->where('id', $open['quotationId'])->value('status'))->toBe('CONVERTED')
        ->and(Shop::productRow($this->cement)->qty_on_hand_milli)->toBe(97_000);
});

it('asks why an expired quote is still honoured', function () {
    $open = openQuote($this, ['businessDate' => '2026-01-02', 'validUntil' => '2026-01-10']);
    quotesAs($this->ownerId, Show::class, ['id' => $open['quotationId']])
        ->assertSee('This quote has run out')->assertSee('Why is this still being honoured?')
        ->call('convert')->assertHasErrors(['convert']);
    expect(DB::table('sales')->count())->toBe(0);
});

it('cancels a quote with a reason, and refuses without the rights', function () {
    $open = openQuote($this);
    quotesAs($this->ownerId, Show::class, ['id' => $open['quotationId']])->call('cancel')
        ->assertHasErrors(['reason'])->assertSee('Say why the quote is being cancelled.');
    $viewer = staff(['quotations' => viewOnly()], 'yaw');
    quotesAs($viewer, Show::class, ['id' => $open['quotationId']])->assertDontSee('The customer accepted')
        ->set('reason', 'x')->call('cancel')->assertForbidden();
    quotesAs($viewer, Show::class, ['id' => $open['quotationId']])->call('convert')->assertForbidden();

    quotesAs($this->ownerId, Show::class, ['id' => $open['quotationId']])->set('reason', 'Went elsewhere')->call('cancel')->assertHasNoErrors();
    expect(DB::table('quotations')->where('id', $open['quotationId'])->value('status'))->toBe('CANCELLED');
    signedIn($this->ownerId)->get("/quotations/{$open['quotationId']}/print")->assertOk()->assertSee('This quote was withdrawn.');
});
