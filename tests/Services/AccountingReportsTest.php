<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/accounting-reports.test.ts: the chart of
| accounts, the journal, the general ledger, receivables and payables ageing
| and the books integrity check (reporting/ledger.service). The whole file is
| ported; nothing is deferred.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\CatalogService;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\LedgerService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const AR_TODAY = '2026-08-17';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->accountIdByCode = function (string $code): int {
        foreach (LedgerService::getChartOfAccounts() as $account) {
            if ($account['code'] === $code) {
                return $account['id'];
            }
        }
        throw new RuntimeException("No account {$code}");
    };
    $this->chartRow = fn (string $code): ?array => collect(LedgerService::getChartOfAccounts())->firstWhere('code', $code);
    // Milo: 10 in at 50.00, two sold for 16.00 cash (or on account with a customer).
    $this->makeSale = function (?int $customerId = null): array {
        $id = Shop::product($this->actor, 'Milo', 500, 800);
        Shop::openingStock($this->actor, $id, 10_000, 5_000, AR_TODAY);

        return Shop::sell($this->actor, [[$id, 2_000]], $customerId === null ? [['paymentAccountId' => $this->cash, 'amount' => 1_600]] : [], ['businessDate' => AR_TODAY, 'customerId' => $customerId]);
    };
    $this->expense = fn (string $date, string $category, int $amount, string $description = 'Rent') => Shop::expense($this->actor, $date, Shop::expenseCategory($category), $amount, $description);
});

describe('chart of accounts', function () {
    it('returns a tree with headings above their children', function () {
        $chart = LedgerService::getChartOfAccounts();
        $codes = array_column($chart, 'code');
        $heading = $chart[array_search(ChartOfAccounts::CASH, $codes, true)];
        $cashOnHand = $chart[array_search('1001', $codes, true)];

        expect($heading['isHeader'])->toBeTrue()
            ->and($cashOnHand['isHeader'])->toBeFalse()
            ->and($cashOnHand['parentId'])->toBe($heading['id'])
            ->and($cashOnHand['depth'])->toBe($heading['depth'] + 1)
            ->and(array_search(ChartOfAccounts::CASH, $codes, true))->toBeLessThan(array_search('1001', $codes, true));
    });

    it('rolls a heading’s balance up from its children', function () {
        ($this->makeSale)();

        expect(($this->chartRow)(ChartOfAccounts::CASH)['balance'])->toBe(0)
            ->and(($this->chartRow)(ChartOfAccounts::CASH)['rollup'])->toBe(1_600)
            ->and(($this->chartRow)('1001')['balance'])->toBe(1_600);
    });

    it('shows sign-adjusted balances so each type reads naturally', function () {
        ($this->makeSale)();

        expect(($this->chartRow)(ChartOfAccounts::SALES_REVENUE)['balance'])->toBe(1_600)
            ->and(($this->chartRow)(ChartOfAccounts::COST_OF_GOODS_SOLD)['balance'])->toBe(1_000);
    });

    it('leaves out an account whose lines all fall outside the period, as the old WHERE did', function () {
        ($this->makeSale)(); // every line dated AR_TODAY
        $codes = array_column(LedgerService::getChartOfAccounts(['from' => '2026-09-01', 'to' => '2026-09-30']), 'code');
        $balanceCodes = array_column(BalancesService::getAccountBalances(['from' => '2026-09-01', 'to' => '2026-09-30']), 'code');

        // Sales revenue moved, but not in September: dropped. A heading never posted to stays, at zero.
        expect($codes)->not->toContain(ChartOfAccounts::SALES_REVENUE)
            ->and($codes)->not->toContain('1001')
            ->and($codes)->toContain(ChartOfAccounts::CASH)
            ->and($balanceCodes)->not->toContain(ChartOfAccounts::SALES_REVENUE)
            ->and(array_column(LedgerService::getChartOfAccounts(['from' => AR_TODAY, 'to' => AR_TODAY]), 'code'))->toContain(ChartOfAccounts::SALES_REVENUE)
            ->and(array_column(LedgerService::getChartOfAccounts(), 'code'))->toContain(ChartOfAccounts::SALES_REVENUE);
    });
});

