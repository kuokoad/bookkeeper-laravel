<?php

declare(strict_types=1);

/*
| New: every list export and every report export builds through the same
| parsers and reads as its page, with the old route's headers, on a shop
| with some trading in it. An unknown name is null (the controller's 404).
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Reports\Csv\Csv;
use App\Reports\Csv\ListExport;
use App\Reports\Csv\ReportExport;
use App\Services\CustomerPaymentService;
use App\Services\PaymentAccountService;
use Illuminate\Support\Facades\DB;
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
        // Named after the dates the rows were read with: this month for a dated list, today for a position.
        ->and($file['filename'])->toBe('bookkeeper_'.$list.'_'.(in_array($list, ['products', 'customers', 'suppliers'], true) ? '' : '2026-08-01_').CSVX_TODAY.'.csv');
})->with([
    ['sales', 'Receipt'], ['purchases', 'Purchase'], ['expenses', 'Number'], ['income', 'Number'], ['products', 'Stock item'],
    ['stock-movements', 'Date'], ['customers', 'Customer'], ['suppliers', 'Supplier'], ['account', 'Date'], ['quotations', 'Quote'],
]);

it('builds every report with the old headers', function (string $report, string $firstHeader) {
    $file = ReportExport::download($report, ['from' => '2026-08-01', 'to' => CSVX_TODAY, 'asAt' => CSVX_TODAY, 'year' => '2026'], CSVX_TODAY);

    expect($file)->not->toBeNull()
        ->and($file['headers'][0])->toBe($firstHeader)
        ->and($file['filename'])->toBe("bookkeeper_{$report}_2026-08-01_".CSVX_TODAY.'.csv');
})->with([
    ['profit-and-loss', 'Item'], ['balance-sheet', 'Item'], ['cash-flow', 'Item'], ['sales', 'Section'], ['purchases', 'Section'],
    ['inventory', 'Stock item'], ['tax', 'Tax'], ['expiry', 'Batch'], ['receivables', 'Customer'], ['payables', 'Supplier'], ['year-end', 'Item'],
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

describe('balance-sheet rows add up to their own totals', function () {
    beforeEach(function () {
        // A 50.00 advance from a customer who owes nothing: a customer credit balance.
        Shop::setting('allow_overpayment', true);
        CustomerPaymentService::recordCustomerPayment(['customerId' => Shop::customer($this->actor, 'Ama'), 'businessDate' => '2026-08-20', 'paymentAccountId' => $this->cash, 'amount' => 5_000], $this->actor);
        $this->minor = fn (string $amount): int => (int) str_replace('.', '', $amount);
        // Sums the amount column of the rows strictly between two labels.
        $this->sumBetween = function (array $rows, string $after, string $until, int $col = 1): int {
            $labels = array_column($rows, 0);
            $from = array_search($after, $labels, true);
            $to = array_search($until, $labels, true);
            expect($from)->not->toBeFalse()->and($to)->not->toBeFalse();

            return array_sum(array_map(fn (array $r): int => ($this->minor)($r[$col]), array_slice($rows, $from + 1, $to - $from - 1)));
        };
    });

    it('lists customer credit balances in the balance-sheet CSV so its liabilities add up', function () {
        $rows = ReportExport::download('balance-sheet', ['asAt' => CSVX_TODAY], CSVX_TODAY)['rows'];
        $byLabel = array_column($rows, 1, 0);
        $assets = array_sum(array_map(fn (array $r): int => ($this->minor)($r[1]), array_filter($rows, fn (array $r): bool => str_starts_with($r[0], 'Asset: '))));
        $liabilities = array_sum(array_map(fn (array $r): int => ($this->minor)($r[1]), array_filter($rows, fn (array $r): bool => str_starts_with($r[0], 'Liability: '))));

        expect($byLabel['Liability: Customer credit balances'])->toBe('50.00')
            ->and($byLabel)->toHaveKey('Asset: Paid to suppliers in advance')
            ->and($assets)->toBe(($this->minor)($byLabel['Total assets']))
            ->and($liabilities)->toBe(($this->minor)($byLabel['Total liabilities']));
    });

    it('lists customer credit balances in the year-end CSV so its balance sheet adds up', function () {
        // An "other" asset held since the previous year: a 300.00 rent deposit paid from the owner's capital.
        $deposit = DB::table('accounts')->insertGetId(['code' => '1500', 'name' => 'Rent deposit', 'type' => 'ASSET', 'normal_balance' => 'DEBIT']);
        $entry = DB::table('journal_entries')->insertGetId([
            'entry_no' => 'JE-TEST-2', 'entry_date' => '2025-06-01', 'occurred_at' => '2025-06-01 10:00:00', 'source_type' => 'CAPITAL', 'source_id' => 1,
        ]);
        DB::table('journal_lines')->insert([
            ['entry_id' => $entry, 'line_no' => 1, 'account_id' => $deposit, 'debit_minor' => 30_000, 'credit_minor' => 0],
            ['entry_id' => $entry, 'line_no' => 2, 'account_id' => DB::table('accounts')->where('code', ChartOfAccounts::OWNERS_CAPITAL)->value('id'), 'debit_minor' => 0, 'credit_minor' => 30_000],
        ]);

        $rows = ReportExport::download('year-end', ['year' => '2026'], CSVX_TODAY)['rows'];
        $byLabel = array_column($rows, 1, 0);
        $previous = array_column($rows, 2, 0);

        expect($byLabel['Customer credit balances'])->toBe('50.00')
            ->and($previous['Rent deposit'])->toBe('300.00');
        foreach ([1 => $byLabel, 2 => $previous] as $col => $amounts) {
            expect(($this->sumBetween)($rows, 'BALANCE SHEET', 'TOTAL ASSETS', $col))->toBe(($this->minor)($amounts['TOTAL ASSETS']))
                ->and(($this->sumBetween)($rows, 'TOTAL ASSETS', 'TOTAL LIABILITIES', $col))->toBe(($this->minor)($amounts['TOTAL LIABILITIES']));
        }
    });
});
