<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/quotations.test.ts: a quote leaves the
| books alone until it converts, and converting charges what was promised.
| "A quote changes nothing in the books" is the old quotation-moves-nothing
| check. The trial balance comes from Shop::trialBalanced, which is the real
| BalancesService::getTrialBalance.
*/

use App\Domain\Errors\DomainError;
use App\Domain\Errors\ValidationError;
use App\Enums\AuditAction;
use App\Enums\MovementType;
use App\Services\AuditService;
use App\Services\QuotationService;
use App\Services\SaleService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const QUOTE_TODAY = '2026-08-29';
const QUOTE_NEXT_MONTH = '2026-09-28';
const QUOTE_LAST_WEEK = '2026-08-22';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->cement = Shop::product($this->actor, 'Cement 50kg', 6_500, 8_000, ['unit' => 'bag']);
    Shop::move([
        'productId' => $this->cement, 'direction' => 'IN', 'qty' => 200_000, 'totalCost' => 6_500 * 200,
        'movementType' => MovementType::Purchase, 'sourceType' => 'TEST', 'businessDate' => QUOTE_TODAY,
        'occurredAt' => Carbon::parse(QUOTE_TODAY.'T08:00:00Z'), 'userId' => $this->actor->id,
    ]);
    $this->quoteFor = fn (array $overrides = []): array => QuotationService::createQuotation([
        'businessDate' => QUOTE_TODAY,
        'validUntil' => QUOTE_NEXT_MONTH,
        'customerName' => 'Kofi Mensah',
        'lines' => [['productId' => $this->cement, 'qty' => 40_000]],
        ...$overrides,
    ], $this->actor);
    $this->convert = fn (int $id, array $input = []): array => QuotationService::convertQuotation($id, [
        'businessDate' => QUOTE_TODAY,
        'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 320_000]],
        ...$input,
    ], $this->actor);
    $this->edit = fn (int $id, array $lines, string $name = 'Kofi'): mixed => QuotationService::updateQuotation(
        $id,
        ['validUntil' => QUOTE_NEXT_MONTH, 'customerName' => $name, 'lines' => $lines],
        $this->actor,
    );
});

describe('writing a quote', function () {
    it('gives it a number a contractor can quote back', function () {
        expect(($this->quoteFor)()['quoteNo'])->toBe('QTE-00001')
            ->and(($this->quoteFor)()['quoteNo'])->toBe('QTE-00002');
    });

    it('prices it from the product list when no price is offered', function () {
        $quote = QuotationService::getQuotation(($this->quoteFor)()['quotationId']);
        expect($quote['items'][0]['unitPriceMinor'])->toBe(8_000)->and($quote['totalMinor'])->toBe(320_000);
    });

    it('takes a negotiated price without needing a permission at this layer', function () {
        $created = ($this->quoteFor)(['lines' => [['productId' => $this->cement, 'qty' => 40_000, 'unitPrice' => 7_500]]]);
        expect(QuotationService::getQuotation($created['quotationId'])['totalMinor'])->toBe(300_000);
    });

    it('snapshots the product name, so a later rename cannot rewrite the offer', function () {
        $created = ($this->quoteFor)();
        DB::table('products')->where('id', $this->cement)->update(['name' => 'Dangote Cement 50kg']);

        expect(QuotationService::getQuotation($created['quotationId'])['items'][0]['productName'])->toBe('Cement 50kg');
    });

    it('refuses a quote with nothing on it', function () {
        expect(fn () => ($this->quoteFor)(['lines' => []]))->toThrow(ValidationError::class, 'at least one item');
    });

    it('refuses a quote with nobody to give it to', function () {
        expect(fn () => ($this->quoteFor)(['customerName' => '   ']))->toThrow(ValidationError::class, 'who the quote is for');
    });

    it('refuses to expire before it is issued', function () {
        expect(fn () => ($this->quoteFor)(['validUntil' => QUOTE_LAST_WEEK]))->toThrow(ValidationError::class, 'cannot expire before');
    });

    it('records who wrote it and when it runs out', function () {
        $created = ($this->quoteFor)();
        $summary = AuditService::list(['action' => AuditAction::Create])[0]->summary ?? '';

        expect($summary)->toContain($created['quoteNo'])->toContain('Kofi Mensah')->toContain(QUOTE_NEXT_MONTH);
    });
});

