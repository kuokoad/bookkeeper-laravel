<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/sales-filters.test.ts. The filter narrows
| the rows in SQL before any page limit, and the totals above the table come
| from the same clause as the table. Filtering never writes.
*/

use App\Enums\AdjustmentReason;
use App\Services\CatalogService;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->staff = Shop::owner('ama');
    $this->cash = Shop::paymentAccount('CASH');
    $this->momo = Shop::paymentAccount('MOBILE_MONEY');
    $this->drinks = CatalogService::createCategory(['name' => 'Drinks'], $this->actor);
    $this->food = CatalogService::createCategory(['name' => 'Food'], $this->actor);

    $stocked = function (string $name, int $categoryId, int $price): int {
        $id = Shop::product($this->actor, $name, 500, $price, ['categoryId' => $categoryId]);
        StockAdjustmentService::createStockAdjustment(['businessDate' => '2026-07-31', 'reason' => AdjustmentReason::OpeningStock, 'items' => [
            ['productId' => $id, 'direction' => 'IN', 'qty' => 500_000, 'totalCost' => 250_000],
        ]], $this->actor);

        return $id;
    };
    $this->coke = $stocked('Coca-Cola 500ml', $this->drinks, 500);
    $this->rice = $stocked('Rice 5kg', $this->food, 6_000);

    $this->kofi = CustomerService::createCustomer(['name' => 'Kofi Mensah', 'phone' => '0244000111'], $this->actor);
    $this->ama = CustomerService::createCustomer(['name' => 'Ama Serwaa', 'phone' => '0201234567'], $this->actor);

    $this->cokeSale = fn (string $date, int $quantity, $actor = null): int => SaleService::createSale([
        'businessDate' => $date,
        'items' => [['productId' => $this->coke, 'qty' => $quantity * 1000]],
        'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 500 * $quantity]],
    ], $actor ?? $this->actor)['saleId'];
    $this->riceCreditSale = fn (string $date, int $customerId, int $quantity): int => SaleService::createSale([
        'businessDate' => $date,
        'customerId' => $customerId,
        'items' => [['productId' => $this->rice, 'qty' => $quantity * 1000]],
        'tenders' => [],
    ], $this->actor)['saleId'];
    $this->range = ['from' => '2026-08-01', 'to' => '2026-08-31'];
});

describe('the date filter', function () {
    it('includes sales on the last day of the range, however late they were rung up', function () {
        ($this->cokeSale)('2026-08-01', 1);
        ($this->cokeSale)('2026-08-15', 1);
        SaleService::createSale([
            'businessDate' => '2026-08-15',
            'items' => [['productId' => $this->coke, 'qty' => 3_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 1_500]],
            'occurredAt' => Carbon::parse('2026-08-15 23:47:00', config('nuna.business_timezone')),
        ], $this->actor);
        ($this->cokeSale)('2026-08-16', 1);

        $filters = ['from' => '2026-08-01', 'to' => '2026-08-15'];
        expect(SaleService::countSales($filters))->toBe(3)
            ->and(SaleService::getFilteredSalesSummary($filters)['revenue'])->toBe(500 + 500 + 1_500);
    });

    it('excludes a sale one day outside the range at either end', function () {
        ($this->cokeSale)('2026-07-31', 1);
        ($this->cokeSale)('2026-08-01', 1);
        ($this->cokeSale)('2026-08-31', 1);
        ($this->cokeSale)('2026-09-01', 1);

        expect(SaleService::countSales($this->range))->toBe(2);
    });
});

describe('the customer filter', function () {
    it('narrows to one customer and totals only their sales', function () {
        ($this->riceCreditSale)('2026-08-02', $this->kofi, 1);
        ($this->riceCreditSale)('2026-08-03', $this->kofi, 2);
        ($this->riceCreditSale)('2026-08-04', $this->ama, 1);
        ($this->cokeSale)('2026-08-05', 1);

        $filters = [...$this->range, 'customerId' => $this->kofi];
        expect(SaleService::countSales($filters))->toBe(2)
            ->and(collect(SaleService::listSales($filters))->every(fn (array $r): bool => $r['customerId'] === $this->kofi))->toBeTrue()
            ->and(SaleService::getFilteredSalesSummary($filters)['revenue'])->toBe(18_000);
    });
});

