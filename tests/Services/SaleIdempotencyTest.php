<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/sale-idempotency.test.ts: a sale
| submitted twice (same clientRef) is recorded once. The concurrent version
| of this lives in tests/Processes.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ConflictError;
use App\Services\CatalogService;
use App\Services\SaleService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const SALE_IDEM_TODAY = '2026-08-16';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->product = Shop::product($this->actor, 'Milo 400g', 500, 800);
    Shop::openingStock($this->actor, $this->product, 10_000, 5_000, SALE_IDEM_TODAY);
    // One Milo at 8.00, paid in cash, under the given cart reference.
    $this->sell = fn (?string $clientRef, int $tendered = 800, int $qty = 1_000): array => SaleService::createSale([
        'businessDate' => SALE_IDEM_TODAY,
        'items' => [['productId' => $this->product, 'qty' => $qty]],
        'tenders' => [['paymentAccountId' => $this->cash, 'amount' => $tendered]],
        ...($clientRef !== null ? ['clientRef' => $clientRef] : []),
    ], $this->actor);
});

describe('the same cart submitted twice', function () {
    it('records one sale, not two', function () {
        $before = DB::table('sales')->count();
        ($this->sell)('cart-abc-123');
        ($this->sell)('cart-abc-123');
        expect(DB::table('sales')->count())->toBe($before + 1);
    });

    it('refuses a cart changed since it was saved, naming the receipt, so the added goods are not handed over unbilled', function () {
        $first = ($this->sell)('cart-abc-123');

        expect(fn () => ($this->sell)('cart-abc-123', 1_600, 2_000))
            ->toThrow(ConflictError::class, $first['receiptNo']);
        expect(DB::table('sales')->count())->toBe(1)
            ->and(Shop::productRow($this->product)->qty_on_hand_milli)->toBe(9_000);
    });

    it('refuses a cart whose line was switched from pieces to cartons since it was saved', function () {
        $milk = Shop::product($this->actor, 'Milk', 400, 500, ['altUnit' => 'carton', 'altFactor' => 24_000, 'altSellingPrice' => 11_000]);
        Shop::openingStock($this->actor, $milk, 100_000, 40_000, SALE_IDEM_TODAY);
        $cart = fn (bool $alt): array => SaleService::createSale([
            'businessDate' => SALE_IDEM_TODAY,
            'items' => [['productId' => $milk, 'qty' => 1_000, ...($alt ? ['alt' => true, 'altFactor' => 24_000] : [])]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 20_000]],
            'clientRef' => 'cart-milk-1',
        ], $this->actor);
        $first = $cart(false);

        expect(fn () => $cart(true))->toThrow(ConflictError::class, $first['receiptNo']);
        expect(Shop::productRow($milk)->qty_on_hand_milli)->toBe(99_000);
    });

    it('returns the same receipt for a cart sold by the carton sent twice', function () {
        $milk = Shop::product($this->actor, 'Milk', 400, 500, ['altUnit' => 'carton', 'altFactor' => 24_000, 'altSellingPrice' => 11_000]);
        Shop::openingStock($this->actor, $milk, 100_000, 40_000, SALE_IDEM_TODAY);
        $cart = fn (): array => SaleService::createSale([
            'businessDate' => SALE_IDEM_TODAY,
            'items' => [['productId' => $milk, 'qty' => 1_000, 'alt' => true, 'altFactor' => 24_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 20_000]],
            'clientRef' => 'cart-milk-2',
        ], $this->actor);

        expect($cart()['receiptNo'])->toBe($cart()['receiptNo'])
            ->and(Shop::productRow($milk)->qty_on_hand_milli)->toBe(76_000);
    });

    it('refuses a cart sent again after its sale was voided, so the till does not show a voided receipt as saved', function () {
        $first = ($this->sell)('cart-abc-123');
        SaleService::voidSale($first['saleId'], 'rung up by mistake', $this->actor);

        expect(fn () => ($this->sell)('cart-abc-123'))->toThrow(ConflictError::class, 'voided');
        expect(DB::table('sales')->where('kind', 'SALE')->count())->toBe(1);
    });

    it('returns the same receipt both times', function () {
        $first = ($this->sell)('cart-abc-123');
        $second = ($this->sell)('cart-abc-123');

        expect($second['saleId'])->toBe($first['saleId'])
            ->and($second['receiptNo'])->toBe($first['receiptNo'])
            ->and($second['total'])->toBe($first['total'])
            ->and($second['cogs'])->toBe($first['cogs'])
            ->and($second['outstanding'])->toBe($first['outstanding'])
            ->and($second['journalEntryId'])->toBe($first['journalEntryId']);
    });

    it('takes the stock off the shelf once', function () {
        $movementsBefore = DB::table('stock_ledger')->count();
        ($this->sell)('cart-abc-123');
        $onHand = CatalogService::getProduct($this->product)['qtyOnHand'];

        ($this->sell)('cart-abc-123');

        expect(CatalogService::getProduct($this->product)['qtyOnHand'])->toBe($onHand)
            ->and(DB::table('stock_ledger')->count())->toBe($movementsBefore + 1);
    });

    it('posts one journal entry, so the books are not doubled', function () {
        $entriesBefore = DB::table('journal_entries')->count();
        ($this->sell)('cart-abc-123');
        ($this->sell)('cart-abc-123');

        expect(DB::table('journal_entries')->count())->toBe($entriesBefore + 1)
            ->and(Shop::balance(ChartOfAccounts::SALES_REVENUE))->toBe(800)
            ->and(Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD))->toBe(500)
            ->and(Shop::balance('1001'))->toBe(800);
    });

    it('does not burn a second receipt number', function () {
        $first = ($this->sell)('cart-abc-123');
        ($this->sell)('cart-abc-123');
        $next = ($this->sell)('cart-def-456');

        expect($next['receiptNo'])->not->toBe($first['receiptNo'])
            ->and(DB::table('sales')->count())->toBe(2);
    });

    it('still reports the change the cashier owes the customer', function () {
        $first = ($this->sell)('cart-abc-123', 2_000);
        $second = ($this->sell)('cart-abc-123', 2_000);

        expect($first['change'])->toBe(1_200)->and($second['change'])->toBe(1_200);
    });
});

