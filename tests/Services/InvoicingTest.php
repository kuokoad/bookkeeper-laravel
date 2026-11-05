<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/invoicing.test.ts: when a sale becomes an
| invoice, and the terms and due date it carries.
*/

use App\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const INVOICING_TODAY = '2026-08-17';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->product = Shop::product($this->actor, 'Rice', 1_400, 1_900, ['unit' => 'kg']);
    Shop::openingStock($this->actor, $this->product, 500_000, 700_000, INVOICING_TODAY);
    // A sale of 500, paid $paid, on $date; returns the stored row.
    $this->sell = fn (string $date, int $paid, ?int $customerId = null, ?int $termsDays = null): object => DB::table('sales')->where('id', SaleService::createSale([
        'businessDate' => $date,
        ...($customerId !== null ? ['customerId' => $customerId] : []),
        ...($termsDays !== null ? ['termsDays' => $termsDays] : []),
        'items' => [['productId' => $this->product, 'unitPrice' => 50_000, 'qty' => 1_000]],
        'tenders' => $paid > 0 ? [['paymentAccountId' => $this->cash, 'amount' => $paid]] : [],
        'allowPriceOverride' => true,
    ], $this->actor)['saleId'])->first();
});

describe('when a sale becomes an invoice', function () {
    it('gives a credit sale an invoice number and a due date', function () {
        $row = ($this->sell)(INVOICING_TODAY, 20_000, Shop::customer($this->actor, 'Ama Serwaa'));

        expect($row->invoice_no)->toMatch('/^INV-\d{5}$/')
            ->and($row->terms_days)->toBe(30)
            ->and($row->due_date)->toBe('2026-09-16');
    });

    it('gives a sale paid in full NO invoice', function () {
        $row = ($this->sell)(INVOICING_TODAY, 50_000, Shop::customer($this->actor, 'Ama Serwaa'));

        expect($row->invoice_no)->toBeNull()->and($row->due_date)->toBeNull()->and($row->terms_days)->toBeNull();
    });

    it('gives a walk-in credit-free sale no invoice either', function () {
        expect(($this->sell)(INVOICING_TODAY, 50_000)->invoice_no)->toBeNull();
    });

    it('numbers invoices in their own sequence, not the receipt one', function () {
        $customerId = Shop::customer($this->actor, 'Ama');
        ($this->sell)(INVOICING_TODAY, 50_000, $customerId);
        $first = ($this->sell)(INVOICING_TODAY, 0, $customerId);
        ($this->sell)(INVOICING_TODAY, 50_000, $customerId);
        $second = ($this->sell)(INVOICING_TODAY, 0, $customerId);

        expect($first->invoice_no)->toBe('INV-00001')->and($second->invoice_no)->toBe('INV-00002');
    });
});

describe('payment terms', function () {
    it('takes the shop default', function () {
        expect(($this->sell)(INVOICING_TODAY, 0, Shop::customer($this->actor, 'Ama'))->due_date)->toBe('2026-09-16');
    });

    it('can be overridden on the sale', function () {
        expect(($this->sell)(INVOICING_TODAY, 0, Shop::customer($this->actor, 'Ama'), 7)->due_date)->toBe('2026-08-24');
    });

    it('due on receipt means the same day', function () {
        expect(($this->sell)(INVOICING_TODAY, 0, Shop::customer($this->actor, 'Ama'), 0)->due_date)->toBe(INVOICING_TODAY);
    });

    it('follows a changed shop default for NEW invoices only', function () {
        $customerId = Shop::customer($this->actor, 'Ama');
        $before = ($this->sell)(INVOICING_TODAY, 0, $customerId);
        Shop::setting('default_terms_days', 7);
        $after = ($this->sell)(INVOICING_TODAY, 0, $customerId);

        expect(DB::table('sales')->where('id', $before->id)->value('due_date'))->toBe('2026-09-16')
            ->and($after->due_date)->toBe('2026-08-24');
    });

    it('crosses a month end correctly', function () {
        expect(($this->sell)('2026-01-31', 0, Shop::customer($this->actor, 'Ama'))->due_date)->toBe('2026-03-02');
    });
});
