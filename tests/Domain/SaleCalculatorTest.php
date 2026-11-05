<?php

declare(strict_types=1);

/*
| Ported from the old tests/domain/sale-calculate.test.ts and
| sale-present.test.ts.
*/

use App\Domain\Errors\ValidationError;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Domain\Sales\SaleCalculator;
use App\Domain\Tax\TaxComponent;
use App\Enums\TaxBasis;

/** A single 12.5% tax. */
function oneTax(int $rateBp = 1_250, TaxBasis $basis = TaxBasis::Net): array
{
    return [new TaxComponent('VAT', 'VAT', $rateBp, $basis, true)];
}

function line(int $productId, int|float $units, int $unitPrice, ?int $discount = null): array
{
    return ['productId' => $productId, 'qty' => Quantity::fromUnits((float) $units), 'unitPrice' => $unitPrice] + ($discount === null ? [] : ['discount' => $discount]);
}

describe('line totals', function () {
    it('multiplies quantity by price', function () {
        $r = SaleCalculator::calculate([line(1, 3, 600), line(2, 2, 300)]);
        expect([$r->lines[0]['gross'], $r->lines[1]['gross'], $r->subtotal, $r->total])->toBe([1_800, 600, 2_400, 2_400]);
    });

    it('handles fractional quantities', function () {
        // 2.5 kg of rice at GHS 19.00/kg = GHS 47.50
        expect(SaleCalculator::calculate([['productId' => 1, 'qty' => Quantity::parse('2.5'), 'unitPrice' => 1_900]])->total)->toBe(4_750);
    });

    it('applies a line discount', function () {
        $r = SaleCalculator::calculate([line(1, 10, 600, 500)]);
        expect([$r->lines[0]['gross'], $r->lines[0]['lineTotal'], $r->subtotal, $r->totalDiscount])->toBe([6_000, 5_500, 5_500, 500]);
    });

    it('rejects a line discount larger than the line', function () {
        SaleCalculator::calculate([line(1, 1, 100, 200)]);
    })->throws(ValidationError::class);

    it('rejects an empty sale, zero quantity and negative price', function () {
        expect(fn () => SaleCalculator::calculate([]))->toThrow(ValidationError::class)
            ->and(fn () => SaleCalculator::calculate([line(1, 0, 100)]))->toThrow(ValidationError::class)
            ->and(fn () => SaleCalculator::calculate([line(1, 1, -1)]))->toThrow(ValidationError::class);
    });
});

describe('invoice-level discount', function () {
    it('spreads across lines without losing a pesewa', function () {
        $r = SaleCalculator::calculate([line(1, 1, 1_000), line(2, 1, 1_000), line(3, 1, 1_000)], invoiceDiscount: 100);
        expect(Money::sum(array_column($r->lines, 'allocatedInvoiceDiscount')))->toBe(100)
            ->and(Money::sum(array_column($r->lines, 'netTotal')))->toBe($r->netBeforeTax)
            ->and($r->total)->toBe(2_900);
    });

    it('spreads in proportion to line value', function () {
        $r = SaleCalculator::calculate([line(1, 1, 7_500), line(2, 1, 2_500)], invoiceDiscount: 1_000);
        expect(array_column($r->lines, 'allocatedInvoiceDiscount'))->toBe([750, 250]);
    });

    it('conserves the discount across many awkward splits', function () {
        foreach ([1, 7, 33, 99, 101, 1_234] as $discount) {
            $r = SaleCalculator::calculate([line(1, 1, 1_111), line(2, 1, 2_222), line(3, 1, 3_333)], invoiceDiscount: $discount);
            expect(Money::sum(array_column($r->lines, 'allocatedInvoiceDiscount')))->toBe($discount)
                ->and(Money::sum(array_column($r->lines, 'netTotal')))->toBe($r->netBeforeTax);
        }
    });

    it('rejects a discount larger than the sale', function () {
        SaleCalculator::calculate([line(1, 1, 500)], invoiceDiscount: 600);
    })->throws(ValidationError::class);

    it('combines line and invoice discounts', function () {
        $r = SaleCalculator::calculate([line(1, 1, 1_000, 100)], invoiceDiscount: 50);
        expect([$r->subtotal, $r->totalDiscount, $r->total])->toBe([900, 150, 850]);
    });
});

