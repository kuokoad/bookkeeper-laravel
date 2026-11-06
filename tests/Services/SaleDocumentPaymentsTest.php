<?php

declare(strict_types=1);

/* The payment lines a receipt and an invoice print (old sales/[id]/receipt and invoice pages). */

use App\Services\SaleService;
use Tests\Support\Shop;

function documentSale(int $total, array $tenders, int $outstanding, int $credited = 0): array
{
    return [
        'totalMinor' => $total,
        'tenders' => array_map(fn (int $amount): array => ['amountMinor' => $amount], $tenders),
        'outstandingMinor' => $outstanding,
        'creditedMinor' => $credited,
    ];
}

it('prints a sale paid in full at the till', function () {
    expect(SaleService::documentPayments(documentSale(2_000, [2_000], 0)))
        ->toBe(['change' => 0, 'alreadyPaid' => 2_000, 'amountDue' => 0, 'inCredit' => 0, 'settled' => true]);
});

it('prints a part-paid sale with what is still due', function () {
    // GHS 50.00 sale, GHS 20.00 at the till, GHS 30.00 on account.
    expect(SaleService::documentPayments(documentSale(5_000, [2_000], 3_000)))
        ->toBe(['change' => 0, 'alreadyPaid' => 2_000, 'amountDue' => 3_000, 'inCredit' => 0, 'settled' => false]);
    // A GHS 10.00 return credited to the account is not money received.
    expect(SaleService::documentPayments(documentSale(5_000, [2_000], 2_000, 1_000)))
        ->toMatchArray(['alreadyPaid' => 2_000, 'amountDue' => 2_000]);
});

it('prints an overpaid sale as credit in hand, not as money due', function () {
    // Paid GHS 20.00 in full, then GHS 10.00 of it came back on account: GHS 10.00 in credit.
    expect(SaleService::documentPayments(documentSale(2_000, [2_000], -1_000, 1_000)))
        ->toBe(['change' => 0, 'alreadyPaid' => 2_000, 'amountDue' => 0, 'inCredit' => 1_000, 'settled' => true]);
});

it('reads the same figures from real sales: cash in full, and part paid on credit', function () {
    $actor = Shop::owner();
    $cash = Shop::paymentAccount('CASH');
    $rice = Shop::product($actor, 'Rice 5kg', 500, 1_000);
    Shop::openingStock($actor, $rice, 10_000, 5_000);
    $customer = Shop::customer($actor);

    // GHS 50.00 handed over for GHS 20.00: the till keeps GHS 20.00 and gives the rest back.
    $paid = Shop::sell($actor, [[$rice, 2_000]], [['paymentAccountId' => $cash, 'amount' => 5_000]]);
    expect($paid['change'])->toBe(3_000)
        ->and(SaleService::documentPayments(SaleService::getSale($paid['saleId'])))->toMatchArray(['alreadyPaid' => 2_000, 'amountDue' => 0, 'settled' => true]);

    $part = Shop::sell($actor, [[$rice, 3_000]], [['paymentAccountId' => $cash, 'amount' => 1_000]], ['customerId' => $customer]);
    expect(SaleService::documentPayments(SaleService::getSale($part['saleId'])))
        ->toBe(['change' => 0, 'alreadyPaid' => 1_000, 'amountDue' => 2_000, 'inCredit' => 0, 'settled' => false]);
});
