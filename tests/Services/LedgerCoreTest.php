<?php

declare(strict_types=1);

/*
| Numbering, posting, reversal and the books lock. Ported from the old
| tests/db/foundation.test.ts ("document numbering", "atomicity") and the
| journal-level and lock-management parts of tests/services/period-lock.test.ts.
| The sale, purchase and adjustment lock cases are here ("the books lock on a
| document"); the expense case waits for the cashbook in Phase 6.
*/

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Domain\Errors\InvariantViolatedError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\PeriodLockedError;
use App\Domain\Errors\UnbalancedEntryError;
use App\Domain\Errors\ValidationError;
use App\Enums\AuditAction;
use App\Enums\DocType;
use App\Enums\JournalSourceType;
use App\Services\AuditService;
use App\Services\JournalService;
use App\Services\PeriodLockService;
use App\Services\SaleService;
use App\Services\SequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
});

function cashSale(string $date = '2026-08-17', int $amount = 50_000, array $options = []): array
{
    return Tx::run(fn () => JournalService::post(
        $date,
        JournalSourceType::Sale,
        [Journal::debit(Shop::accountId('1001'), $amount), Journal::credit(Shop::accountId(ChartOfAccounts::SALES_REVENUE), $amount)],
        ...['sourceId' => 1, ...$options],
    ));
}

/**
 * Run code on a second connection that has no transaction open, as a caller
 * that forgot Tx::run would. The guard must refuse before anything is written.
 */
function outsideTheTestTransaction(Closure $work): mixed
{
    config(['database.connections.bare' => config('database.connections.mysql')]);
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('bare');
    try {
        return $work();
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('bare');
    }
}

describe('document numbering', function () {
    it('issues sequential numbers with the configured prefix and padding, per document type', function () {
        $take = fn (DocType $t) => Tx::run(fn () => SequenceService::next($t));
        expect([$take(DocType::Receipt), $take(DocType::Receipt), $take(DocType::Receipt), $take(DocType::Purchase), $take(DocType::Journal)])
            ->toBe(['RCP-00001', 'RCP-00002', 'RCP-00003', 'PUR-00001', 'JE-000001']);
    });

    it('rolls the counter back when its transaction fails, leaving no gap', function () {
        Tx::run(fn () => SequenceService::next(DocType::Expense));
        expect(fn () => Tx::run(function () {
            SequenceService::next(DocType::Expense);
            throw new RuntimeException('simulated failure after numbering');
        }))->toThrow(RuntimeException::class, 'simulated failure');

        expect(Tx::run(fn () => SequenceService::next(DocType::Expense)))->toBe('EXP-00002');
    });

    it('can preview the next number without consuming it', function () {
        expect(SequenceService::peek(DocType::Receipt))->toBe('RCP-00001')
            ->and(SequenceService::peek(DocType::Receipt))->toBe('RCP-00001')
            ->and(Tx::run(fn () => SequenceService::next(DocType::Receipt)))->toBe('RCP-00001');
    });

    it('throws rather than guessing for an unconfigured type', function () {
        DB::table('sequences')->where('doc_type', 'RECEIPT')->delete();
        Tx::run(fn () => SequenceService::next(DocType::Receipt));
    })->throws(InvariantViolatedError::class, 'No numbering sequence');

    it('refuses to hand out a number outside a transaction, where a failure could not return it', function () {
        outsideTheTestTransaction(fn () => SequenceService::next(DocType::Receipt));
    })->throws(InvariantViolatedError::class, 'must run inside a transaction');
});