describe('journal', function () {
    it('lists entries newest first and reports each as balanced', function () {
        ($this->makeSale)();
        $entries = LedgerService::listJournalEntries(['from' => AR_TODAY, 'to' => AR_TODAY]);

        expect(count($entries))->toBeGreaterThanOrEqual(2)
            ->and(collect($entries)->every(fn (array $e): bool => $e['balanced']))->toBeTrue()
            ->and($entries[0]['sourceType'])->toBe('SALE');
    });

    it('list totals agree with each entry’s own lines', function () {
        ($this->makeSale)();
        ($this->expense)(AR_TODAY, 'Rent', 3_000);

        $entries = LedgerService::listJournalEntries();
        expect(count($entries))->toBeGreaterThanOrEqual(3);

        foreach ($entries as $summary) {
            $detail = LedgerService::getJournalEntry($summary['id']);
            expect($summary['total'])->toBe($detail['totalDebit'], "{$summary['entryNo']} total")
                ->and($summary['lineCount'])->toBe(count($detail['lines']), "{$summary['entryNo']} line count")
                ->and($summary['balanced'])->toBeTrue()
                ->and($detail['totalDebit'])->toBe($detail['totalCredit']);
        }
        expect(count(array_unique(array_column($entries, 'total'))))->toBeGreaterThan(1);
    });

    it('filters by kind and by account', function () {
        ($this->makeSale)();

        expect(LedgerService::listJournalEntries(['sourceType' => 'SALE']))->toHaveCount(1)
            ->and(LedgerService::listJournalEntries(['sourceType' => 'PURCHASE']))->toHaveCount(0);

        $cashLines = LedgerService::listJournalEntries(['accountId' => ($this->accountIdByCode)('1001')]);
        expect($cashLines)->toHaveCount(1)
            ->and($cashLines[0]['sourceType'])->toBe('SALE');
    });

    it('shows an entry’s lines with its subledger tags', function () {
        $customerId = Shop::customer($this->actor, 'Ama');
        $sale = ($this->makeSale)($customerId);

        $detail = LedgerService::getJournalEntry($sale['journalEntryId']);
        expect($detail['balanced'])->toBeTrue()
            ->and($detail['totalDebit'])->toBe($detail['totalCredit'])
            ->and(collect($detail['lines'])->firstWhere('accountCode', ChartOfAccounts::ACCOUNTS_RECEIVABLE)['customerName'])->toBe('Ama');
    });
});

describe('category product counts', function () {
    it('counts each category’s own products, not another table’s rows', function () {
        $drinks = CatalogService::createCategory(['name' => 'Drinks'], $this->actor);
        $food = CatalogService::createCategory(['name' => 'Food'], $this->actor);
        CatalogService::createCategory(['name' => 'Empty'], $this->actor);
        Shop::product($this->actor, 'Cola', 100, 200, ['categoryId' => $drinks]);
        Shop::product($this->actor, 'Water', 100, 200, ['categoryId' => $drinks]);
        Shop::product($this->actor, 'Bread', 100, 200, ['categoryId' => $food]);

        $counts = collect(CatalogService::listCategories())->mapWithKeys(fn ($c): array => [((array) $c)['name'] => ((array) $c)['productCount']]);
        expect($counts['Drinks'])->toBe(2)
            ->and($counts['Food'])->toBe(1)
            ->and($counts['Empty'])->toBe(0);
    });
});

