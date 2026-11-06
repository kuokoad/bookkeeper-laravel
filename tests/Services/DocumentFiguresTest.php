<?php

declare(strict_types=1);

/*
| From the old tests/app/document-figures.test.ts, the service-level half: a
| sale document carries its trading date apart from when the row was written,
| and the year-end pack's trial balance carries its own totals and verdict
| beside the whole-ledger check.
|
| Deferred to Phase 9 (the screens): the old file pinned the page sources
| (the receipt and invoice print formatDate(sale.businessDate), never
| occurredAt; the year-end page quotes pack.trialBalance and names the
| whole-ledger check "Every entry ever posted ... gross debits").
*/

use App\Services\Reporting\YearEndService;
use App\Services\SaleService;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Tests\Support\Shop;

it("gives a back-dated sale's document the trading date, not the day it was written", function () {
    Carbon::setTestNow('2026-08-17 09:00:00');
    $actor = Shop::owner();
    $id = Shop::product($actor, 'Milo', 500, 800);
    Shop::openingStock($actor, $id, 5_000, 2_500, '2026-06-01');
    $sale = Shop::sell($actor, [[$id, 1_000]], [['paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => 800]], ['businessDate' => '2026-06-20']);

    $document = SaleService::getSale($sale['saleId']);
    expect((string) $document['businessDate'])->toBe('2026-06-20')
        ->and(Clock::dateOf(Clock::fromStamp((string) $document['occurredAt'])))->toBe('2026-08-17');
    Carbon::setTestNow();
});

it('gives the year-end pack a trial balance with its own totals and verdict, apart from the whole-ledger check', function () {
    $pack = YearEndService::getYearEndPack(2026);
    expect($pack['trialBalance'])->toHaveKeys(['totalDebit', 'totalCredit', 'balanced'])
        ->and($pack['integrity'])->toHaveKeys(['trialBalanced', 'totalDebit', 'totalCredit']);
});
