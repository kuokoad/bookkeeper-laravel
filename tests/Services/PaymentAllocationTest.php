<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/payment-allocation.test.ts: which
| invoices a payment pays, and how an entry is unwound. Account balances come
| from Shop::balance until the reports arrive (Phase 7).
*/

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Domain\Errors\InvariantViolatedError;
use App\Domain\Errors\ValidationError;
use App\Enums\JournalSourceType;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\JournalService;
use App\Services\SaleService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->customer = Shop::customer($this->actor, 'Ama');
    $this->product = Shop::product($this->actor, 'Rice', 500, 1_000, ['unit' => 'bag']);
    Shop::openingStock($this->actor, $this->product, 500_000, 250_000, '2026-08-01');
    // A credit sale of $units at 10.00 each, dated $day.
    $this->creditSale = fn (string $day, int $units, ?int $customerId = null): array => SaleService::createSale([
        'businessDate' => $day, 'customerId' => $customerId ?? $this->customer,
        'items' => [['productId' => $this->product, 'qty' => $units * 1000]], 'tenders' => [],
    ], $this->actor);
    $this->pay = fn (string $day, int $amount, ?array $allocations = null): array => CustomerPaymentService::recordCustomerPayment([
        'customerId' => $this->customer, 'businessDate' => $day, 'paymentAccountId' => $this->cash, 'amount' => $amount,
        ...($allocations === null ? [] : ['allocations' => $allocations]),
    ], $this->actor);
});

describe('who a payment pays when the customer does not say', function () {
    it('settles the OLDEST invoice first', function () {
        $august = ($this->creditSale)('2026-08-05', 3);
        $september = ($this->creditSale)('2026-09-05', 3);

        ($this->pay)('2026-09-10', 3_000);

        expect(SaleService::getSaleOutstanding($august['saleId']))->toBe(0)
            ->and(SaleService::getSaleOutstanding($september['saleId']))->toBe(3_000);
    });

    it('spills over onto the next invoice when it more than covers the first', function () {
        $first = ($this->creditSale)('2026-08-05', 2);
        $second = ($this->creditSale)('2026-08-06', 5);

        ($this->pay)('2026-08-10', 3_000);

        expect(SaleService::getSaleOutstanding($first['saleId']))->toBe(0)
            ->and(SaleService::getSaleOutstanding($second['saleId']))->toBe(4_000)
            ->and(DB::table('customer_payment_allocations')->count())->toBe(2);
    });

    it('leaves the rest of an invoice outstanding on a part payment', function () {
        $sale = ($this->creditSale)('2026-08-05', 5);

        ($this->pay)('2026-08-10', 2_000);

        expect(SaleService::getSaleOutstanding($sale['saleId']))->toBe(3_000)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(3_000);
    });
});

describe('a payment aimed at particular invoices', function () {
    it('settles exactly the ones named, in defiance of age', function () {
        $old = ($this->creditSale)('2026-08-05', 2);
        $recent = ($this->creditSale)('2026-09-05', 2);

        ($this->pay)('2026-09-10', 2_000, [['saleId' => $recent['saleId'], 'amount' => 2_000]]);

        expect(SaleService::getSaleOutstanding($old['saleId']))->toBe(2_000)
            ->and(SaleService::getSaleOutstanding($recent['saleId']))->toBe(0);
    });

    it('refuses to put more on an invoice than it owes', function () {
        $sale = ($this->creditSale)('2026-08-05', 2);

        expect(fn () => ($this->pay)('2026-08-10', 2_000, [['saleId' => $sale['saleId'], 'amount' => 2_001]]))
            ->toThrow(ValidationError::class);
    });

    it('refuses to allocate more than the payment itself', function () {
        $first = ($this->creditSale)('2026-08-05', 2);
        $second = ($this->creditSale)('2026-08-06', 2);

        expect(fn () => ($this->pay)('2026-08-10', 2_000, [
            ['saleId' => $first['saleId'], 'amount' => 2_000],
            ['saleId' => $second['saleId'], 'amount' => 2_000],
        ]))->toThrow(ValidationError::class, 'exceed the payment');
    });

    it('refuses an invoice belonging to somebody else', function () {
        $other = Shop::customer($this->actor, 'Kofi');
        $theirs = ($this->creditSale)('2026-08-05', 2, $other);
        ($this->creditSale)('2026-08-05', 2);

        expect(fn () => ($this->pay)('2026-08-10', 2_000, [['saleId' => $theirs['saleId'], 'amount' => 2_000]]))
            ->toThrow(ValidationError::class, 'different customer');
    });
});

