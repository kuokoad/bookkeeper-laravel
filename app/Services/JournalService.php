<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Accounting\DraftLine;
use App\Domain\Accounting\Journal;
use App\Domain\Accounting\PeriodLock;
use App\Domain\Errors\InvariantViolatedError;
use App\Domain\Errors\NotFoundError;
use App\Enums\DocType;
use App\Enums\JournalSourceType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every posting goes through here. An entry is checked against the books
 * lock, stripped of zero lines, asserted balanced, refused if it touches a
 * heading account, numbered from the JOURNAL sequence and written, all inside
 * the caller's transaction. A port of the old journal.service.ts.
 */
final class JournalService
{
    /** A chart-of-accounts code (ChartOfAccounts::*) to its account id. */
    public static function accountIdByCode(string $code): int
    {
        $id = DB::table('accounts')->where('code', $code)->value('id');

        return $id === null ? throw new NotFoundError('Account', $code) : (int) $id;
    }

    /**
     * The books lock. Read with a shared lock so it cannot move while this
     * transaction posts; changing it takes the exclusive lock and waits.
     *
     * Lock order (see Tx): a service that posts calls this BEFORE taking any
     * document number, so every writer meets the settings row and then the
     * sequence rows in the same order. post() calls it again; the second read
     * is a lock this transaction already holds. A transaction that has posted
     * must not then update business_settings (a shared-to-exclusive upgrade
     * that can deadlock with another poster).
     */
    public static function readLockDate(): ?string
    {
        $value = DB::table('business_settings')->where('id', 1)->sharedLock()->value('books_locked_before');

        return $value === null ? null : (string) $value;
    }

    /**
     * @param  list<DraftLine>  $lines
     * @return array{entryId: int, entryNo: string}
     */
    public static function post(
        string $entryDate,
        JournalSourceType $sourceType,
        array $lines,
        ?int $sourceId = null,
        ?string $memo = null,
        bool $isOpening = false,
        bool $isClosing = false,
        bool $isDemo = false,
        bool $overridePeriodLock = false,
        ?Carbon $occurredAt = null,
        ?Actor $actor = null,
    ): array {
        Tx::assertOpen('Posting a journal entry');
        PeriodLock::assertOpen($entryDate, self::readLockDate(), $overridePeriodLock);

        $lines = Journal::finalise($lines);
        self::assertAccountsPostable($lines);

        $entryNo = SequenceService::next(DocType::Journal);
        $at = ($occurredAt ?? Carbon::now())->format('Y-m-d H:i:s.v');

        $entryId = (int) DB::table('journal_entries')->insertGetId([
            'entry_no' => $entryNo,
            'entry_date' => $entryDate,
            'occurred_at' => $at,
            'source_type' => $sourceType->value,
            'source_id' => $sourceId,
            'memo' => $memo,
            'is_opening' => $isOpening,
            'is_closing' => $isClosing,
            'created_by' => $actor?->id,
            'is_demo' => $isDemo,
            'created_at' => $at,
        ]);

        $rows = [];
        foreach ($lines as $index => $line) {
            $rows[] = [
                'entry_id' => $entryId,
                'line_no' => $index + 1,
                'account_id' => $line->accountId,
                'debit_minor' => $line->debit,
                'credit_minor' => $line->credit,
                'payment_account_id' => $line->paymentAccountId,
                'customer_id' => $line->customerId,
                'supplier_id' => $line->supplierId,
                'description' => $line->description,
                'created_at' => $at,
            ];
        }
        DB::table('journal_lines')->insert($rows);

        return ['entryId' => $entryId, 'entryNo' => $entryNo];
    }

    /**
     * Post the mirror of an entry and link the two. History is never edited:
     * a correction is a reversal, dated when it is made.
     *
     * @return array{entryId: int, entryNo: string}
     */
    public static function reverse(
        int $entryId,
        string $entryDate,
        ?JournalSourceType $sourceType = null,
        ?int $sourceId = null,
        ?string $memo = null,
        bool $overridePeriodLock = false,
        bool $isClosing = false,
        ?Carbon $occurredAt = null,
        ?Actor $actor = null,
    ): array {
        Tx::assertOpen('Reversing a journal entry');

        // Locked, so two people voiding the same document cannot both reverse it.
        $original = DB::table('journal_entries')->where('id', $entryId)->lockForUpdate()->first();
        if ($original === null) {
            throw new NotFoundError('Journal entry', $entryId);
        }
        if ($original->reversed_by_entry_id !== null) {
            throw new InvariantViolatedError('That entry has already been reversed.', ['entryId' => $entryId]);
        }

        $originalLines = DB::table('journal_lines')->where('entry_id', $entryId)->orderBy('line_no')->get();
        if ($originalLines->isEmpty()) {
            throw new InvariantViolatedError('That entry has no lines to reverse.', ['entryId' => $entryId]);
        }

        $mirrored = Journal::reverse($originalLines->map(fn (object $line): DraftLine => new DraftLine(
            (int) $line->account_id,
            (int) $line->debit_minor,
            (int) $line->credit_minor,
            $line->payment_account_id === null ? null : (int) $line->payment_account_id,
            $line->customer_id === null ? null : (int) $line->customer_id,
            $line->supplier_id === null ? null : (int) $line->supplier_id,
            $line->description,
        ))->all());

        $reversal = self::post(
            $entryDate,
            $sourceType ?? JournalSourceType::Reversal,
            $mirrored,
            sourceId: $sourceId,
            memo: $memo ?? "Reversal of {$original->entry_no}",
            isClosing: $isClosing,
            isDemo: (bool) $original->is_demo,
            overridePeriodLock: $overridePeriodLock,
            occurredAt: $occurredAt,
            actor: $actor,
        );

        DB::table('journal_entries')->where('id', $reversal['entryId'])->update(['reverses_entry_id' => $entryId]);
        DB::table('journal_entries')->where('id', $entryId)->update(['reversed_by_entry_id' => $reversal['entryId']]);

        return $reversal;
    }

    /**
     * Every account exists and none is a heading: a heading's balance is its
     * children's, so posting to it directly would hide money in the chart.
     *
     * @param  list<DraftLine>  $lines
     */
    private static function assertAccountsPostable(array $lines): void
    {
        $ids = array_values(array_unique(array_map(fn (DraftLine $l): int => $l->accountId, $lines)));
        $names = DB::table('accounts')->whereIn('id', $ids)->pluck('name', 'id');
        $headings = DB::table('accounts')->whereIn('parent_id', $ids)->distinct()->pluck('parent_id')->map(fn ($id): int => (int) $id)->all();

        // In line order, existence then heading, account by account: the same
        // first refusal the old app gave.
        foreach ($ids as $id) {
            if (! $names->has($id)) {
                throw new NotFoundError('Account', $id);
            }
            if (in_array($id, $headings, true)) {
                throw new InvariantViolatedError("Account \"{$names[$id]}\" is a heading and cannot be posted to directly.", ['accountId' => $id]);
            }
        }
    }
}