describe('different carts', function () {
    it('are two sales, as they should be', function () {
        $first = ($this->sell)('cart-abc-123');
        $second = ($this->sell)('cart-def-456');

        expect($second['saleId'])->not->toBe($first['saleId'])
            ->and(DB::table('sales')->count())->toBe(2)
            ->and(CatalogService::getProduct($this->product)['qtyOnHand'])->toBe(8_000);
    });

    it('are still two sales when the carts are identical in every other way', function () {
        ($this->sell)('cart-one');
        ($this->sell)('cart-two');
        expect(DB::table('sales')->count())->toBe(2)
            ->and(Shop::balance(ChartOfAccounts::SALES_REVENUE))->toBe(1_600);
    });
});

describe('a sale with no cart reference', function () {
    it('is created every time, as before', function () {
        ($this->sell)(null);
        ($this->sell)(null);
        expect(DB::table('sales')->count())->toBe(2);
    });
});

describe('the replay of a credit sale', function () {
    it('reports what is still owed, not what was owed at the counter', function () {
        $customerId = Shop::customer($this->actor, 'Ama Serwaa');
        $input = [
            'businessDate' => SALE_IDEM_TODAY,
            'customerId' => $customerId,
            'items' => [['productId' => $this->product, 'qty' => 1_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 300]],
            'clientRef' => 'cart-credit-1',
        ];

        $first = SaleService::createSale($input, $this->actor);
        expect($first['outstanding'])->toBe(500);

        $replay = SaleService::createSale($input, $this->actor);
        expect($replay['saleId'])->toBe($first['saleId'])
            ->and($replay['outstanding'])->toBe(500)
            ->and(DB::table('sales')->count())->toBe(1);
    });
});

describe('the database enforces it, not just the service', function () {
    it('refuses a second sale carrying a reference already used', function () {
        $first = ($this->sell)('cart-abc-123');

        expect(fn () => DB::table('sales')->insert([
            'receipt_no' => 'R-FORGED',
            'business_date' => SALE_IDEM_TODAY,
            'occurred_at' => '2026-08-16 10:00:00.000',
            'subtotal_minor' => 800,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 800,
            'client_ref' => 'cart-abc-123',
        ]))->toThrow(QueryException::class, 'Duplicate entry');

        expect(DB::table('sales')->count())->toBe(1)
            ->and($first['receiptNo'])->not->toBe('R-FORGED');
    });
});
