<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Accounting\PeriodLock;
use App\Domain\BusinessDate;
use App\Domain\Errors\ValidationError;
use App\Enums\AuditAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The books lock: transactions dated on or before it are refused. A control,
 * not an accounting close. Moving it back, or removing it, reopens a period
 * that was declared final, and is recorded distinctly. A port of the old
 * period-lock.service.ts.
 */
final class PeriodLockService
{
    /** @return array{lockedBefore: ?string, entriesLocked: int, entriesAffectedByProposal?: int} */
    public static function status(?string $proposedDate = null): array
    {
        $lockedBefore = DB::table('business_settings')->where('id', 1)->value('books_locked_before');
        $countUpTo = fn (string $date): int => DB::table('journal_entries')->where('journal_entries.entry_date', '<=', $date)->count();

        $status = [
            'lockedBefore' => $lockedBefore,
            'entriesLocked' => $lockedBefore === null ? 0 : $countUpTo($lockedBefore),
        ];
        if ($proposedDate !== null) {
            $status['entriesAffectedByProposal'] = $countUpTo($proposedDate);
        }

        return $status;
    }

    public static function setLock(?string $lockedBefore, Actor $actor, ?Carbon $now = null): void
    {
        if ($lockedBefore !== null) {
            try {
                BusinessDate::assert($lockedBefore);
            } catch (ValidationError) {
                throw new ValidationError('Enter a valid lock date.');
            }
        }
        $now ??= Carbon::now();

        Tx::run(function () use ($lockedBefore, $actor, $now): void {
            // Exclusive: waits for any posting that read the old lock to finish.
            $current = DB::table('business_settings')->where('id', 1)->lockForUpdate()->value('books_locked_before');
            if ($current === $lockedBefore) {
                return;
            }

            $relaxing = PeriodLock::isRelaxation($current, $lockedBefore);
            DB::table('business_settings')->where('id', 1)->update([
                'books_locked_before' => $lockedBefore,
                'updated_at' => $now->format('Y-m-d H:i:s.v'),
            ]);

            AuditService::write(
                AuditAction::SettingsChange,
                'books_lock',
                match (true) {
                    $relaxing => 'REOPENED the books: lock moved back from '.$current.' to '.($lockedBefore ?? 'nothing locked'),
                    $lockedBefore === null => 'Removed the books lock',
                    default => "Closed the books up to {$lockedBefore}",
                },
                entityId: 'books_lock',
                actor: $actor,
                metadata: ['before' => $current, 'after' => $lockedBefore, 'reopened' => $relaxing],
                at: $now,
            );
        });
    }
}