describe('the product and category filters', function () {
    it('finds sales containing a product', function () {
        ($this->cokeSale)('2026-08-02', 2);
        ($this->riceCreditSale)('2026-08-03', $this->kofi, 1);

        expect(SaleService::countSales([...$this->range, 'productId' => $this->coke]))->toBe(1)
            ->and(SaleService::countSales([...$this->range, 'productId' => $this->rice]))->toBe(1);
    });

    it('finds a mixed sale under either of its products', function () {
        SaleService::createSale([
            'businessDate' => '2026-08-02',
            'items' => [['productId' => $this->coke, 'qty' => 2_000], ['productId' => $this->rice, 'qty' => 1_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 7_000]],
        ], $this->actor);

        expect(SaleService::countSales([...$this->range, 'productId' => $this->coke]))->toBe(1)
            ->and(SaleService::countSales([...$this->range, 'productId' => $this->rice]))->toBe(1)
            ->and(SaleService::listSales([...$this->range, 'productId' => $this->coke]))->toHaveCount(1);
    });

    it('narrows by category', function () {
        ($this->cokeSale)('2026-08-02', 1);
        ($this->cokeSale)('2026-08-03', 1);
        ($this->riceCreditSale)('2026-08-04', $this->kofi, 1);

        expect(SaleService::countSales([...$this->range, 'categoryId' => $this->drinks]))->toBe(2)
            ->and(SaleService::countSales([...$this->range, 'categoryId' => $this->food]))->toBe(1);
    });
});

describe('the payment filters', function () {
    it('separates cash from mobile money', function () {
        ($this->cokeSale)('2026-08-02', 1);
        SaleService::createSale([
            'businessDate' => '2026-08-03',
            'items' => [['productId' => $this->coke, 'qty' => 4_000]],
            'tenders' => [['paymentAccountId' => $this->momo, 'amount' => 2_000]],
        ], $this->actor);

        expect(SaleService::countSales([...$this->range, 'paymentKind' => 'CASH']))->toBe(1)
            ->and(SaleService::countSales([...$this->range, 'paymentKind' => 'MOBILE_MONEY']))->toBe(1)
            ->and(SaleService::countSales([...$this->range, 'paymentAccountId' => $this->momo]))->toBe(1);
    });

    it('finds a split-tender sale under both methods, once each', function () {
        SaleService::createSale([
            'businessDate' => '2026-08-02',
            'items' => [['productId' => $this->rice, 'qty' => 1_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 2_000], ['paymentAccountId' => $this->momo, 'amount' => 4_000]],
        ], $this->actor);

        expect(SaleService::listSales([...$this->range, 'paymentKind' => 'CASH']))->toHaveCount(1)
            ->and(SaleService::listSales([...$this->range, 'paymentKind' => 'MOBILE_MONEY']))->toHaveCount(1);
    });

    it('tells settled sales from ones still owing', function () {
        ($this->cokeSale)('2026-08-02', 1);
        ($this->riceCreditSale)('2026-08-03', $this->kofi, 1);

        expect(SaleService::countSales([...$this->range, 'paymentState' => 'unpaid']))->toBe(1)
            ->and(SaleService::countSales([...$this->range, 'paymentState' => 'paid']))->toBe(1);
    });

    it('moves a credit sale to settled once the customer pays', function () {
        $saleId = ($this->riceCreditSale)('2026-08-03', $this->kofi, 1);
        expect(SaleService::countSales([...$this->range, 'paymentState' => 'unpaid']))->toBe(1);

        CustomerPaymentService::recordCustomerPayment([
            'customerId' => $this->kofi,
            'businessDate' => '2026-08-10',
            'paymentAccountId' => $this->cash,
            'amount' => 6_000,
            'allocations' => [['saleId' => $saleId, 'amount' => 6_000]],
        ], $this->actor);

        expect(SaleService::countSales([...$this->range, 'paymentState' => 'unpaid']))->toBe(0)
            ->and(SaleService::countSales([...$this->range, 'paymentState' => 'paid']))->toBe(1);
    });

    it('finds a credit sale that sits past the page limit', function () {
        $owing = ($this->riceCreditSale)('2026-08-01', $this->kofi, 1);
        for ($day = 2; $day <= 20; $day++) {
            ($this->cokeSale)(sprintf('2026-08-%02d', $day), 1);
        }

        $filters = [...$this->range, 'paymentState' => 'unpaid'];
        expect(SaleService::countSales($filters))->toBe(1)
            ->and(SaleService::listSales([...$filters, 'limit' => 5])[0]['id'])->toBe($owing);
    });
});