describe('tax', function () {
    it('adds tax on top when prices exclude it', function () {
        $r = SaleCalculator::calculate([line(1, 1, 10_000)], taxComponents: oneTax());
        expect([$r->netBeforeTax, $r->tax, $r->total])->toBe([10_000, 1_250, 11_250]);
    });

    it('extracts tax from within when prices include it', function () {
        $r = SaleCalculator::calculate([line(1, 1, 11_250)], taxComponents: oneTax(), taxInclusive: true);
        expect([$r->total, $r->tax])->toBe([11_250, 1_250]);
    });

    it('is zero when tax is switched off', function () {
        $r = SaleCalculator::calculate([line(1, 1, 10_000)]);
        expect([$r->tax, $r->total])->toBe([0, 10_000]);
    });

    it('taxes the discounted amount, not the gross', function () {
        $r = SaleCalculator::calculate([line(1, 1, 10_000)], invoiceDiscount: 2_000, taxComponents: oneTax());
        expect([$r->netBeforeTax, $r->tax, $r->total])->toBe([8_000, 1_000, 9_000]);
    });
});

describe('tender, change and outstanding', function () {
    $pay = fn (int ...$amounts): array => array_map(fn (int $a, int $i): array => ['paymentAccountId' => $i + 1, 'amount' => $a], $amounts, array_keys($amounts));

    it('handles exact, over, under, none and split tenders', function () use ($pay) {
        expect(SaleCalculator::tender(4_700, $pay(4_700)))->toBe(['tendered' => 4_700, 'applied' => 4_700, 'change' => 0, 'outstanding' => 0])
            // Over-tendering gives change, never extra revenue.
            ->and(SaleCalculator::tender(4_700, $pay(5_000)))->toBe(['tendered' => 5_000, 'applied' => 4_700, 'change' => 300, 'outstanding' => 0])
            ->and(SaleCalculator::tender(4_700, $pay(2_000)))->toBe(['tendered' => 2_000, 'applied' => 2_000, 'change' => 0, 'outstanding' => 2_700])
            ->and(SaleCalculator::tender(4_700, []))->toBe(['tendered' => 0, 'applied' => 0, 'change' => 0, 'outstanding' => 4_700])
            ->and(SaleCalculator::tender(4_700, $pay(2_000, 2_700)))->toBe(['tendered' => 4_700, 'applied' => 4_700, 'change' => 0, 'outstanding' => 0])
            ->and(SaleCalculator::tender(4_700, $pay(2_000, 4_000)))->toBe(['tendered' => 6_000, 'applied' => 4_700, 'change' => 1_300, 'outstanding' => 0]);
    });

    it('can refuse over-tendering, and refuses a negative payment', function () use ($pay) {
        expect(fn () => SaleCalculator::tender(4_700, $pay(5_000), allowChange: false))->toThrow(ValidationError::class)
            ->and(fn () => SaleCalculator::tender(4_700, $pay(-1)))->toThrow(ValidationError::class);
    });
});

describe('profit', function () {
    it('is revenue less cost, and can be negative', function () {
        expect(SaleCalculator::grossProfit(50_000, 32_000))->toBe(18_000)
            ->and(SaleCalculator::grossProfit(1_000, 1_500))->toBe(-500);
    });

    it('reports margin in basis points, or null when there is no revenue', function () {
        expect(SaleCalculator::marginBp(50_000, 32_000))->toBe(3_600)
            ->and(SaleCalculator::marginBp(1_000, 1_000))->toBe(0)
            ->and(SaleCalculator::marginBp(0, 0))->toBeNull();
    });

    it('rounds a half basis point the way the old screens did', function () {
        // 1/20000 = 0.5 bp: Math.round gives 1; -0.5 bp gives 0 (towards +∞), not -1.
        expect(SaleCalculator::marginBp(20_000, 19_999))->toBe(1)
            ->and(SaleCalculator::marginBp(20_000, 20_001))->toBe(0);
    });

    it('is net of tax, and shares earnings across lines to the pesewa', function () {
        expect(SaleCalculator::saleGrossProfit(11_250, 1_250, 6_000))->toBe(4_000);
        $earnings = SaleCalculator::lineEarnings(11_250, 1_250, [7_500, 3_750]);
        expect(Money::sum($earnings))->toBe(10_000);
    });
});