describe('reversing a journal entry', function () {
    beforeEach(function () {
        $this->reverse = fn (int $entryId): array => Tx::run(fn (): array => JournalService::reverse($entryId, '2026-08-11', sourceId: $entryId, memo: 'Reversed'));
        $this->postOne = fn (): array => Tx::run(fn (): array => JournalService::post('2026-08-10', JournalSourceType::OpeningBalance, [
            Journal::debit(Shop::accountId('1001'), 50_000),
            Journal::credit(Shop::accountId(ChartOfAccounts::OWNERS_CAPITAL), 50_000),
        ], memo: 'Owner puts money in', isOpening: true));
    });

    it('writes a mirror that cancels the original', function () {
        $original = ($this->postOne)();
        expect(Shop::balance(ChartOfAccounts::CASH))->toBe(50_000);

        ($this->reverse)($original['entryId']);

        expect(Shop::balance(ChartOfAccounts::CASH))->toBe(0)->and(Shop::trialBalanced())->toBeTrue();
    });

    it('leaves the original entry exactly as it was', function () {
        $original = ($this->postOne)();
        $before = DB::table('journal_lines')->where('entry_id', $original['entryId'])->orderBy('id')->get()->all();

        ($this->reverse)($original['entryId']);

        expect(DB::table('journal_lines')->where('entry_id', $original['entryId'])->orderBy('id')->get()->all())->toEqual($before);
    });

    it('links the two, so either can be found from the other', function () {
        $original = ($this->postOne)();
        $reversal = ($this->reverse)($original['entryId']);

        expect((int) DB::table('journal_entries')->where('id', $original['entryId'])->value('reversed_by_entry_id'))->toBe($reversal['entryId'])
            ->and((int) DB::table('journal_entries')->where('id', $reversal['entryId'])->value('reverses_entry_id'))->toBe($original['entryId']);
    });

    it('refuses to reverse without naming what it reverses', function () {
        $original = ($this->postOne)();

        expect(fn () => Tx::run(fn (): array => JournalService::reverse($original['entryId'], '2026-08-11')))
            ->toThrow(QueryException::class, 'traceable');
    });

    it('refuses to reverse the same entry twice', function () {
        $original = ($this->postOne)();
        ($this->reverse)($original['entryId']);

        expect(fn () => ($this->reverse)($original['entryId']))->toThrow(InvariantViolatedError::class, 'already been reversed');
    });

    it('swaps every debit for a credit rather than negating amounts', function () {
        $original = ($this->postOne)();
        $reversal = ($this->reverse)($original['entryId']);
        $mirrored = DB::table('journal_lines')->where('entry_id', $reversal['entryId'])->get();

        expect($mirrored->every(fn (object $l): bool => $l->debit_minor >= 0 && $l->credit_minor >= 0))->toBeTrue()
            ->and((int) $mirrored->sum('debit_minor'))->toBe(50_000)
            ->and((int) $mirrored->sum('credit_minor'))->toBe(50_000);
    });
});

it('refuses a payment that names the same sale twice, rather than blaming someone else', function () {
    $sale = ($this->creditSale)('2026-08-05', 3);

    expect(fn () => ($this->pay)('2026-08-10', 2_000, [['saleId' => $sale['saleId'], 'amount' => 1_000], ['saleId' => $sale['saleId'], 'amount' => 1_000]]))
        ->toThrow(ValidationError::class, 'Each sale can appear only once in a payment.');
    expect(SaleService::getSaleOutstanding($sale['saleId']))->toBe(3_000);
});