describe('the staff filter', function () {
    it('narrows to who rang the sale up', function () {
        ($this->cokeSale)('2026-08-02', 1, $this->actor);
        ($this->cokeSale)('2026-08-03', 1, $this->staff);
        ($this->cokeSale)('2026-08-04', 1, $this->staff);

        expect(SaleService::countSales([...$this->range, 'staffId' => $this->actor->id]))->toBe(1)
            ->and(SaleService::countSales([...$this->range, 'staffId' => $this->staff->id]))->toBe(2);
    });
});

describe('the search box', function () {
    it('finds a sale by receipt number, customer name, phone, product name and SKU', function () {
        $saleId = ($this->riceCreditSale)('2026-08-03', $this->kofi, 1);
        $receipt = collect(SaleService::listSales($this->range))->firstWhere('id', $saleId)['receiptNo'];
        DB::table('products')->where('id', $this->rice)->update(['sku' => 'RICE-5KG']);

        foreach ([$receipt, 'kofi', '0244', 'rice', 'RICE-5KG'] as $term) {
            expect(array_column(SaleService::listSales([...$this->range, 'search' => $term]), 'id'))->toContain($saleId);
        }
    });

    it('is case-insensitive and matches part of a word', function () {
        ($this->riceCreditSale)('2026-08-03', $this->ama, 1);

        expect(SaleService::countSales([...$this->range, 'search' => 'SERWAA']))->toBe(1)
            ->and(SaleService::countSales([...$this->range, 'search' => 'erw']))->toBe(1);
    });

    it('finds nothing for a term nobody used, rather than everything', function () {
        ($this->cokeSale)('2026-08-02', 1);
        expect(SaleService::countSales([...$this->range, 'search' => 'zzz']))->toBe(0);
    });
});

describe('the amount filter', function () {
    it('narrows to a band of sale totals', function () {
        ($this->cokeSale)('2026-08-02', 1);
        ($this->cokeSale)('2026-08-03', 20);
        ($this->riceCreditSale)('2026-08-04', $this->kofi, 10);

        expect(SaleService::countSales([...$this->range, 'minAmount' => 10_000]))->toBe(2)
            ->and(SaleService::countSales([...$this->range, 'maxAmount' => 10_000]))->toBe(2)
            ->and(SaleService::countSales([...$this->range, 'minAmount' => 9_000, 'maxAmount' => 11_000]))->toBe(1);
    });
});

describe('filters combined', function () {
    it('satisfies every condition at once', function () {
        $wanted = ($this->cokeSale)('2026-08-10', 2);
        SaleService::createSale(['businessDate' => '2026-08-11', 'items' => [['productId' => $this->coke, 'qty' => 2_000]], 'tenders' => [['paymentAccountId' => $this->momo, 'amount' => 1_000]]], $this->actor);
        SaleService::createSale(['businessDate' => '2026-08-12', 'items' => [['productId' => $this->rice, 'qty' => 1_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 6_000]]], $this->actor);
        ($this->cokeSale)('2026-09-01', 2);

        $filters = [...$this->range, 'categoryId' => $this->drinks, 'paymentKind' => 'CASH', 'search' => 'Coca'];
        $rows = SaleService::listSales($filters);
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['id'])->toBe($wanted)
            ->and(SaleService::countSales($filters))->toBe(1)
            ->and(SaleService::getFilteredSalesSummary($filters)['revenue'])->toBe(1_000);
    });
});