describe('posting', function () {
    it('writes a balanced entry, numbered, with its lines in order', function () {
        $posted = cashSale(options: ['memo' => 'Till']);
        $entry = DB::table('journal_entries')->find($posted['entryId']);
        $lines = DB::table('journal_lines')->where('entry_id', $posted['entryId'])->orderBy('line_no')->get();

        expect($posted['entryNo'])->toBe('JE-000001')
            ->and([$entry->source_type, $entry->entry_date, $entry->memo])->toBe(['SALE', '2026-08-17', 'Till'])
            ->and($lines->map(fn ($l) => [$l->line_no, $l->debit_minor, $l->credit_minor])->all())->toBe([[1, 50_000, 0], [2, 0, 50_000]]);
    });

    it('drops lines that move nothing', function () {
        $posted = Tx::run(fn () => JournalService::post('2026-08-17', JournalSourceType::Sale, [
            Journal::debit(Shop::accountId('1001'), 100),
            Journal::debit(Shop::accountId(ChartOfAccounts::ACCOUNTS_RECEIVABLE), 0),
            Journal::credit(Shop::accountId(ChartOfAccounts::SALES_REVENUE), 100),
        ], sourceId: 1));

        expect(DB::table('journal_lines')->where('entry_id', $posted['entryId'])->count())->toBe(2);
    });

    it('refuses an unbalanced entry and leaves no partial rows, not even its number', function () {
        expect(fn () => Tx::run(fn () => JournalService::post('2026-08-17', JournalSourceType::Sale, [
            Journal::debit(Shop::accountId('1001'), 100),
            Journal::credit(Shop::accountId(ChartOfAccounts::SALES_REVENUE), 90),
        ], sourceId: 1)))->toThrow(UnbalancedEntryError::class);

        expect(DB::table('journal_entries')->count())->toBe(0)
            ->and(DB::table('journal_lines')->count())->toBe(0)
            ->and(SequenceService::peek(DocType::Journal))->toBe('JE-000001');
    });

    it('refuses a heading account, which only sums its children', function () {
        Tx::run(fn () => JournalService::post('2026-08-17', JournalSourceType::Sale, [
            Journal::debit(Shop::accountId(ChartOfAccounts::CASH), 100),
            Journal::credit(Shop::accountId(ChartOfAccounts::SALES_REVENUE), 100),
        ], sourceId: 1));
    })->throws(InvariantViolatedError::class, 'Account "Cash" is a heading and cannot be posted to directly.');

    it('refuses in line order, as the old app did: a heading on line 1 beats a missing account on line 2', function () {
        Tx::run(fn () => JournalService::post('2026-08-17', JournalSourceType::Sale, [
            Journal::debit(Shop::accountId(ChartOfAccounts::BANK), 100),
            Journal::credit(999_999, 100),
        ], sourceId: 1));
    })->throws(InvariantViolatedError::class, 'Account "Bank" is a heading');

    it('refuses an account that does not exist', function () {
        Tx::run(fn () => JournalService::post('2026-08-17', JournalSourceType::Sale, [
            Journal::debit(999_999, 100),
            Journal::credit(Shop::accountId(ChartOfAccounts::SALES_REVENUE), 100),
        ], sourceId: 1));
    })->throws(NotFoundError::class);

    it('refuses an entry with no traceable source, except an opening balance or year-end close', function () {
        expect(fn () => Tx::run(fn () => JournalService::post('2026-08-17', JournalSourceType::Sale, [
            Journal::debit(Shop::accountId('1001'), 100),
            Journal::credit(Shop::accountId(ChartOfAccounts::SALES_REVENUE), 100),
        ])))->toThrow(QueryException::class, 'ck_journal_entries_traceable');

        Shop::postOpeningCapital();
        expect(DB::table('journal_entries')->where('is_opening', true)->count())->toBe(1);
    });

    it('must run inside the caller\'s transaction', function () {
        $lines = [Journal::debit(Shop::accountId('1001'), 100), Journal::credit(Shop::accountId(ChartOfAccounts::SALES_REVENUE), 100)];
        outsideTheTestTransaction(fn () => JournalService::post('2026-08-17', JournalSourceType::Sale, $lines, sourceId: 1));
    })->throws(InvariantViolatedError::class, 'must run inside a transaction');
});

describe('reversal', function () {
    it('mirrors every line, links both ways, and nets to nothing', function () {
        $original = cashSale();
        $reversal = Tx::run(fn () => JournalService::reverse($original['entryId'], '2026-08-18', sourceId: 1, actor: $this->actor));

        $links = DB::table('journal_entries')->whereIn('id', [$original['entryId'], $reversal['entryId']])->get()->keyBy('id');
        $net = DB::table('journal_lines')->where('account_id', Shop::accountId('1001'))->selectRaw('SUM(debit_minor) - SUM(credit_minor) AS n')->value('n');
        expect($links[$original['entryId']]->reversed_by_entry_id)->toBe($reversal['entryId'])
            ->and($links[$reversal['entryId']]->reverses_entry_id)->toBe($original['entryId'])
            ->and($links[$reversal['entryId']]->source_type)->toBe('REVERSAL')
            ->and($links[$reversal['entryId']]->memo)->toBe('Reversal of JE-000001')
            ->and((int) $net)->toBe(0);
    });

    it('refuses to reverse the same entry twice', function () {
        $original = cashSale();
        Tx::run(fn () => JournalService::reverse($original['entryId'], '2026-08-18', sourceId: 1));
        Tx::run(fn () => JournalService::reverse($original['entryId'], '2026-08-19', sourceId: 1));
    })->throws(InvariantViolatedError::class, 'already been reversed');

    it('keeps payment-account and party tags, and labels descriptions', function () {
        $posted = Tx::run(fn () => JournalService::post('2026-08-17', JournalSourceType::Sale, [
            Journal::debit(Shop::accountId('1001'), 100, ['paymentAccountId' => 1, 'description' => 'Cash sale']),
            Journal::credit(Shop::accountId(ChartOfAccounts::SALES_REVENUE), 100),
        ], sourceId: 1));
        $reversal = Tx::run(fn () => JournalService::reverse($posted['entryId'], '2026-08-18', sourceId: 1));

        $line = DB::table('journal_lines')->where('entry_id', $reversal['entryId'])->where('line_no', 1)->first();
        expect([$line->payment_account_id, $line->credit_minor, $line->description])->toBe([1, 100, 'Reversal: Cash sale']);
    });
});