describe('changing a quote', function () {
    it('replaces its lines, because an offer is not history', function () {
        $created = ($this->quoteFor)();
        ($this->edit)($created['quotationId'], [['productId' => $this->cement, 'qty' => 60_000]], 'Kofi Mensah');

        $quote = QuotationService::getQuotation($created['quotationId']);
        expect($quote['items'])->toHaveCount(1)
            ->and($quote['items'][0]['qtyMilli'])->toBe(60_000)
            ->and($quote['totalMinor'])->toBe(480_000)
            ->and($quote['quoteNo'])->toBe($created['quoteNo']);
    });

    it('refuses once the quote has become a sale', function () {
        $created = ($this->quoteFor)();
        ($this->convert)($created['quotationId']);

        expect(fn () => ($this->edit)($created['quotationId'], [['productId' => $this->cement, 'qty' => 1_000]]))
            ->toThrow(ValidationError::class, 'already become a sale');
    });

    it('refuses once the quote has been cancelled', function () {
        $created = ($this->quoteFor)();
        QuotationService::cancelQuotation($created['quotationId'], 'Customer went elsewhere', $this->actor);

        expect(fn () => ($this->edit)($created['quotationId'], [['productId' => $this->cement, 'qty' => 1_000]]))
            ->toThrow(ValidationError::class, 'cancelled');
    });

    it('will not cancel without a reason', function () {
        $created = ($this->quoteFor)();
        expect(fn () => QuotationService::cancelQuotation($created['quotationId'], '  ', $this->actor))->toThrow(ValidationError::class, 'why');
    });
});

describe('turning a quote into a sale', function () {
    it('charges what was quoted, even after the shelf price moves', function () {
        $created = ($this->quoteFor)(['lines' => [['productId' => $this->cement, 'qty' => 40_000, 'unitPrice' => 7_500]]]);
        DB::table('products')->where('id', $this->cement)->update(['selling_price_minor' => 9_000]);

        $converted = ($this->convert)($created['quotationId'], ['tenders' => [['paymentAccountId' => $this->cash, 'amount' => 300_000]]]);

        $sale = SaleService::getSale($converted['saleId']);
        expect($sale['items'][0]['unitPriceMinor'])->toBe(7_500)->and($sale['totalMinor'])->toBe(300_000);
    });

    it('produces a sale whose total is the quoted total, to the pesewa', function () {
        $created = ($this->quoteFor)([
            'lines' => [
                ['productId' => $this->cement, 'qty' => 37_000, 'unitPrice' => 7_333],
                ['productId' => $this->cement, 'qty' => 3_000, 'unitPrice' => 8_000, 'discount' => 1_111],
            ],
            'quoteDiscount' => 2_777,
        ]);
        $quote = QuotationService::getQuotation($created['quotationId']);

        $converted = ($this->convert)($created['quotationId'], ['tenders' => [['paymentAccountId' => $this->cash, 'amount' => $quote['totalMinor']]]]);

        expect(SaleService::getSale($converted['saleId'])['totalMinor'])->toBe($quote['totalMinor']);
    });

    it('closes the quote and links it to the sale, both ways', function () {
        $created = ($this->quoteFor)();
        $converted = ($this->convert)($created['quotationId']);

        $quote = QuotationService::getQuotation($created['quotationId']);
        expect($quote['status'])->toBe('CONVERTED')
            ->and($quote['convertedSaleId'])->toBe($converted['saleId'])
            ->and(SaleService::getSale($converted['saleId'])['note'])->toContain($created['quoteNo']);
    });

    it('refuses a second conversion', function () {
        $created = ($this->quoteFor)();
        ($this->convert)($created['quotationId']);

        expect(fn () => ($this->convert)($created['quotationId']))->toThrow(ValidationError::class, 'already become a sale');
        expect(DB::table('sales')->count())->toBe(1);
    });

    it('is refused by the database as well as by the service', function () {
        $created = ($this->quoteFor)();
        $converted = ($this->convert)($created['quotationId']);
        $second = ($this->quoteFor)();

        // MySQL's unique index says "Duplicate entry" where SQLite said "UNIQUE constraint failed".
        expect(fn () => DB::update('UPDATE quotations SET status = ?, converted_sale_id = ? WHERE id = ?', ['CONVERTED', $converted['saleId'], $second['quotationId']]))
            ->toThrow(QueryException::class, 'Duplicate entry');
    });

    it('creates the customer at conversion, when asked', function () {
        $created = ($this->quoteFor)(['customerName' => 'Adom Construction', 'customerPhone' => '024 000 0000']);
        $converted = ($this->convert)($created['quotationId'], [
            'createCustomer' => true,
            'termsDays' => 30,
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 0]],
        ]);

        $sale = SaleService::getSale($converted['saleId']);
        expect($sale['customerId'])->not->toBeNull()
            ->and(QuotationService::getQuotation($created['quotationId'])['customerId'])->toBe($sale['customerId']);
    });
});