describe('filtered totals', function () {
    it('totals exactly the sales the filter selects', function () {
        ($this->cokeSale)('2026-08-05', 10);
        ($this->cokeSale)('2026-08-06', 4);
        ($this->riceCreditSale)('2026-08-07', $this->kofi, 5);
        ($this->cokeSale)('2026-08-20', 10);

        $filters = ['from' => '2026-08-01', 'to' => '2026-08-15', 'paymentKind' => 'CASH'];
        $summary = SaleService::getFilteredSalesSummary($filters);
        $rows = SaleService::listSales($filters);

        expect($summary['count'])->toBe(2)
            ->and($rows)->toHaveCount(2)
            ->and($summary['revenue'])->toBe(array_sum(array_column($rows, 'totalMinor')))
            ->and($summary['cogs'])->toBe(array_sum(array_column($rows, 'cogsMinor')))
            ->and($summary['grossProfit'])->toBe($summary['revenue'] - $summary['cogs'])
            ->and($summary['quantity'])->toBe(14_000);
    });

    it('does not multiply a sale by its number of lines', function () {
        SaleService::createSale([
            'businessDate' => '2026-08-02',
            'items' => [['productId' => $this->coke, 'qty' => 2_000], ['productId' => $this->rice, 'qty' => 1_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 7_000]],
        ], $this->actor);

        $summary = SaleService::getFilteredSalesSummary($this->range);
        expect($summary['revenue'])->toBe(7_000)
            ->and($summary['count'])->toBe(1)
            ->and($summary['quantity'])->toBe(3_000);
    });

    it('counts corrections out of the sale count but keeps their money in the totals', function () {
        // Today: the correction is dated from the clock, so the pair shares this month.
        $today = Clock::today();
        $saleId = ($this->cokeSale)($today, 10);
        SaleService::voidSale($saleId, 'Rang it up twice', $this->actor);

        $summary = SaleService::getFilteredSalesSummary(Shop::monthOf($today));
        expect($summary['count'])->toBe(1)->and($summary['revenue'])->toBe(0);
    });

    it('reports nothing rather than throwing when a filter matches no sales', function () {
        ($this->cokeSale)('2026-08-02', 1);
        $filters = ['from' => '2026-01-01', 'to' => '2026-01-31'];

        expect(SaleService::countSales($filters))->toBe(0)
            ->and(SaleService::listSales($filters))->toBe([])
            ->and(SaleService::getFilteredSalesSummary($filters))->toMatchArray([
                'count' => 0, 'revenue' => 0, 'cogs' => 0, 'grossProfit' => 0, 'quantity' => 0, 'discount' => 0, 'outstanding' => 0,
            ]);
    });
});

describe('pagination', function () {
    it('pages through the filtered set without repeating or losing a row', function () {
        for ($day = 1; $day <= 25; $day++) {
            ($this->cokeSale)(sprintf('2026-08-%02d', $day), 1);
        }
        for ($day = 1; $day <= 25; $day++) {
            ($this->riceCreditSale)(sprintf('2026-08-%02d', $day), $this->kofi, 1);
        }

        $filters = [...$this->range, 'categoryId' => $this->drinks];
        expect(SaleService::countSales($filters))->toBe(25);

        $seen = [];
        for ($page = 0; $page < 3; $page++) {
            foreach (SaleService::listSales([...$filters, 'limit' => 10, 'offset' => $page * 10]) as $row) {
                expect(isset($seen[$row['id']]))->toBeFalse();
                $seen[$row['id']] = true;
            }
        }
        expect($seen)->toHaveCount(25);
    });

    it('returns nothing for a page past the end rather than wrapping round', function () {
        ($this->cokeSale)('2026-08-02', 1);
        expect(SaleService::listSales([...$this->range, 'limit' => 10, 'offset' => 100]))->toBe([]);
    });

    it('leaves the count and the totals unchanged whichever page is asked for', function () {
        for ($day = 1; $day <= 12; $day++) {
            ($this->cokeSale)(sprintf('2026-08-%02d', $day), 1);
        }
        $total = SaleService::countSales($this->range);
        $summary = SaleService::getFilteredSalesSummary($this->range);

        SaleService::listSales([...$this->range, 'limit' => 5, 'offset' => 5]);

        expect(SaleService::countSales($this->range))->toBe($total)
            ->and(SaleService::getFilteredSalesSummary($this->range))->toBe($summary);
    });
});