describe('general ledger', function () {
    it('produces a running balance that ends at the account balance', function () {
        $categoryId = Shop::expenseCategory('Transport');
        ($this->expense)('2026-08-01', 'Transport', 1_000, 'Taxi one');
        ($this->expense)('2026-08-05', 'Transport', 2_000, 'Taxi two');

        $ledger = LedgerService::getGeneralLedger($categoryId);
        expect($ledger['lines'])->toHaveCount(2)
            ->and($ledger['lines'][0]['runningBalance'])->toBe(1_000)
            ->and($ledger['lines'][1]['runningBalance'])->toBe(3_000)
            ->and($ledger['closingBalance'])->toBe(3_000)
            ->and($ledger['account']['balance'])->toBe(3_000);
    });

    it('carries an opening balance when a period is given', function () {
        $categoryId = Shop::expenseCategory('Rent');
        ($this->expense)('2026-07-01', 'Rent', 50_000, 'July rent');
        ($this->expense)('2026-08-01', 'Rent', 50_000, 'August rent');

        $august = LedgerService::getGeneralLedger($categoryId, ['from' => '2026-08-01']);
        expect($august['openingBalance'])->toBe(50_000)
            ->and($august['lines'])->toHaveCount(1)
            ->and($august['closingBalance'])->toBe(100_000);
    });

    it('pages the lines without losing the running balance or the period totals', function () {
        $categoryId = Shop::expenseCategory('Rent');
        ($this->expense)('2026-07-01', 'Rent', 50_000, 'July rent');
        foreach ([1_000, 2_000, 3_000, 4_000] as $i => $amount) {
            ($this->expense)('2026-08-0'.($i + 1), 'Rent', $amount, "Repair {$i}");
        }

        $all = LedgerService::getGeneralLedger($categoryId, ['from' => '2026-08-01']);
        $page2 = LedgerService::getGeneralLedger($categoryId, ['from' => '2026-08-01', 'limit' => 2, 'offset' => 2]);
        // Page 2 picks up at 50,000 + 1,000 + 2,000, exactly where page 1 ended.
        expect(array_column($page2['lines'], 'runningBalance'))->toBe([56_000, 60_000])
            ->and($page2['lines'])->toBe(array_slice($all['lines'], 2, 2))
            ->and([$page2['total'], $page2['totalDebit'], $page2['closingBalance']])->toBe([4, 10_000, 60_000]);
    });

    it('reads a credit-normal account the right way round', function () {
        $supplierId = Shop::supplier($this->actor, 'Depot');
        $id = Shop::product($this->actor, 'Milo', 500, 800);
        Shop::purchase($this->actor, $supplierId, [[$id, 10_000, 500]], [], ['businessDate' => AR_TODAY]);

        $ledger = LedgerService::getGeneralLedger(($this->accountIdByCode)(ChartOfAccounts::ACCOUNTS_PAYABLE));
        expect($ledger['closingBalance'])->toBe(5_000)
            ->and($ledger['lines'][0]['credit'])->toBe(5_000);
    });
});

describe('ageing', function () {
    beforeEach(function () {
        $this->n = 0;
        $this->creditSale = function (string $date, int $amount, int $customerId): array {
            $id = Shop::product($this->actor, 'P'.(++$this->n), 100, $amount);
            Shop::openingStock($this->actor, $id, 10_000, 1_000, '2026-01-01');

            return Shop::sell($this->actor, [[$id, 1_000]], [], ['businessDate' => $date, 'customerId' => $customerId]);
        };
    });

    it('buckets each debt by how OVERDUE it is, not how old', function () {
        $customerId = Shop::customer($this->actor, 'Ama', extra: ['phone' => '024']);
        // Default 30-day terms: each falls due 30 days after the sale.
        ($this->creditSale)('2026-08-17', 1_000, $customerId); // due 09-16 -> not due
        ($this->creditSale)('2026-08-01', 2_000, $customerId); // due 08-31 -> not due
        ($this->creditSale)('2026-07-01', 3_000, $customerId); // due 07-31 -> 17 days over
        ($this->creditSale)('2026-06-01', 4_000, $customerId); // due 07-01 -> 47 days over
        ($this->creditSale)('2026-01-01', 5_000, $customerId); // due 01-31 -> far over

        $ageing = LedgerService::getReceivablesAgeing('2026-08-17');
        expect($ageing)->toHaveCount(1);
        $row = $ageing[0];
        expect($row['current'])->toBe(3_000)
            ->and($row['days1to30'])->toBe(3_000)
            ->and($row['days31to60'])->toBe(4_000)
            ->and($row['days61to90'])->toBe(0)
            ->and($row['over90'])->toBe(5_000)
            ->and($row['total'])->toBe(15_000)
            ->and($row['oldestDate'])->toBe('2026-01-31');
    });

    it('ages a sale with no due date from the sale date, as it always did', function () {
        $customerId = Shop::customer($this->actor, 'Kofi');
        ($this->creditSale)('2026-06-01', 4_000, $customerId);
        DB::table('sales')->update(['due_date' => null, 'terms_days' => null]);

        $row = LedgerService::getReceivablesAgeing('2026-08-17')[0];
        expect($row['days61to90'])->toBe(4_000)
            ->and($row['oldestDate'])->toBe('2026-06-01');
    });

    it('adds up to the same total as the receivables control account', function () {
        ($this->creditSale)('2026-08-01', 2_500, Shop::customer($this->actor, 'Ama'));
        ($this->creditSale)('2026-07-15', 4_000, Shop::customer($this->actor, 'Kofi'));

        $total = array_sum(array_column(LedgerService::getReceivablesAgeing('2026-08-17'), 'total'));
        expect($total)->toBe(($this->chartRow)(ChartOfAccounts::ACCOUNTS_RECEIVABLE)['balance']);
    });

    it('leaves out anything already paid', function () {
        $customerId = Shop::customer($this->actor, 'Ama');
        $id = Shop::product($this->actor, 'Milo', 500, 800);
        Shop::openingStock($this->actor, $id, 10_000, 5_000, AR_TODAY);
        Shop::sell($this->actor, [[$id, 1_000]], [['paymentAccountId' => $this->cash, 'amount' => 800]], ['businessDate' => AR_TODAY, 'customerId' => $customerId]);

        expect(LedgerService::getReceivablesAgeing(AR_TODAY))->toHaveCount(0);
    });

    it('ages what is owed to suppliers the same way', function () {
        $supplierId = Shop::supplier($this->actor, 'Depot');
        $id = Shop::product($this->actor, 'Milo', 500, 800);
        Shop::purchase($this->actor, $supplierId, [[$id, 2_000, 500]], [], ['businessDate' => '2026-08-17']);
        Shop::purchase($this->actor, $supplierId, [[$id, 4_000, 500]], [], ['businessDate' => '2026-05-01']);

        $ageing = LedgerService::getPayablesAgeing('2026-08-17');
        expect($ageing)->toHaveCount(1)
            ->and($ageing[0]['current'])->toBe(1_000)
            ->and($ageing[0]['over90'])->toBe(2_000)
            ->and($ageing[0]['total'])->toBe(3_000);
    });
});