describe('a quote whose promise has run out', function () {
    beforeEach(function () {
        $this->expiredQuote = fn (): array => ($this->quoteFor)(['businessDate' => QUOTE_LAST_WEEK, 'validUntil' => QUOTE_LAST_WEEK]);
    });

    it('is refused, naming the day it was good to', function () {
        $created = ($this->expiredQuote)();
        expect(fn () => ($this->convert)($created['quotationId']))->toThrow(ValidationError::class, QUOTE_LAST_WEEK);
    });

    it('converts when the owner says why, and the reason is kept', function () {
        $created = ($this->expiredQuote)();
        ($this->convert)($created['quotationId'], ['overrideReason' => 'Agreed with the customer on the phone']);

        $quote = QuotationService::getQuotation($created['quotationId']);
        expect($quote['status'])->toBe('CONVERTED')
            ->and($quote['overrideReason'])->toBe('Agreed with the customer on the phone')
            ->and(AuditService::list(['action' => AuditAction::Update])[0]->summary ?? '')->toContain('honoured');
    });

    it('knows itself as expired without a stored status', function () {
        expect(QuotationService::isExpired(['status' => 'OPEN', 'validUntil' => QUOTE_LAST_WEEK], QUOTE_TODAY))->toBeTrue()
            ->and(QuotationService::isExpired(['status' => 'OPEN', 'validUntil' => QUOTE_NEXT_MONTH], QUOTE_TODAY))->toBeFalse()
            ->and(QuotationService::isExpired(['status' => 'CONVERTED', 'validUntil' => QUOTE_LAST_WEEK], QUOTE_TODAY))->toBeFalse();
    });
});

describe('the quote list', function () {
    it('counts what it lists, from the same conditions', function () {
        ($this->quoteFor)();
        ($this->quoteFor)(['customerName' => 'Ama Serwaa']);
        $cancelled = ($this->quoteFor)();
        QuotationService::cancelQuotation($cancelled['quotationId'], 'Changed mind', $this->actor);

        expect(QuotationService::listQuotations(['status' => 'OPEN']))->toHaveCount(2)
            ->and(QuotationService::countQuotations(['status' => 'OPEN']))->toBe(2)
            ->and(QuotationService::countQuotations(['status' => 'CANCELLED']))->toBe(1);
    });

    it('finds a quote by its number, its customer or the job', function () {
        ($this->quoteFor)(['customerName' => 'Adom Construction', 'reference' => 'Adenta site']);

        expect(QuotationService::countQuotations(['search' => 'adom']))->toBe(1)
            ->and(QuotationService::countQuotations(['search' => 'adenta']))->toBe(1)
            ->and(QuotationService::countQuotations(['search' => 'QTE-00001']))->toBe(1)
            ->and(QuotationService::countQuotations(['search' => 'nothing here']))->toBe(0);
    });

    it('shows the ones that have quietly run out', function () {
        ($this->quoteFor)();
        ($this->quoteFor)(['businessDate' => QUOTE_LAST_WEEK, 'validUntil' => QUOTE_LAST_WEEK]);

        expect(QuotationService::countQuotations(['expired' => true], QUOTE_TODAY))->toBe(1);
    });

    it('treats an id that matches nothing as an empty result, not an error', function () {
        ($this->quoteFor)();
        expect(QuotationService::listQuotations(['customerId' => 9_999]))->toBe([]);
    });
});

