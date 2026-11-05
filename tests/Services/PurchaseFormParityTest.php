<?php

declare(strict_types=1);

/*
| Ported from the old tests/app/purchase-form-parity.test.ts, the service half.
| The old test put every entry through the new-purchase form's reading and
| through the real Save, and required them to agree. The form is a screen
| (Phase 8), so here each entry goes through createPurchase with the values
| the form's parsers produce, and the outcome the old Save gave is pinned:
| whether it saves, and the stored subtotal, total and what is still owed.
|
| Left for the screens (Phase 8), because the old action's parsers refused
| them before the service saw them: an empty cost box, a comma as a decimal
| point, a date that does not exist, and the invoice-number and reference
| length limits (60 and 80). "Proper thousands grouping" is here as the value
| it parses to (1,250.00 = 125_000).
*/

use App\Domain\Errors\DomainError;
use App\Services\PurchaseService;
use App\Services\UnitsService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const PFP_TODAY = '2026-08-17';

beforeEach(function () {
    $this->actor = Shop::owner();
    UnitsService::createUnit(['symbol' => 'pc', 'name' => 'Piece', 'decimalPlaces' => 0], $this->actor);
    // A carton allows one decimal place: half a carton is fine, a tenth (2.4 pieces) is not.
    UnitsService::createUnit(['symbol' => 'carton', 'name' => 'Carton', 'decimalPlaces' => 1], $this->actor);
    UnitsService::createUnit(['symbol' => 'trip', 'name' => 'Trip', 'decimalPlaces' => 0], $this->actor);
    $this->products = [
        'soap' => Shop::product($this->actor, 'Bath soap', 300, 400, ['unit' => 'pc', 'altUnit' => 'carton', 'altFactor' => 24_000]),
        // A service: bought, never stocked, so it takes the other road through Save.
        'cartage' => Shop::product($this->actor, 'Cartage to site', 5_000, 0, ['unit' => 'trip', 'trackInventory' => false]),
    ];
    $this->supplier = Shop::supplier($this->actor, 'Kofi Wholesale');
    $this->cash = (int) DB::table('payment_accounts')->orderBy('id')->value('id');
});

/** What Save stored, or null when it refused. */
function pfpSave(object $test, array $case): ?array
{
    if ($case['ghanaTaxes'] ?? false) {
        Shop::setGhanaTaxes();
    }
    if (isset($case['lockedBefore'])) {
        Shop::setting('books_locked_before', $case['lockedBefore']);
    }
    $items = array_map(fn (array $line): array => [
        'productId' => $test->products[$line['product'] ?? 'soap'],
        'qty' => $line['qty'],
        'unitCost' => $line['cost'],
        ...(isset($line['discount']) ? ['discount' => $line['discount']] : []),
        ...(($line['alt'] ?? false) ? ['alt' => true, 'altFactor' => 24_000] : []),
    ], $case['lines']);

    try {
        $created = PurchaseService::createPurchase([
            'supplierId' => $test->supplier,
            'businessDate' => $case['date'] ?? PFP_TODAY,
            'invoiceNo' => $case['invoiceNo'] ?? null,
            'items' => $items,
            'invoiceDiscount' => $case['invoiceDiscount'] ?? 0,
            'tenders' => isset($case['pay']) ? [['paymentAccountId' => $test->cash, 'amount' => $case['pay']]] : [],
        ], $test->actor);
    } catch (DomainError) {
        return null;
    }
    $row = DB::table('purchases')->where('id', $created['purchaseId'])->first();
    $paid = (int) DB::table('purchase_payments')->where('purchase_id', $created['purchaseId'])->sum('amount_minor');

    return ['subtotal' => (int) $row->subtotal_minor, 'total' => (int) $row->total_minor, 'outstanding' => (int) $row->total_minor - $paid];
}

