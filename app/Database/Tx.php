<?php

declare(strict_types=1);

namespace App\Database;

use App\Domain\Errors\ConflictError;
use App\Domain\Errors\InvariantViolatedError;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The one way a service writes. Everything inside the callback commits
 * together or not at all: no sale without its stock movement, no stock
 * movement without its journal entry.
 *
 * SQLite gave the old app this for free with BEGIN IMMEDIATE, one writer at a
 * time. MySQL runs writers side by side, so each service locks what it reads
 * before writing based on it, always in the same order:
 *
 *   1. products (by id, ascending), 2. parties, 3. documents,
 *   4. the settings row (shared, via JournalService::readLockDate(), called
 *      before any number is taken; exclusive only in settings, tax and lock
 *      edits, which take it first and post nothing),
 *   5. batches (only ever reached through a product already locked, so their
 *      place after the settings row cannot deadlock),
 *   6. sequence rows (SequenceService::next; a document's own number first,
 *      then any batch numbers), 7. the journal (post()).
 *
 * A deadlock or lock-wait timeout retries the whole closure, so a closure must
 * not keep state outside itself between attempts. Any other failure rolls
 * everything back.
 */
final class Tx
{
    private const ATTEMPTS = 3;

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function run(Closure $work): mixed
    {
        try {
            return DB::transaction($work, self::ATTEMPTS);
        } catch (QueryException $e) {
            // MySQL 1062: two people saved the same code, name or unit at the
            // same moment, and the other one won. The checks before the insert
            // passed for both; the unique index caught the second.
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new ConflictError(
                    'Someone else saved the same code or name at the same moment. Nothing was saved; check it and try again.',
                    ['duplicate' => $e->errorInfo[2] ?? null],
                );
            }
            throw $e;
        }
    }

    /** For code that must only ever run inside a write, such as taking a document number. */
    public static function assertOpen(string $what): void
    {
        if (DB::transactionLevel() === 0) {
            throw new InvariantViolatedError("{$what} must run inside a transaction, or a rollback could not undo it.");
        }
    }
}