describe('a quote changes nothing in the books', function () {
    beforeEach(function () {
        $this->snapshot = fn (): array => [
            'stock' => DB::table('products')->orderBy('id')->get(['id', 'qty_on_hand_milli', 'stock_value_minor'])->all(),
            'entries' => DB::table('journal_entries')->count(),
            'lines' => DB::table('journal_lines')->count(),
            'ledger' => DB::table('stock_ledger')->count(),
            'sales' => DB::table('sales')->count(),
            'trial' => Shop::trialBalanced(),
        ];
    });

    it('leaves stock, the ledger and the trial balance exactly as they were', function () {
        $before = ($this->snapshot)();

        $created = ($this->quoteFor)();
        ($this->edit)($created['quotationId'], [['productId' => $this->cement, 'qty' => 120_000]], 'Kofi Mensah');
        QuotationService::getQuotation($created['quotationId']);
        QuotationService::listQuotations(['status' => 'OPEN']);
        QuotationService::cancelQuotation($created['quotationId'], 'Went elsewhere', $this->actor);

        expect(($this->snapshot)())->toEqual($before);
    });

    it('does not reserve the stock it quotes', function () {
        ($this->quoteFor)(['lines' => [['productId' => $this->cement, 'qty' => 200_000]]]);
        expect((int) DB::table('products')->where('id', $this->cement)->value('qty_on_hand_milli'))->toBe(200_000);
    });
});

describe('conversion is all or nothing', function () {
    it('leaves the quote open and the books untouched when the sale fails', function () {
        $created = ($this->quoteFor)(['lines' => [['productId' => $this->cement, 'qty' => 500_000]]]);
        $before = [DB::table('sales')->count(), DB::table('journal_entries')->count(), (int) DB::table('products')->where('id', $this->cement)->value('qty_on_hand_milli')];

        expect(fn () => ($this->convert)($created['quotationId'], ['tenders' => [['paymentAccountId' => $this->cash, 'amount' => 4_000_000]]]))->toThrow(DomainError::class);

        expect([DB::table('sales')->count(), DB::table('journal_entries')->count(), (int) DB::table('products')->where('id', $this->cement)->value('qty_on_hand_milli')])->toBe($before);
        $quote = QuotationService::getQuotation($created['quotationId']);
        expect($quote['status'])->toBe('OPEN')
            ->and($quote['convertedSaleId'])->toBeNull()
            ->and(Shop::trialBalanced())->toBeTrue();
    });

    it('leaves no orphan quote lines behind after a failed edit', function () {
        $created = ($this->quoteFor)();
        expect(fn () => ($this->edit)($created['quotationId'], [['productId' => 9_999, 'qty' => 1_000]]))->toThrow(DomainError::class);

        $items = DB::table('quotation_items')->where('quotation_id', $created['quotationId'])->get();
        expect($items)->toHaveCount(1)->and((int) $items[0]->qty_milli)->toBe(40_000);
    });
});

describe('a shop that prices tax-inclusive', function () {
    foreach ([false, true] as $inclusive) {
        it('quotes and sells the same total, tax '.($inclusive ? 'inclusive' : 'exclusive'), function () use ($inclusive) {
            Shop::setGhanaTaxes($inclusive);
            $created = ($this->quoteFor)([
                'lines' => [
                    ['productId' => $this->cement, 'qty' => 37_000, 'unitPrice' => 7_333],
                    ['productId' => $this->cement, 'qty' => 3_000, 'unitPrice' => 8_000, 'discount' => 1_111],
                ],
                'quoteDiscount' => 2_777,
            ]);
            $quote = QuotationService::getQuotation($created['quotationId']);

            $converted = ($this->convert)($created['quotationId'], ['tenders' => [['paymentAccountId' => $this->cash, 'amount' => $quote['totalMinor']]]]);

            $sale = SaleService::getSale($converted['saleId']);
            expect($sale['totalMinor'])->toBe($quote['totalMinor'])
                ->and($sale['taxMinor'])->toBe($quote['taxMinor'])
                ->and($sale['discountMinor'])->toBe($quote['discountMinor'])
                ->and(Shop::trialBalanced())->toBeTrue();
        });
    }

    it('keeps the typed discount apart from the net one when tax is inclusive', function () {
        Shop::setGhanaTaxes(true);
        $quote = QuotationService::getQuotation(($this->quoteFor)(['quoteDiscount' => 10_000])['quotationId']);

        expect($quote['quoteDiscountMinor'])->toBe(10_000)->and($quote['discountMinor'])->toBeLessThan(10_000);
    });
});