// [case, stored figures or null when Save refused]. Quantities in milli-units, money in pesewas.
dataset('purchase entries', [
    'a plain line' => [['lines' => [['qty' => 2_000, 'cost' => 300]]], [600, 600, 600]],
    'a line discount' => [['lines' => [['qty' => 2_000, 'cost' => 300, 'discount' => 150]]], [450, 450, 450]],
    'a line discount larger than the line' => [['lines' => [['qty' => 2_000, 'cost' => 300, 'discount' => 700]]], null],
    'a negative line discount' => [['lines' => [['qty' => 2_000, 'cost' => 300, 'discount' => -100]]], null],
    'a discount of the whole line, alone' => [['lines' => [['qty' => 2_000, 'cost' => 300, 'discount' => 600]]], [0, 0, 0]],
    'free goods alone' => [['lines' => [['qty' => 2_000, 'cost' => 0]]], [0, 0, 0]],
    'a free service alone' => [['lines' => [['product' => 'cartage', 'qty' => 1_000, 'cost' => 0]]], [0, 0, 0]],
    'free goods beside paid ones' => [['lines' => [['qty' => 2_000, 'cost' => 0], ['qty' => 1_000, 'cost' => 300]]], [300, 300, 300]],
    'a zero quantity' => [['lines' => [['qty' => 0, 'cost' => 300]]], null],
    'a quantity finer than the unit' => [['lines' => [['qty' => 1_500, 'cost' => 300]]], null],
    'half a carton' => [['lines' => [['qty' => 500, 'cost' => 7_200, 'alt' => true]]], [3_600, 3_600, 3_600]],
    'a tenth of a carton, which is 2.4 pieces' => [['lines' => [['qty' => 100, 'cost' => 7_200, 'alt' => true]]], null],
    'too many places for a carton' => [['lines' => [['qty' => 1_250, 'cost' => 7_200, 'alt' => true]]], null],
    'proper thousands grouping' => [['lines' => [['qty' => 2_000, 'cost' => 125_000]]], [250_000, 250_000, 250_000]],
    'a service bought on its own' => [['lines' => [['product' => 'cartage', 'qty' => 1_000, 'cost' => 5_000]]], [5_000, 5_000, 5_000]],
    'a free service beside paid goods' => [['lines' => [['product' => 'cartage', 'qty' => 1_000, 'cost' => 0], ['qty' => 2_000, 'cost' => 300]]], [600, 600, 600]],
    'an invoice discount' => [['lines' => [['qty' => 2_000, 'cost' => 300]], 'invoiceDiscount' => 100], [600, 500, 500]],
    'an invoice discount larger than the lines' => [['lines' => [['qty' => 2_000, 'cost' => 300]], 'invoiceDiscount' => 601], null],
    'an invoice discount of the whole purchase' => [['lines' => [['qty' => 2_000, 'cost' => 300]], 'invoiceDiscount' => 600], [600, 0, 0]],
    'part paid' => [['lines' => [['qty' => 2_000, 'cost' => 300]], 'pay' => 200], [600, 600, 400]],
    'paying all of it' => [['lines' => [['qty' => 2_000, 'cost' => 300]], 'pay' => 600], [600, 600, 0]],
    'paying more than the total' => [['lines' => [['qty' => 2_000, 'cost' => 300]], 'pay' => 601], null],
    'the Ghana taxes' => [['lines' => [['qty' => 10_000, 'cost' => 8_600]], 'ghanaTaxes' => true], [86_000, 103_200, 103_200]],
    'paying all of it with tax on top' => [['lines' => [['qty' => 10_000, 'cost' => 8_600]], 'pay' => 103_200, 'ghanaTaxes' => true], [86_000, 103_200, 0]],
    'taxed, then discounted to nothing' => [['lines' => [['qty' => 2_000, 'cost' => 300]], 'invoiceDiscount' => 600, 'ghanaTaxes' => true], [600, 0, 0]],
    'a free service inside the books lock' => [['lines' => [['product' => 'cartage', 'qty' => 1_000, 'cost' => 0]], 'lockedBefore' => '2026-08-31'], null],
    'a date inside the books lock' => [['lines' => [['qty' => 2_000, 'cost' => 300]], 'lockedBefore' => '2026-08-31'], null],
    'the lock date itself' => [['lines' => [['qty' => 2_000, 'cost' => 300]], 'date' => '2026-08-31', 'lockedBefore' => '2026-08-31'], null],
    'the day after the books lock' => [['lines' => [['qty' => 2_000, 'cost' => 300]], 'date' => '2026-09-01', 'lockedBefore' => '2026-08-31'], [600, 600, 600]],
    'an invoice number at the limit' => [['lines' => [['qty' => 2_000, 'cost' => 300]], 'invoiceNo' => str_repeat('N', 60)], [600, 600, 600]],
]);

describe('what Save stores for each entry the form allows', function () {
    it('stores exactly the old figures, or refuses as the old Save did', function (array $case, ?array $expected) {
        $stored = pfpSave($this, $case);

        expect($stored === null ? null : [$stored['subtotal'], $stored['total'], $stored['outstanding']])->toBe($expected);
        if ($stored === null) {
            expect(DB::table('purchases')->count())->toBe(0, 'a refusal leaves nothing behind');
        } else {
            Shop::assertBooksHealthy('saved');
        }
    })->with('purchase entries');
});