describe('the books lock on posting', function () {
    beforeEach(function () {
        PeriodLockService::setLock('2026-07-31', $this->actor);
    });

    it('refuses an entry dated on or before the lock, leaving nothing behind', function () {
        expect(fn () => cashSale('2026-07-31'))->toThrow(PeriodLockedError::class);
        expect(DB::table('journal_entries')->count())->toBe(0);
    });

    it('allows an entry dated after the lock', function () {
        expect(cashSale('2026-08-01')['entryNo'])->toBe('JE-000001');
    });

    it('lets year-end close and reopen through with an override', function () {
        expect(cashSale('2026-07-15', options: ['overridePeriodLock' => true])['entryNo'])->toBe('JE-000001');
    });

    it('still allows a locked entry to be corrected by a reversal dated today', function () {
        PeriodLockService::setLock(null, $this->actor);
        $old = cashSale('2026-07-15');
        PeriodLockService::setLock('2026-07-31', $this->actor);

        Tx::run(fn () => JournalService::reverse($old['entryId'], '2026-08-02', sourceId: 1));
        expect(DB::table('journal_entries')->count())->toBe(2);
    });
});

describe('the books lock on a document', function () {
    beforeEach(function () {
        $this->milo = Shop::product($this->actor, 'Milo', 500, 1_000);
        Shop::openingStock($this->actor, $this->milo, 100_000, 50_000, '2026-06-01');
        PeriodLockService::setLock('2026-07-31', $this->actor);
        $this->sell = fn (string $date, int $units = 1): array => SaleService::createSale([
            'businessDate' => $date,
            'items' => [['productId' => $this->milo, 'qty' => $units * 1000]],
            'tenders' => [['paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => $units * 1_000]],
        ], $this->actor);
    });

    it('refuses a sale dated inside the closed period', function () {
        expect(fn () => ($this->sell)('2026-07-15'))->toThrow(PeriodLockedError::class);
    });

    it('allows a sale dated after the lock', function () {
        expect(($this->sell)('2026-08-01')['receiptNo'])->toBe('RCP-00001');
    });

    it('refuses a back-dated purchase', function () {
        $supplierId = Shop::supplier($this->actor, 'Depot');
        expect(fn () => Shop::purchase($this->actor, $supplierId, [[$this->milo, 1_000, 500]], [], ['businessDate' => '2026-07-01']))
            ->toThrow(PeriodLockedError::class);
    });

    it('refuses a back-dated stock adjustment', function () {
        expect(fn () => Shop::stockOut($this->actor, $this->milo, 1_000, '2026-07-10'))->toThrow(PeriodLockedError::class);
    });

    it('leaves nothing behind when a locked transaction is refused', function () {
        $sales = DB::table('sales')->count();
        $debits = (int) DB::table('journal_lines')->sum('debit_minor');

        expect(fn () => ($this->sell)('2026-07-15', 5))->toThrow(PeriodLockedError::class);

        expect(DB::table('sales')->count())->toBe($sales)
            ->and((int) DB::table('journal_lines')->sum('debit_minor'))->toBe($debits)
            ->and(Shop::trialBalanced())->toBeTrue();
    });

    it('still allows a locked transaction to be corrected by a dated reversal', function () {
        PeriodLockService::setLock(null, $this->actor);
        $sale = ($this->sell)('2026-07-15', 2);
        PeriodLockService::setLock('2026-07-31', $this->actor);

        // The reversal is dated today, which is after the lock, so it goes through.
        SaleService::voidSale($sale['saleId'], 'Entered in error', $this->actor);

        $original = DB::table('sales')->where('id', $sale['saleId'])->first();
        expect($original->status)->toBe('VOIDED')
            // The original record is untouched: its date still says July.
            ->and($original->business_date)->toBe('2026-07-15')
            ->and(Shop::trialBalanced())->toBeTrue();
    });
});

describe('managing the lock', function () {
    it('reports what is locked', function () {
        cashSale('2026-07-15');
        expect(PeriodLockService::status()['lockedBefore'])->toBeNull();
        PeriodLockService::setLock('2026-07-31', $this->actor);
        expect(PeriodLockService::status('2026-06-30'))->toBe(['lockedBefore' => '2026-07-31', 'entriesLocked' => 1, 'entriesAffectedByProposal' => 0])
            ->and(PeriodLockService::countOpenEntries('2026-07-31'))->toBe(0)
            ->and(PeriodLockService::countOpenEntries(null))->toBe(1);
    });

    it('records closing, reopening and removal distinctly', function () {
        PeriodLockService::setLock('2026-07-31', $this->actor);
        PeriodLockService::setLock('2026-06-30', $this->actor);
        PeriodLockService::setLock(null, $this->actor);

        $entries = DB::table('audit_logs')->where('entity_type', 'books_lock')->orderBy('id')->get();
        expect($entries->pluck('summary')->all())->toBe([
            'Closed the books up to 2026-07-31',
            'REOPENED the books: lock moved back from 2026-07-31 to 2026-06-30',
            'REOPENED the books: lock moved back from 2026-06-30 to nothing locked',
        ])
            ->and($entries->pluck('action')->unique()->all())->toBe(['SETTINGS_CHANGE'])
            ->and($entries[0]->username)->toBe('kwame')
            ->and(json_decode($entries[1]->metadata, true))->toBe(['before' => '2026-07-31', 'after' => '2026-06-30', 'reopened' => true]);
    });

    it('does nothing, and logs nothing, when the date is unchanged', function () {
        PeriodLockService::setLock('2026-07-31', $this->actor);
        PeriodLockService::setLock('2026-07-31', $this->actor);
        expect(DB::table('audit_logs')->where('entity_type', 'books_lock')->count())->toBe(1);
    });

    it('rejects a malformed date', function () {
        PeriodLockService::setLock('31/07/2026', $this->actor);
    })->throws(ValidationError::class, 'Enter a valid lock date.');
});

describe('the audit log', function () {
    it('redacts secrets however deeply nested, and writes an empty object as {}', function () {
        AuditService::write(AuditAction::Update, 'user', 'Changed things', metadata: ['password' => 'x', 'nested' => ['PIN_hash' => 'y', 'keep' => 1]]);
        AuditService::write(AuditAction::Update, 'user', 'Nothing', metadata: []);

        $rows = DB::table('audit_logs')->where('entity_type', 'user')->orderBy('id')->pluck('metadata')->all();
        expect(json_decode($rows[0], true))->toBe(['password' => '[redacted]', 'nested' => ['PIN_hash' => '[redacted]', 'keep' => 1]])
            ->and($rows[1])->toBe('{}');
    });

    it('redacts secrets inside an object, as the old app redacted any object', function () {
        AuditService::write(AuditAction::Update, 'user', 'Object', metadata: ['user' => (object) ['name' => 'Ama', 'pin' => '8351']]);
        expect(json_decode(DB::table('audit_logs')->where('summary', 'Object')->value('metadata'), true))->toBe(['user' => ['name' => 'Ama', 'pin' => '[redacted]']]);
    });

    it('filters and counts with the same conditions, and treats wildcards literally', function () {
        AuditService::write(AuditAction::Update, 'product', 'Price 100% off', actor: $this->actor);
        AuditService::write(AuditAction::Update, 'product', 'Price changed');

        expect(AuditService::count(['search' => '100%']))->toBe(1)
            ->and(AuditService::list(['search' => '100%'])->pluck('summary')->all())->toBe(['Price 100% off'])
            ->and(AuditService::count(['search' => '%']))->toBe(1)
            ->and(AuditService::count(['entityType' => 'product']))->toBe(2)
            ->and(AuditService::count(['search' => 'KWAME']))->toBe(1)
            ->and(AuditService::entityTypes())->toContain('product', 'system');
    });
});
