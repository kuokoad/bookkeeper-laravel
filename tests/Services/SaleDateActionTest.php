<?php

declare(strict_types=1);

/*
| Ported from the old tests/app/sale-date-action.test.ts, the service-level
| part. The old createSaleAction decided three things before calling
| createSale: "today" is the server's clock; SaleDate::assertAllowed with
| mayBackdate = sales:edit; and a backdated sale passes expiryAsAt = today and
| arrivedBy = its own date. $this->sellOn makes exactly those decisions, so the
| same rules are proved against the same services.
|
| Deferred to the UI phase (Phase 8, routes and actions): reading the cart
| from the form, the permission lookup, and the action's { error } / { expired }
| response shapes. Here a refusal is the DomainError's userMessage and the
| expired case is the ExpiredStockError plus the till's mayOverride flag.
*/

use App\Domain\Errors\DomainError;
use App\Domain\Errors\ExpiredStockError;
use App\Domain\Sales\SaleDate;
use App\Services\SaleService;
use App\Services\SettingsService;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const SALEDATE_TODAY = '2026-08-16';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-16 10:30:00', config('nuna.business_timezone')));
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->milo = Shop::product($this->actor, 'Milo 400g', 500, 800);
    Shop::openingStock($this->actor, $this->milo, 10_000, 5_000, '2026-08-01');

    // Owner: may backdate and sell expired. Manager (sales:edit): may backdate only. Cashier: neither.
    $this->roles = ['OWNER' => [true, true], 'MANAGER' => [true, false], 'CASHIER' => [false, false]];
    $this->carts = 0;

    /** What createSaleAction did with one Milo paid in cash. @return array{error?: string, expired?: array} */
    $this->sellOn = function (?string $businessDate = null, ?int $productId = null, string $role = 'OWNER'): array {
        [$mayBackdate, $maySellExpired] = $this->roles[$role];
        $today = Clock::today();
        $date = $businessDate ?? $today;
        try {
            SaleDate::assertAllowed($date, $today, SettingsService::get()->books_locked_before, $mayBackdate);
            SaleService::createSale([
                'clientRef' => 'test-cart-'.$this->carts++,
                'businessDate' => $date,
                'customerId' => null,
                'items' => [['productId' => $productId ?? $this->milo, 'qty' => 1_000, 'unitPrice' => 800]],
                'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 800]],
                'allowPriceOverride' => $mayBackdate,
                'allowExpiredStock' => false,
                ...($date !== $today ? ['expiryAsAt' => $today, 'arrivedBy' => $date] : []),
            ], $this->actor);

            return [];
        } catch (ExpiredStockError $e) {
            return ['expired' => ['mayOverride' => $maySellExpired]];
        } catch (DomainError $e) {
            return ['error' => $e->userMessage];
        }
    };
    $this->dates = fn (): array => DB::table('sales')->orderBy('id')->pluck('business_date')->all();
    $this->delivery = fn (int $productId, string $date, string $expiry): array => Shop::move([
        'productId' => $productId,
        'direction' => 'IN',
        'qty' => 5_000,
        'totalCost' => 2_500,
        'movementType' => 'PURCHASE',
        'sourceType' => 'TEST',
        'businessDate' => $date,
        'occurredAt' => Carbon::parse("{$date} 08:00:00", config('nuna.business_timezone')),
        'batch' => ['kind' => 'NEW', 'expiryDate' => $expiry],
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('a sale dated today', function () {
    it('goes through for a cashier', function () {
        expect(($this->sellOn)(SALEDATE_TODAY, null, 'CASHIER'))->not->toHaveKey('error')
            ->and(($this->dates)())->toBe([SALEDATE_TODAY]);
    });
});

describe('a sale dated on an earlier day', function () {
    it('is refused for a cashier, and nothing is recorded', function () {
        expect(($this->sellOn)('2026-08-14', null, 'CASHIER')['error'])->toContain('Only an owner or manager')
            ->and(($this->dates)())->toBe([]);
    });

    it('lands on the chosen day in the books when a manager records it', function () {
        expect(($this->sellOn)('2026-08-14', null, 'MANAGER'))->not->toHaveKey('error');

        $sale = DB::table('sales')->first();
        expect($sale->business_date)->toBe('2026-08-14')
            ->and((int) $sale->total_minor)->toBe(800)
            ->and(DB::table('journal_entries')->where('id', $sale->journal_entry_id)->value('entry_date'))->toBe('2026-08-14');
    });

    it('goes through for the owner', function () {
        expect(($this->sellOn)('2026-08-14'))->not->toHaveKey('error')
            ->and(($this->dates)())->toBe(['2026-08-14']);
    });

    it('is refused inside the books lock, even for the owner', function () {
        Shop::setting('books_locked_before', '2026-08-14');

        expect(($this->sellOn)('2026-08-14')['error'])->toContain('books are closed')
            ->and(($this->dates)())->toBe([])
            ->and(($this->sellOn)('2026-08-15'))->not->toHaveKey('error')
            ->and(($this->dates)())->toBe(['2026-08-15']);
    });
});

describe('a sale dated in the future', function () {
    it('is refused, even for the owner', function () {
        expect(($this->sellOn)('2026-08-17')['error'])->toContain('future')
            ->and(($this->dates)())->toBe([]);
    });
});

describe('a till left open overnight', function () {
    it('records a cashier’s sale on the day it is rung up', function () {
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:15:00', config('nuna.business_timezone')));

        expect(($this->sellOn)(null, null, 'CASHIER'))->not->toHaveKey('error')
            ->and(($this->dates)())->toBe(['2026-08-17']);
    });

    it('records an owner’s sale on the day it is rung up, not the day the page opened', function () {
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:15:00', config('nuna.business_timezone')));
        ($this->sellOn)();

        expect(($this->dates)())->toBe(['2026-08-17']);
    });
});

describe('backdating and expired stock', function () {
    it('still stops a manager without the right to sell expired stock', function () {
        $milk = Shop::product($this->actor, 'Fresh Milk 1L', 500, 800);
        ($this->delivery)($milk, '2026-08-01', '2026-08-15');

        // Dated before the crate expired, it would look like good stock.
        $state = ($this->sellOn)('2026-08-14', $milk, 'MANAGER');

        expect($state['expired']['mayOverride'] ?? null)->toBeFalse()
            ->and(($this->dates)())->toBe([]);
    });
});

describe('the audit trail', function () {
    it('says when a sale was put on an earlier day', function () {
        ($this->sellOn)('2026-08-14');
        $sale = DB::table('sales')->first();
        $entry = DB::table('audit_logs')->where('entity_id', (string) $sale->id)->where('entity_type', 'sale')->where('action', 'CREATE')->first();

        expect($entry->summary)->toContain('dated 14 Aug 2026')
            ->and(json_decode($entry->metadata, true)['businessDate'])->toBe('2026-08-14');
    });

    it('says nothing extra about an ordinary sale', function () {
        ($this->sellOn)();
        $sale = DB::table('sales')->first();
        $entry = DB::table('audit_logs')->where('entity_id', (string) $sale->id)->where('entity_type', 'sale')->where('action', 'CREATE')->first();

        expect($entry->summary)->not->toContain('dated');
    });
});

describe('backdating and later deliveries', function () {
    it('takes stock that was on the shelf that day, not a delivery that came after', function () {
        ($this->delivery)($this->milo, '2026-08-15', '2026-12-31');

        ($this->sellOn)('2026-08-14');

        expect((int) DB::table('product_batches')->where('product_id', $this->milo)->where('received_date', '2026-08-15')->value('qty_milli'))->toBe(5_000);
    });
});
