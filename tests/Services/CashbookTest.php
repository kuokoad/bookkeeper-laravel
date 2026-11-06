<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/cashbook.test.ts: expenses, other income,
| payment accounts, account movements, categories, owner capital and drawings,
| and the lists. Account balances come from Shop::balance, which is the real
| BalancesService::getAccountBalanceByCode.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Enums\PaymentAccountKind;
use App\Services\CashbookService;
use App\Services\PaymentAccountService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const CB_TODAY = '2026-08-17';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->momo = Shop::paymentAccount('MOBILE_MONEY');
    $this->spend = fn (string $category, int $amount, array $extra = []): array => CashbookService::recordExpense([
        'businessDate' => CB_TODAY,
        'categoryAccountId' => Shop::expenseCategory($category),
        'description' => $category,
        'amount' => $amount,
        'paymentAccountId' => $this->cash,
        ...$extra,
    ], $this->actor);
    $this->receive = fn (string $category, int $amount, array $extra = []): array => CashbookService::recordIncome([
        'businessDate' => CB_TODAY,
        'categoryAccountId' => Shop::incomeCategory($category),
        'description' => $category,
        'amount' => $amount,
        'paymentAccountId' => $this->cash,
        ...$extra,
    ], $this->actor);
});

describe('expenses', function () {
    it('reduces the paying account and records the cost', function () {
        $result = ($this->spend)('Transport', 4_500, ['description' => 'Taxi to Madina market']);

        expect($result['documentNo'])->toStartWith('EXP-')
            ->and(Shop::balance('1001'))->toBe(-4_500)
            ->and(Shop::balance('6050'))->toBe(4_500); // Transport
        Shop::assertBooksHealthy('cash expense');
    });

    it('can be paid from MoMo, leaving cash untouched', function () {
        ($this->spend)('MoMo Charges', 350, ['description' => 'Withdrawal fee', 'paymentAccountId' => $this->momo, 'reference' => 'MM-77120']);

        expect(Shop::balance('1011'))->toBe(-350)
            ->and(Shop::balance('1001'))->toBe(0);
        Shop::assertBooksHealthy('momo expense');
    });

    it('rejects zero, negative and blank entries', function () {
        expect(fn () => ($this->spend)('Rent', 0))->toThrow(ValidationError::class)
            ->and(fn () => ($this->spend)('Rent', -100))->toThrow(ValidationError::class)
            ->and(fn () => ($this->spend)('Rent', 100, ['description' => '   ']))->toThrow(ValidationError::class);
    });

    it('refuses a category that is not an expense account', function () {
        expect(fn () => ($this->spend)('Rent', 1_000, ['categoryAccountId' => Shop::accountId(ChartOfAccounts::SALES_REVENUE), 'description' => 'Wrong account']))
            ->toThrow(ValidationError::class, 'is not a valid expense category');
    });

    it('refuses to post to a heading that groups other categories', function () {
        expect(fn () => ($this->spend)('Rent', 1_000, ['categoryAccountId' => Shop::accountId(ChartOfAccounts::OPERATING_EXPENSES), 'description' => 'To a heading']))
            ->toThrow(ValidationError::class, 'is a heading');
    });

    it('totals and groups by category for a period', function () {
        ($this->spend)('Rent', 50_000, ['businessDate' => '2026-08-01', 'description' => 'August rent']);
        ($this->spend)('Transport', 6_000, ['businessDate' => '2026-08-10', 'description' => 'Deliveries']);
        ($this->spend)('Transport', 4_000, ['businessDate' => '2026-08-15', 'description' => 'More deliveries']);

        expect(CashbookService::getExpensesTotal('2026-08-01', '2026-08-31'))->toBe(60_000)
            // A narrower window excludes the rent.
            ->and(CashbookService::getExpensesTotal('2026-08-10', '2026-08-31'))->toBe(10_000);

        $byCategory = CashbookService::getExpensesByCategory('2026-08-01', '2026-08-31');
        $transport = collect($byCategory)->firstWhere('categoryName', 'Transport');
        expect($byCategory[0]['categoryName'])->toBe('Rent') // biggest first
            ->and($byCategory[0]['total'])->toBe(50_000)
            ->and($transport['total'])->toBe(10_000)
            ->and($transport['count'])->toBe(2);
    });

    it('voids by reversal, keeping the original record', function () {
        $result = ($this->spend)('Repairs & Maintenance', 12_000, ['description' => 'Fridge repair']);
        expect(Shop::balance('1001'))->toBe(-12_000);

        CashbookService::voidExpense($result['id'], 'Recorded twice by mistake', $this->actor);

        expect(Shop::balance('1001'))->toBe(0)
            ->and(Shop::balance('6070'))->toBe(0);
        Shop::assertBooksHealthy('void expense');

        $original = DB::table('expenses')->where('id', $result['id'])->first();
        expect($original->status)->toBe('VOIDED')
            ->and($original->void_reason)->toBe('Recorded twice by mistake')
            ->and((int) $original->amount_minor)->toBe(12_000) // untouched
            // A voided expense no longer counts toward the period total.
            ->and(CashbookService::getExpensesTotal(CB_TODAY, CB_TODAY))->toBe(0);
    });

    it('refuses to void twice or without a reason', function () {
        $result = ($this->spend)('Rent', 1_000);

        expect(fn () => CashbookService::voidExpense($result['id'], 'x', $this->actor))->toThrow(ValidationError::class);
        CashbookService::voidExpense($result['id'], 'Wrong month', $this->actor);
        expect(fn () => CashbookService::voidExpense($result['id'], 'Again', $this->actor))->toThrow(ConflictError::class);
    });
});