describe('books integrity check', function () {
    it('reports healthy books after ordinary trading', function () {
        $customerId = Shop::customer($this->actor, 'Ama');
        $supplierId = Shop::supplier($this->actor, 'Depot');
        $id = Shop::product($this->actor, 'Milo', 500, 1_000);
        Shop::purchase($this->actor, $supplierId, [[$id, 20_000, 500]], [], ['businessDate' => AR_TODAY]);
        Shop::sell($this->actor, [[$id, 5_000]], [], ['businessDate' => AR_TODAY, 'customerId' => $customerId]);
        ($this->expense)(AR_TODAY, 'Rent', 2_000);

        $integrity = LedgerService::checkBooksIntegrity();
        expect($integrity['trialBalanced'])->toBeTrue()
            ->and($integrity['unbalancedEntries'])->toHaveCount(0)
            ->and($integrity['receivablesMatch'])->toBeTrue()
            ->and($integrity['payablesMatch'])->toBeTrue()
            ->and($integrity['untracedEntries'])->toBe(0);
    });

    it('detects an unbalanced entry if one is forced into the database', function () {
        $id = Shop::product($this->actor, 'Milo', 500, 800);
        Shop::openingStock($this->actor, $id, 10_000, 5_000, AR_TODAY);
        expect(LedgerService::checkBooksIntegrity()['trialBalanced'])->toBeTrue();

        // Tamper directly, bypassing every service-layer guard.
        $line = DB::table('journal_lines')->where('debit_minor', '>', 0)->orderBy('id')->first();
        DB::table('journal_lines')->where('id', $line->id)->update(['debit_minor' => $line->debit_minor + 1]);

        $integrity = LedgerService::checkBooksIntegrity();
        expect($integrity['trialBalanced'])->toBeFalse()
            ->and(count($integrity['unbalancedEntries']))->toBeGreaterThan(0)
            ->and($integrity['difference'])->not->toBe(0);
    });

    it('detects a receivable that lost its customer tag', function () {
        ($this->makeSale)(Shop::customer($this->actor, 'Ama'));
        expect(LedgerService::checkBooksIntegrity()['receivablesMatch'])->toBeTrue();

        DB::table('journal_lines')->update(['customer_id' => null]);

        expect(LedgerService::checkBooksIntegrity()['receivablesMatch'])->toBeFalse();
    });
});