describe('credit limits', function () {
    it('reports headroom, treating no limit as unlimited and zero as no credit', function () {
        expect(SaleCalculator::creditHeadroom(50_000, 20_000))->toBe(30_000)
            ->and(SaleCalculator::creditHeadroom(50_000, 60_000))->toBe(0)
            ->and(SaleCalculator::creditHeadroom(null, 99_999))->toBeNull()
            ->and(SaleCalculator::exceedsCreditLimit(null, 99_999, 50_000))->toBeFalse()
            ->and(SaleCalculator::exceedsCreditLimit(0, 0, 1))->toBeTrue();
    });

    it('detects when a new credit sale would breach the limit; exactly at it is allowed', function () {
        expect(SaleCalculator::exceedsCreditLimit(50_000, 30_000, 10_000))->toBeFalse()
            ->and(SaleCalculator::exceedsCreditLimit(50_000, 30_000, 25_000))->toBeTrue()
            ->and(SaleCalculator::exceedsCreditLimit(50_000, 30_000, 20_000))->toBeFalse();
    });
});

it('caps a refund at what leaves the customer in credit', function () {
    expect(SaleCalculator::refundableOnReturn(5_000, 0))->toBe(5_000)
        ->and(SaleCalculator::refundableOnReturn(5_000, 2_000))->toBe(3_000)
        ->and(SaleCalculator::refundableOnReturn(5_000, 9_000))->toBe(0);
});

/*
| Printing: what the customer reads has to agree with itself.
*/

/** Price a sale, store it the way the service does, print it. */
function priceAndPrint(array $lines, int $invoiceDiscount, array $taxComponents, bool $taxInclusive): array
{
    $t = SaleCalculator::calculate($lines, $invoiceDiscount, $taxComponents, $taxInclusive);
    $stored = ['subtotal' => $t->subtotalExTax, 'discount' => $t->invoiceDiscountExTax, 'tax' => $t->tax, 'total' => $t->total];
    $printed = SaleCalculator::documentTotals($stored['subtotal'], $stored['discount'], $stored['tax'], $stored['total'], $taxInclusive, array_column($t->lines, 'lineTotal'));

    return [$stored, $printed];
}

describe('printing a tax-inclusive sale', function () {
    it('shows a subtotal that matches the printed lines', function () {
        [, $printed] = priceAndPrint([line(1, 2, 11_250)], 0, oneTax(), true);
        expect($printed)->toBe(['subtotal' => 22_500, 'discount' => 0, 'tax' => 2_500, 'total' => 22_500, 'taxWithinTotal' => true]);
    });

    it('shows the discount the customer was actually given', function () {
        // GHS 22.50 was taken off at the counter, not the GHS 20.00 that reaches revenue.
        [, $printed] = priceAndPrint([line(1, 2, 11_250)], 2_250, oneTax(), true);
        expect([$printed['discount'], $printed['subtotal'], $printed['total']])->toBe([2_250, 22_500, 20_250]);
    });

    it('adds up on the page: subtotal − discount = total', function () {
        [, $printed] = priceAndPrint([line(1, 3, 11_250, 1_000)], 1_337, oneTax(), true);
        expect($printed['subtotal'] - $printed['discount'])->toBe($printed['total']);
    });
});

it('prints a tax-exclusive sale straight from the stored columns', function () {
    [$stored, $printed] = priceAndPrint([line(1, 1, 10_000)], 0, oneTax(), false);
    expect($printed)->toBe(['subtotal' => 10_000, 'discount' => 0, 'tax' => 1_250, 'total' => 11_250, 'taxWithinTotal' => false])
        ->and([$printed['subtotal'], $printed['discount']])->toBe([$stored['subtotal'], $stored['discount']]);
});

it('keeps subtotal − discount + tax = total in storage, whatever the pricing', function (array $taxComponents, bool $taxInclusive) {
    [$stored] = priceAndPrint([line(1, 3, 3_333, 777), line(2, 7, 1_111)], 1_234, $taxComponents, $taxInclusive);
    expect($stored['subtotal'] - $stored['discount'] + $stored['tax'])->toBe($stored['total']);
})->with([
    'no tax' => [[], false],
    'tax added on' => [oneTax(), false],
    'tax within the price' => [oneTax(), true],
    'an awkward rate, within the price' => [oneTax(733), true],
    "Ghana's three, added on" => [[new TaxComponent('NHIL', 'NHIL', 250), new TaxComponent('GETFUND', 'GETFund', 250), new TaxComponent('VAT', 'VAT', 1_500, TaxBasis::NetPlusLevies, true)], false],
    "Ghana's three, within the price" => [[new TaxComponent('NHIL', 'NHIL', 250), new TaxComponent('GETFUND', 'GETFund', 250), new TaxComponent('VAT', 'VAT', 1_500, TaxBasis::NetPlusLevies, true)], true],
]);