describe('other income', function () {
    it('increases the receiving account and records the income', function () {
        ($this->receive)('Commission', 8_000, ['description' => 'Airtime commission', 'paymentAccountId' => $this->momo]);

        expect(Shop::balance('1011'))->toBe(8_000)
            ->and(Shop::balance('4210'))->toBe(8_000);
        Shop::assertBooksHealthy('income');
    });

    it('is kept separate from sales revenue', function () {
        ($this->receive)('Service Income', 2_000, ['description' => 'Phone charging']);

        // Other income must NOT inflate sales revenue.
        expect(Shop::balance(ChartOfAccounts::SALES_REVENUE))->toBe(0)
            ->and(CashbookService::getFilteredIncomesSummary(['from' => CB_TODAY, 'to' => CB_TODAY])['total'])->toBe(2_000);
    });

    it('refuses an expense account as an income category', function () {
        expect(fn () => ($this->receive)('Commission', 1_000, ['categoryAccountId' => Shop::expenseCategory('Rent'), 'description' => 'Wrong']))
            ->toThrow(ValidationError::class, 'is not a valid income category');
    });

    it('voids by reversal', function () {
        $result = ($this->receive)('Commission', 5_000);
        CashbookService::voidIncome($result['id'], 'Duplicated', $this->actor);

        expect(Shop::balance('1001'))->toBe(0)
            ->and(CashbookService::getFilteredIncomesSummary(['from' => CB_TODAY, 'to' => CB_TODAY])['total'])->toBe(0)
            ->and(DB::table('incomes')->where('id', $result['id'])->value('status'))->toBe('VOIDED');
        Shop::assertBooksHealthy('void income');
    });
});

describe('payment accounts', function () {
    it('lets the owner add a mobile money account without a code change', function () {
        $id = PaymentAccountService::createPaymentAccount(['name' => 'Telecel Cash', 'kind' => PaymentAccountKind::MobileMoney, 'provider' => 'Telecel'], $this->actor);

        $account = collect(PaymentAccountService::listPaymentAccounts())->firstWhere('id', $id);
        expect($account['provider'])->toBe('Telecel')
            ->and($account['balance'])->toBe(0)
            // It got its own ledger account under the Mobile Money heading.
            ->and($account['glCode'])->toMatch('/^101\d$/');

        // And it works immediately for real money movement.
        ($this->receive)('Commission', 3_000, ['description' => 'Telecel commission', 'paymentAccountId' => $id]);
        expect(PaymentAccountService::getPaymentAccountBalance($id))->toBe(3_000);
        Shop::assertBooksHealthy('new payment account');
    });

    it('refuses a duplicate name', function () {
        PaymentAccountService::createPaymentAccount(['name' => 'Telecel Cash', 'kind' => PaymentAccountKind::MobileMoney], $this->actor);
        expect(fn () => PaymentAccountService::createPaymentAccount(['name' => 'telecel cash', 'kind' => PaymentAccountKind::MobileMoney], $this->actor))
            ->toThrow(ConflictError::class);
    });

    it('keeps exactly one default', function () {
        $id = PaymentAccountService::createPaymentAccount(['name' => 'Telecel Cash', 'kind' => PaymentAccountKind::MobileMoney, 'isDefault' => true], $this->actor);

        $defaults = array_values(array_filter(PaymentAccountService::listPaymentAccounts(), fn (array $a): bool => $a['isDefault']));
        expect($defaults)->toHaveCount(1)
            ->and($defaults[0]['id'])->toBe($id);
    });

    it('refuses to archive an account still holding money', function () {
        ($this->receive)('Commission', 5_000, ['paymentAccountId' => $this->momo]);

        expect(fn () => PaymentAccountService::setPaymentAccountActive($this->momo, false, $this->actor))
            ->toThrow(ConflictError::class, 'still holds money');
    });

    it('refuses to archive the default account', function () {
        // Cash is the seeded default and has a zero balance.
        expect(fn () => PaymentAccountService::setPaymentAccountActive($this->cash, false, $this->actor))
            ->toThrow(ConflictError::class, 'Make another account the default');
    });

    it('renames the ledger account when the payment account is renamed', function () {
        PaymentAccountService::updatePaymentAccount($this->momo, ['name' => 'MTN MoMo (shop line)', 'kind' => PaymentAccountKind::MobileMoney, 'provider' => 'MTN'], $this->actor);

        $account = collect(PaymentAccountService::listPaymentAccounts())->firstWhere('id', $this->momo);
        expect($account['name'])->toBe('MTN MoMo (shop line)')
            ->and(DB::table('accounts')->where('id', $account['glAccountId'])->value('name'))->toBe('MTN MoMo (shop line)');
    });
});

