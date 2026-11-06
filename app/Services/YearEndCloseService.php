<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\YearEndClose;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\FinancialYear;
use App\Domain\Money;
use App\Enums\AuditAction;
use App\Enums\JournalSourceType;
use App\Services\Reporting\BalancesService;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Closing and reopening a financial year. Closing posts one entry, flagged
 * is_closing and dated on the year end, that sweeps revenue, contra-revenue,
 * expenses, COGS and drawings into Retained Earnings, then moves the books
 * lock to the year end. Reopening reverses it (never deletes it) and moves
 * the lock back. A port of the old year-end-close.service.ts.
 */
final class YearEndCloseService
{
    /** The year's balances, never counting a closing entry, so closing twice cannot sweep the sweep. */
    private static function collectClosable(FinancialYear $year): array
    {
        $balances = array_filter(
            BalancesService::getAccountBalances(['from' => $year->start, 'to' => $year->end, 'excludeClosing' => true]),
            fn (array $a): bool => ! $a['isHeader'],
        );
        $of = fn (callable $keep): array => array_values(array_map(
            fn (array $a): array => ['accountId' => $a['accountId'], 'code' => $a['code'], 'name' => $a['name'], 'balance' => $a['balance']],
            array_filter($balances, $keep),
        ));

        return [
            'revenue' => $of(fn (array $a): bool => $a['type'] === 'REVENUE'),
            'contraRevenue' => $of(fn (array $a): bool => $a['type'] === 'CONTRA_REVENUE'),
            'expenses' => $of(fn (array $a): bool => $a['type'] === 'EXPENSE' || $a['type'] === 'COGS'),
            'drawings' => $of(fn (array $a): bool => $a['code'] === ChartOfAccounts::OWNERS_DRAWINGS),
        ];
    }

