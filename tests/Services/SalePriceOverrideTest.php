<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/sale-price-override.test.ts: selling
| below (or above) the shop's own prices, or discounting, is a separate right
| from ringing a sale up.
*/

use App\Domain\Errors\ForbiddenError;
use App\Domain\Errors\ValidationError;
use App\Services\CatalogService;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const SALE_PRICE_TODAY = '2026-08-16';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    // Milo, cost 5.00, sells for 8.00, ten on the shelf.
    $this->product = Shop::product($this->actor, 'Milo 400g', 500, 800);
    Shop::openingStock($this->actor, $this->product, 10_000, 5_000, SALE_PRICE_TODAY);
    // A cart of one Milo, paid in cash, with whatever concession is being tried.
    $this->sell = function (array $overrides): array {
        $line = array_intersect_key($overrides, ['unitPrice' => 0, 'discount' => 0]);

        return SaleService::createSale([
            'businessDate' => SALE_PRICE_TODAY,
            'items' => [['productId' => $this->product, 'qty' => 1_000, ...$line]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 800]],
            ...array_intersect_key($overrides, ['invoiceDiscount' => 0, 'allowPriceOverride' => 0]),
        ], $this->actor);
    };
});

describe('a till without the right to change prices', function () {
    it('sells happily at the shop price', function () {
        expect(($this->sell)([])['total'])->toBe(800);
    });

    it('sells at the shop price when the till sends it explicitly', function () {
        expect(($this->sell)(['unitPrice' => 800])['total'])->toBe(800);
    });

    it('REFUSES a price below the shop price', function () {
        expect(fn () => ($this->sell)(['unitPrice' => 1]))->toThrow(ForbiddenError::class);
    });

    it('REFUSES a price above the shop price', function () {
        expect(fn () => ($this->sell)(['unitPrice' => 5_000]))->toThrow(ForbiddenError::class);
    });

    it('REFUSES a line discount', function () {
        expect(fn () => ($this->sell)(['discount' => 700]))->toThrow(ForbiddenError::class);
    });

    it('REFUSES a discount on the whole sale', function () {
        expect(fn () => ($this->sell)(['invoiceDiscount' => 700]))->toThrow(ForbiddenError::class);
    });

    it('REFUSES the full-discount route to a free sale', function () {
        expect(fn () => ($this->sell)(['discount' => 800]))->toThrow(ForbiddenError::class);
    });

    it('allows a zero discount, which is what an empty box means', function () {
        expect(($this->sell)(['discount' => 0])['total'])->toBe(800);
    });

    it('says what to do about it, rather than just "not permitted"', function () {
        try {
            ($this->sell)(['unitPrice' => 1]);
            $this->fail('the sale should have been refused');
        } catch (ForbiddenError $error) {
            expect($error->userMessage)->toContain('supervisor')->toContain('8.00');
        }
    });
});

describe('the refusal leaves nothing behind', function () {
    it('writes no sale, no stock movement and no journal entry', function () {
        $ledgerBefore = DB::table('stock_ledger')->count();
        $entriesBefore = DB::table('journal_entries')->count();
        $onHandBefore = CatalogService::getProduct($this->product)['qtyOnHand'];

        expect(fn () => ($this->sell)(['unitPrice' => 1]))->toThrow(ForbiddenError::class);

        expect(DB::table('sales')->count())->toBe(0)
            ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
            ->and(DB::table('journal_entries')->count())->toBe($entriesBefore)
            ->and(CatalogService::getProduct($this->product)['qtyOnHand'])->toBe($onHandBefore);
    });

    it('does not consume a receipt number', function () {
        expect(fn () => ($this->sell)(['discount' => 500]))->toThrow(ForbiddenError::class);
        $sale = ($this->sell)([]);
        expect($sale['receiptNo'])->toContain('1')
            ->and(DB::table('sales')->count())->toBe(1);
    });
});

describe('a till that DOES hold the right', function () {
    it('may sell below the shop price', function () {
        expect(($this->sell)(['unitPrice' => 600, 'allowPriceOverride' => true])['total'])->toBe(600);
    });

    it('may give a line discount', function () {
        expect(($this->sell)(['discount' => 200, 'allowPriceOverride' => true])['total'])->toBe(600);
    });

    it('may discount the whole sale', function () {
        expect(($this->sell)(['invoiceDiscount' => 200, 'allowPriceOverride' => true])['total'])->toBe(600);
    });

    it('still cannot sell at a negative price', function () {
        expect(fn () => ($this->sell)(['unitPrice' => -100, 'allowPriceOverride' => true]))->toThrow(ValidationError::class);
    });

    it('still cannot discount more than the line is worth', function () {
        expect(fn () => ($this->sell)(['discount' => 900, 'allowPriceOverride' => true]))->toThrow(ValidationError::class);
    });
});

describe('the default', function () {
    it('is to refuse, when the flag is not passed at all', function () {
        expect(fn () => ($this->sell)(['unitPrice' => 1]))->toThrow(ForbiddenError::class);
    });

    it('is to refuse, when the flag is explicitly false', function () {
        expect(fn () => ($this->sell)(['unitPrice' => 1, 'allowPriceOverride' => false]))->toThrow(ForbiddenError::class);
    });
});