describe('sorting', function () {
    it('sorts by amount in both directions, and pages consistently', function () {
        ($this->cokeSale)('2026-08-02', 1);
        ($this->cokeSale)('2026-08-03', 20);
        ($this->cokeSale)('2026-08-04', 6);

        $filters = [...$this->range, 'sort' => 'amount'];
        expect(array_column(SaleService::listSales([...$filters, 'direction' => 'desc']), 'totalMinor'))->toBe([10_000, 3_000, 500])
            ->and(array_column(SaleService::listSales([...$filters, 'direction' => 'asc']), 'totalMinor'))->toBe([500, 3_000, 10_000]);
    });

    it('sorts with a filter applied rather than sorting the whole table', function () {
        ($this->cokeSale)('2026-08-02', 20);
        ($this->riceCreditSale)('2026-08-03', $this->kofi, 100);

        $rows = SaleService::listSales([...$this->range, 'categoryId' => $this->drinks, 'sort' => 'amount', 'direction' => 'desc']);
        expect($rows)->toHaveCount(1)->and($rows[0]['totalMinor'])->toBe(10_000);
    });
});

describe('filtering is read-only', function () {
    it('changes no stock and no balances, whatever is asked for', function () {
        ($this->cokeSale)('2026-08-02', 5);
        ($this->riceCreditSale)('2026-08-03', $this->kofi, 2);

        $snapshot = fn (): array => [
            'stock' => DB::table('products')->orderBy('id')->get(['id', 'qty_on_hand_milli', 'stock_value_minor'])->map(fn ($r) => (array) $r)->all(),
            'sales' => DB::table('sales')->count(),
            'entries' => DB::table('journal_entries')->count(),
            'lines' => DB::table('journal_lines')->count(),
            'ledger' => DB::table('stock_ledger')->count(),
        ];
        $before = $snapshot();

        SaleService::listSales([...$this->range, 'categoryId' => $this->drinks, 'paymentState' => 'unpaid', 'search' => 'rice']);
        SaleService::countSales([...$this->range, 'customerId' => $this->kofi, 'minAmount' => 1]);
        SaleService::getFilteredSalesSummary([...$this->range, 'paymentKind' => 'MOBILE_MONEY']);
        SaleService::listSales([...$this->range, 'sort' => 'profit', 'direction' => 'asc', 'limit' => 1, 'offset' => 3]);

        expect($snapshot())->toBe($before);
    });
});

describe('invalid filter values', function () {
    it('treats an id that matches nothing as an empty result, not an error', function () {
        ($this->cokeSale)('2026-08-02', 1);

        expect(SaleService::countSales([...$this->range, 'customerId' => 99_999]))->toBe(0)
            ->and(SaleService::countSales([...$this->range, 'productId' => 99_999]))->toBe(0)
            ->and(SaleService::countSales([...$this->range, 'categoryId' => 99_999]))->toBe(0)
            ->and(SaleService::countSales([...$this->range, 'staffId' => 99_999]))->toBe(0);
    });

    it('survives a search term full of SQL punctuation', function () {
        ($this->cokeSale)('2026-08-02', 1);

        foreach (["'; DROP TABLE sales; --", '100%', '_', "O'Brien", '\\'] as $term) {
            SaleService::listSales([...$this->range, 'search' => $term]);
        }
        expect(SaleService::countSales($this->range))->toBe(1)
            ->and(DB::table('categories')->count())->toBeGreaterThan(0);
    });

    it('returns nothing for a range that ends before it starts', function () {
        ($this->cokeSale)('2026-08-02', 1);
        expect(SaleService::countSales(['from' => '2026-08-31', 'to' => '2026-08-01']))->toBe(0);
    });
});

