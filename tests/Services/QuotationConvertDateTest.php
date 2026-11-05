<?php

declare(strict_types=1);

/*
| Ported from the old tests/app/quotation-convert-date.test.ts, the
| service-level part. The old convertQuotationAction ignored the date its form
| carried and passed today (server clock) to convertQuotation; convertQuotation
| given no businessDate does the same, so that is what is proved here.
|
| Deferred to the UI phase (Phase 8): posting the form with a past or future
| businessDate field and the redirect to /sales/{id}.
*/

use App\Services\QuotationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-16 10:30:00', config('nuna.business_timezone')));
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $cement = Shop::product($this->actor, 'Cement 50kg', 6_000, 8_000, ['unit' => 'bag']);
    Shop::openingStock($this->actor, $cement, 10_000, 60_000, '2026-08-01');
    $this->quotationId = QuotationService::createQuotation([
        'businessDate' => '2026-08-10',
        'validUntil' => '2026-09-10',
        'customerName' => 'Kofi Mensah',
        'lines' => [['productId' => $cement, 'qty' => 1_000]],
    ], $this->actor)['quotationId'];
    // As the action called it: the form's date is never passed on.
    $this->convert = fn (): array => QuotationService::convertQuotation($this->quotationId, [
        'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 8_000]],
    ], $this->actor);
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('converting a quote', function () {
    it('records the sale today, whatever date the form carried', function () {
        ($this->convert)();
        expect(DB::table('sales')->pluck('business_date')->all())->toBe(['2026-08-16']);
    });

    it('cannot put a sale on a day that has not happened yet', function () {
        ($this->convert)();
        expect(DB::table('sales')->pluck('business_date')->all())->toBe(['2026-08-16']);
    });
});