describe('account movements — answering "why is the balance this?"', function () {
    it('lists every movement with a running balance that ends at the balance', function () {
        ($this->receive)('Commission', 10_000, ['businessDate' => '2026-08-01']);
        ($this->spend)('Transport', 3_000, ['businessDate' => '2026-08-02', 'description' => 'Taxi']);
        ($this->spend)('Rent', 2_000, ['businessDate' => '2026-08-03']);

        $movements = PaymentAccountService::getAccountStatement($this->cash)['movements'];
        expect($movements)->toHaveCount(3)
            // Newest first, and the newest running balance IS the account balance.
            ->and($movements[0]['runningBalance'])->toBe(5_000)
            ->and(PaymentAccountService::getPaymentAccountBalance($this->cash))->toBe(5_000)
            // The oldest movement is the money coming in.
            ->and($movements[2]['inMinor'])->toBe(10_000)
            ->and($movements[2]['outMinor'])->toBe(0)
            ->and($movements[0]['outMinor'])->toBe(2_000);
    });

    it('filters by date without breaking the balance', function () {
        ($this->receive)('Commission', 10_000, ['businessDate' => '2026-07-01', 'description' => 'July']);
        ($this->receive)('Commission', 5_000, ['businessDate' => '2026-08-01', 'description' => 'August']);

        expect(PaymentAccountService::getAccountStatement($this->cash, ['from' => '2026-08-01'])['movements'])->toHaveCount(1)
            // Balance as at the end of July.
            ->and(PaymentAccountService::getPaymentAccountBalance($this->cash, '2026-07-31'))->toBe(10_000)
            ->and(PaymentAccountService::getPaymentAccountBalance($this->cash))->toBe(15_000);
    });

    it('throws for an unknown account rather than returning zero', function () {
        expect(fn () => PaymentAccountService::getPaymentAccountBalance(999_999))->toThrow(NotFoundError::class);
    });
});

describe('categories', function () {
    it('adds an expense category as a real account under Operating Expenses', function () {
        $id = PaymentAccountService::createCategory('EXPENSE', 'Security guard', $this->actor);

        $category = collect(PaymentAccountService::listExpenseCategories())->firstWhere('id', $id);
        expect($category['name'])->toBe('Security guard')
            ->and((int) $category['code'])->toBeGreaterThan(6000);

        // It can be used immediately.
        ($this->spend)('Rent', 20_000, ['categoryAccountId' => $id, 'description' => 'Night guard']);
        Shop::assertBooksHealthy('new category');
    });

    it('adds an income category under Other Income', function () {
        $id = PaymentAccountService::createCategory('INCOME', 'Table rental', $this->actor);
        expect(collect(PaymentAccountService::listIncomeCategories())->contains('id', $id))->toBeTrue()
            // And is refused as an expense category.
            ->and(fn () => ($this->spend)('Rent', 100, ['categoryAccountId' => $id, 'description' => 'Wrong']))
            ->toThrow(ValidationError::class, 'is not a valid expense category');
    });

    it('refuses a duplicate category name', function () {
        PaymentAccountService::createCategory('EXPENSE', 'Security guard', $this->actor);
        expect(fn () => PaymentAccountService::createCategory('EXPENSE', 'security guard', $this->actor))->toThrow(ConflictError::class);
    });

    it('ships sensible defaults for a Ghanaian shop', function () {
        $names = array_column(PaymentAccountService::listExpenseCategories(), 'name');
        expect($names)->toContain('Rent', 'Electricity', 'MoMo Charges', 'Transport');
    });
});