// Ported from the amount cases of the old tests/lib/filters.test.ts, plus the
// three Phase 4 list parsers the page and its CSV share.
describe('the parsers', function () {
    it('parses an amount into minor units and shrugs off a typo', function () {
        expect(Filters::parseAmount('50'))->toBe(5_000)
            ->and(Filters::parseAmount('1,250.50'))->toBe(125_050)
            ->and(Filters::parseAmount('GHS 20'))->toBe(2_000)
            ->and(Filters::parseAmount('abc'))->toBeNull()
            ->and(Filters::parseAmount(''))->toBeNull()
            ->and(Filters::parseAmount(null))->toBeNull();
    });

    it('drops a negative bound rather than filtering everything away', function () {
        expect(Filters::parseAmount('-5'))->toBeNull()
            ->and(Filters::parseAmount('-1,250.50'))->toBeNull()
            ->and(Filters::parseAmount('0'))->toBe(0)
            ->and(Filters::parseAmountRange('-5', '100'))->toBe(['maxAmount' => 10_000])
            ->and(Filters::parseAmountRange('-10', '-5'))->toBe([]);
    });

    it('puts an amount range back in order when it is entered backwards', function () {
        expect(Filters::parseAmountRange('500', '100'))->toBe(['minAmount' => 10_000, 'maxAmount' => 50_000])
            ->and(Filters::parseAmountRange('100', null))->toBe(['minAmount' => 10_000])
            ->and(Filters::parseAmountRange(null, '100'))->toBe(['maxAmount' => 10_000])
            ->and(Filters::parseAmountRange(null, null))->toBe([]);
    });

    it('turns a sales query string into validated filters, junk narrowing nothing', function () {
        $parsed = ListFilters::parseSalesFilters([
            'period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-15', 'customer' => '7', 'product' => 'abc',
            'method' => 'MOBILE_MONEY', 'status' => 'DELETED', 'paid' => 'unpaid', 'q' => ' kofi ', 'min' => '500', 'max' => '100',
            'sort' => 'total_minor; DROP', 'page' => '2',
        ], '2026-08-15');

        expect($parsed['filters'])->toBe([
            'from' => '2026-08-01', 'to' => '2026-08-15', 'sort' => 'date', 'direction' => 'desc',
            'customerId' => 7, 'paymentKind' => 'MOBILE_MONEY', 'paymentState' => 'unpaid', 'search' => 'kofi',
            'minAmount' => 10_000, 'maxAmount' => 50_000,
        ])->and($parsed['page'])->toBe(2)
            ->and($parsed['carried'])->toHaveKey('status', 'DELETED');
    });

    it('reads expired only as 1 and whitelists quote and customer filters', function () {
        $quotes = ListFilters::parseQuotationFilters(['expired' => '0', 'status' => 'OPEN', 'sort' => 'validUntil'], '2026-08-15');
        expect($quotes['filters'])->not->toHaveKey('expired')
            ->and($quotes['filters']['status'])->toBe('OPEN')
            ->and($quotes['filters']['sort'])->toBe('validUntil')
            ->and(ListFilters::parseQuotationFilters(['expired' => '1'], '2026-08-15')['filters']['expired'])->toBeTrue();

        $customers = ListFilters::parseCustomerFilters(['balance' => 'owing', 'archived' => 'all', 'sort' => 'balance', 'direction' => 'sideways']);
        expect($customers['filters'])->toBe(['sort' => 'balance', 'direction' => 'asc', 'balanceState' => 'owing']);
    });
});
