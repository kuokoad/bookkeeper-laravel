<?php

declare(strict_types=1);

/*
| New: every list export and every report export builds through the same
| parsers and reads as its page, with the old route's headers, on a shop
| with some trading in it. An unknown name is null (the controller's 404).
*/

use App\Reports\Csv\Csv;
use App\Reports\Csv\ListExport;
use App\Reports\Csv\ReportExport;
use App\Services\PaymentAccountService;
use Tests\Support\Shop;

const CSVX_TODAY = '2026-08-31';

beforeEach(function () {
    $this->actor = $actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $rice = Shop::product($actor, 'Rice 5kg', 4_000, 6_000);
    Shop::openingStock($actor, $rice, 10_000, 40_000, '2026-08-01');
    Shop::sell($actor, [[$rice, 2_000]], [['paymentAccountId' => $this->cash, 'amount' => 12_000]], ['businessDate' => '2026-08-10']);
    Shop::purchase($actor, Shop::supplier($actor), [[$rice, 5_000, 4_000]], [], ['businessDate' => '2026-08-12']);
    Shop::expense($actor, '2026-08-14', PaymentAccountService::listExpenseCategories()[0]['id'], 500);
});

it('builds every list with the old headers', function (string $list, string $firstHeader) {
    $query = ['period' => 'month', ...($list === 'account' ? ['id' => (string) $this->cash] : [])];
    $file = ListExport::download($list, $query, CSVX_TODAY);

    expect($file)->not->toBeNull()
        ->and($file['headers'][0])->toBe($firstHeader)
        ->and($file['body'])->toStartWith("\u{FEFF}".Csv::escapeField($firstHeader))
        ->and($file['filename'])->toBe('bookkeeper_'.$list.'_'.CSVX_TODAY.'.csv');
})->with([
    ['sales', 'Receipt'], ['purchases', 'Purchase'], ['expenses', 'Number'], ['income', 'Number'], ['products', 'Product'],
    ['stock-movements', 'Date'], ['customers', 'Customer'], ['suppliers', 'Supplier'], ['account', 'Date'], ['quotations', 'Quote'],
]);

it('builds every report with the old headers', function (string $report, string $firstHeader) {
    $file = ReportExport::download($report, ['from' => '2026-08-01', 'to' => CSVX_TODAY, 'asAt' => CSVX_TODAY, 'year' => '2026'], CSVX_TODAY);

    expect($file)->not->toBeNull()
        ->and($file['headers'][0])->toBe($firstHeader)
        ->and($file['filename'])->toBe("bookkeeper_{$report}_2026-08-01_".CSVX_TODAY.'.csv');
})->with([
    ['profit-and-loss', 'Item'], ['balance-sheet', 'Item'], ['cash-flow', 'Item'], ['sales', 'Section'], ['purchases', 'Section'],
    ['inventory', 'Product'], ['tax', 'Tax'], ['expiry', 'Batch'], ['receivables', 'Customer'], ['payables', 'Supplier'], ['year-end', 'Item'],
]);

it('puts the same figures in the P&L file as the report', function () {
    $rows = ReportExport::download('profit-and-loss', ['from' => '2026-08-01', 'to' => CSVX_TODAY], CSVX_TODAY)['rows'];
    expect(array_column($rows, 1, 0))->toMatchArray(['Sales' => '120.00', 'Cost of goods sold' => '80.00', 'Gross profit' => '40.00', 'Net profit' => '35.00']);
});

it('falls back to this month so far when the dates are junk', function () {
    expect(ReportExport::period(['from' => 'yesterday', 'to' => '2026-02-30'], CSVX_TODAY))->toBe(['from' => '2026-08-01', 'to' => CSVX_TODAY]);
});

it('knows nothing of an unknown export', function () {
    expect(ListExport::download('passwords', [], CSVX_TODAY))->toBeNull()
        ->and(ReportExport::download('passwords', [], CSVX_TODAY))->toBeNull();
});

it('reads the year-end year as the old Number() did', function () {
    Shop::expense($this->actor, '2025-03-01', PaymentAccountService::listExpenseCategories()[0]['id'], 700);
    $rows = fn (?string $year): array => ReportExport::download('year-end', $year === null ? [] : ['year' => $year], CSVX_TODAY)['rows'];

    expect($rows('2025'))->not->toBe($rows(null));
    foreach (['2025.0', '+2025', " 2025\u{A0}", '2.025e3'] as $spelling) {
        expect($rows($spelling))->toBe($rows('2025'));
    }
    expect($rows('2025x'))->toBe($rows(null));
});