describe('owner capital and drawings', function () {
    it('capital increases the account and the owner’s stake, not revenue', function () {
        CashbookService::recordOwnerCapital(['businessDate' => CB_TODAY, 'paymentAccountId' => $this->cash, 'amount' => 300_000, 'description' => 'Opening float'], $this->actor);

        expect(Shop::balance('1001'))->toBe(300_000)
            ->and(Shop::balance(ChartOfAccounts::OWNERS_CAPITAL))->toBe(300_000)
            // Money the owner puts in is NOT income.
            ->and(Shop::balance(ChartOfAccounts::SALES_REVENUE))->toBe(0)
            ->and(CashbookService::getFilteredIncomesSummary(['from' => CB_TODAY, 'to' => CB_TODAY])['total'])->toBe(0);
        Shop::assertBooksHealthy('owner capital');
    });

    it('drawings reduce the owner’s stake, not the profit', function () {
        CashbookService::recordOwnerCapital(['businessDate' => CB_TODAY, 'paymentAccountId' => $this->cash, 'amount' => 300_000], $this->actor);
        CashbookService::recordOwnerDrawings(['businessDate' => CB_TODAY, 'paymentAccountId' => $this->cash, 'amount' => 50_000, 'description' => 'Owner took cash for school fees'], $this->actor);

        expect(Shop::balance('1001'))->toBe(250_000)
            ->and(Shop::balance(ChartOfAccounts::OWNERS_DRAWINGS))->toBe(50_000)
            // Crucially NOT an expense: it must not reduce reported profit.
            ->and(CashbookService::getExpensesTotal(CB_TODAY, CB_TODAY))->toBe(0);
        Shop::assertBooksHealthy('owner drawings');
    });

    it('creates a real source row so the entry is traceable', function () {
        $result = CashbookService::recordOwnerCapital(['businessDate' => CB_TODAY, 'paymentAccountId' => $this->cash, 'amount' => 1_000], $this->actor);

        $movement = DB::table('owner_movements')->where('id', $result['id'])->first();
        $entry = DB::table('journal_entries')->where('id', $result['journalEntryId'])->first();
        expect($movement->kind)->toBe('CAPITAL')
            ->and((int) $movement->journal_entry_id)->toBe($result['journalEntryId'])
            // The journal entry points back at that row.
            ->and($entry->source_type)->toBe('CAPITAL')
            ->and((int) $entry->source_id)->toBe($result['id']);
    });

    it('numbers capital from the income sequence and drawings from the expense sequence, as the old app did', function () {
        $capital = CashbookService::recordOwnerCapital(['businessDate' => CB_TODAY, 'paymentAccountId' => $this->cash, 'amount' => 1_000], $this->actor);
        $drawings = CashbookService::recordOwnerDrawings(['businessDate' => CB_TODAY, 'paymentAccountId' => $this->cash, 'amount' => 500], $this->actor);

        expect($capital['documentNo'])->toBe('INC-00001')
            ->and($drawings['documentNo'])->toBe('EXP-00001');
    });

    it('rejects a zero or negative amount', function () {
        expect(fn () => CashbookService::recordOwnerCapital(['businessDate' => CB_TODAY, 'paymentAccountId' => $this->cash, 'amount' => 0], $this->actor))
            ->toThrow(ValidationError::class);
    });
});

describe('lists', function () {
    it('shows expenses newest first with their category and account', function () {
        ($this->spend)('Rent', 50_000, ['businessDate' => '2026-08-01']);
        ($this->spend)('Electricity', 8_000, ['businessDate' => '2026-08-15', 'description' => 'ECG top-up', 'paymentAccountId' => $this->momo]);

        $rows = CashbookService::listExpenses();
        expect($rows)->toHaveCount(2)
            ->and($rows[0]['description'])->toBe('ECG top-up')
            ->and($rows[0]['categoryName'])->toBe('Electricity')
            ->and($rows[0]['paymentAccountName'])->toBe('MTN MoMo');
    });

    it('shows income separately from expenses', function () {
        ($this->spend)('Rent', 1_000);
        ($this->receive)('Commission', 2_000);

        expect(CashbookService::listExpenses())->toHaveCount(1)
            ->and(CashbookService::listIncomes())->toHaveCount(1);
    });
});