    /** The open (not reversed) closing for a year, if any. */
    private static function openClosing(int $startYear, bool $lock = false): ?object
    {
        return DB::table('year_end_closings')->where('year_end_closings.start_year', $startYear)->whereNull('year_end_closings.reversed_at')
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    public static function isYearClosed(int $startYear): bool
    {
        return self::openClosing($startYear) !== null;
    }

    /** Every close ever made, newest year first, reversed ones included. */
    public static function listClosings(): array
    {
        $startMonth = (int) SettingsService::get()->financial_year_start_month;

        return DB::table('year_end_closings')->orderByDesc('year_end_closings.start_year')->orderByDesc('year_end_closings.id')->get()
            ->map(fn (object $r): array => [
                'id' => (int) $r->id,
                'startYear' => (int) $r->start_year,
                'label' => FinancialYear::of((int) $r->start_year, $startMonth)->label,
                'periodStart' => (string) $r->period_start,
                'periodEnd' => (string) $r->period_end,
                'journalEntryId' => (int) $r->journal_entry_id,
                'profit' => (int) $r->profit_minor,
                'drawings' => (int) $r->drawings_minor,
                'closedAt' => Clock::fromStamp((string) $r->closed_at),
                'closedByUsername' => null,
                'reversedAt' => $r->reversed_at === null ? null : Clock::fromStamp((string) $r->reversed_at),
            ])->all();
    }

    /**
     * Refused when the year is already closed, has not finished, or an
     * earlier year is still open: each would make figures look final that are not.
     *
     * @return array{entryId: int, entryNo: string, profit: int, drawings: int, lineCount: int}
     */
    public static function closeFinancialYear(int $startYear, Actor $actor, ?Carbon $now = null): array
    {
        return Tx::run(function () use ($startYear, $actor, $now): array {
            $now ??= Carbon::now();
            // Exclusive first: this write moves the lock after posting.
            $settings = DB::table('business_settings')->where('id', 1)->lockForUpdate()->first();
            if ($settings === null) {
                throw new NotFoundError('Business settings', 1);
            }
            $startMonth = (int) $settings->financial_year_start_month;
            $year = FinancialYear::of($startYear, $startMonth);

            if (self::openClosing($startYear, lock: true) !== null) {
                throw new ConflictError("The year {$year->label} is already closed.");
            }
            if (Clock::dateOf($now) <= $year->end) {
                throw new ValidationError("The year {$year->label} has not finished yet — it ends {$year->end}. Closing it now would make provisional figures look final.");
            }

            $earliest = DB::table('journal_entries')->min('journal_entries.entry_date');
            if ($earliest !== null) {
                $first = FinancialYear::for((string) $earliest, $startMonth);
                for ($previous = $first->startYear; $previous < $startYear; $previous++) {
                    if (self::openClosing($previous) === null) {
                        $label = FinancialYear::of($previous, $startMonth)->label;
                        throw new ConflictError("Close {$label} first. Years must be closed in order, oldest first.");
                    }
                }
            }

            $closable = self::collectClosable($year);
            $entry = YearEndClose::buildClosingEntry(
                $closable['revenue'], $closable['contraRevenue'], $closable['expenses'], $closable['drawings'],
                JournalService::accountIdByCode(ChartOfAccounts::RETAINED_EARNINGS),
            );

            // Dated on the year end, past the very lock it is about to create.
            $posted = JournalService::post(
                $year->end,
                JournalSourceType::YearEndClose,
                $entry['lines'],
                memo: "Year-end close for {$year->label}",
                isClosing: true,
                overridePeriodLock: true,
                occurredAt: $now,
                actor: $actor,
            );

            $stamp = Clock::stamp($now);
            DB::table('year_end_closings')->insert([
                'start_year' => $startYear,
                'period_start' => $year->start,
                'period_end' => $year->end,
                'journal_entry_id' => $posted['entryId'],
                'profit_minor' => $entry['profit'],
                'drawings_minor' => $entry['totalDrawings'],
                'closed_by' => $actor->id,
                'closed_at' => $stamp,
                'created_at' => $stamp,
            ]);

            // Closing without locking would let a sale be dated into a year just declared final.
            DB::table('business_settings')->where('id', 1)->update(['books_locked_before' => $year->end, 'updated_at' => $stamp]);

            AuditService::write(
                AuditAction::Update,
                'year_end_closing',
                "Closed the year {$year->label}: profit ".Money::toDecimalString($entry['profit'], false).', drawings '.Money::toDecimalString($entry['totalDrawings'], false).". Books locked to {$year->end}.",
                entityId: $posted['entryId'],
                actor: $actor,
                at: $now,
            );

            return ['entryId' => $posted['entryId'], 'entryNo' => $posted['entryNo'], 'profit' => $entry['profit'], 'drawings' => $entry['totalDrawings'], 'lineCount' => count($entry['lines'])];
        });
    }

    /**
     * Reverses a closed year's entry (dated on the year end, flagged closing
     * so the P&L does not count the year twice) and moves the lock back to
     * the newest year still closed. Refused under a later closed year.
     */
    public static function reopenFinancialYear(int $startYear, Actor $actor, ?Carbon $now = null): void
    {
        Tx::run(function () use ($startYear, $actor, $now): void {
            $now ??= Carbon::now();
            $settings = DB::table('business_settings')->where('id', 1)->lockForUpdate()->first();
            if ($settings === null) {
                throw new NotFoundError('Business settings', 1);
            }
            $startMonth = (int) $settings->financial_year_start_month;
            $year = FinancialYear::of($startYear, $startMonth);

            $closing = self::openClosing($startYear, lock: true);
            if ($closing === null) {
                throw new ConflictError("The year {$year->label} is not closed.");
            }

            $later = DB::table('year_end_closings')->whereNull('year_end_closings.reversed_at')->where('year_end_closings.start_year', '>', $startYear)
                ->orderBy('year_end_closings.id')->first();
            if ($later !== null) {
                $label = FinancialYear::of((int) $later->start_year, $startMonth)->label;
                throw new ConflictError("Reopen {$label} first. Years must be reopened newest first.");
            }

            $reversal = JournalService::reverse(
                (int) $closing->journal_entry_id,
                $year->end,
                JournalSourceType::YearEndClose,
                memo: "Reopened the year {$year->label}",
                overridePeriodLock: true,
                isClosing: true,
                occurredAt: $now,
                actor: $actor,
            );

            $stamp = Clock::stamp($now);
            DB::table('year_end_closings')->where('id', $closing->id)->update(['reversed_at' => $stamp, 'reversed_by' => $actor->id, 'reversal_entry_id' => $reversal['entryId']]);

            $previousClosed = DB::table('year_end_closings')->whereNull('year_end_closings.reversed_at')->where('year_end_closings.start_year', '<=', $startYear)
                ->orderByDesc('year_end_closings.start_year')->first();
            $lockTo = $previousClosed === null ? null : (string) $previousClosed->period_end;
            DB::table('business_settings')->where('id', 1)->update(['books_locked_before' => $lockTo, 'updated_at' => $stamp]);

            AuditService::write(
                AuditAction::Reverse,
                'year_end_closing',
                "REOPENED the year {$year->label}. The closing entry was reversed, and the books lock moved back to ".($lockTo ?? 'nothing').'.',
                entityId: (int) $closing->id,
                actor: $actor,
                at: $now,
            );
        });
    }
}
