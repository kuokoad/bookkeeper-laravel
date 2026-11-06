<?php

declare(strict_types=1);

/*
| One till, as a separate PHP process, for ConcurrencyTest. Takes a receipt
| number and posts a journal entry in one transaction, N times, and every
| fifth time throws after numbering so the transaction rolls back. Prints one
| JSON line per committed write.
|
| Usage: php worker.php <iterations> <worker-id>   (DB_DATABASE from the environment)
*/

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Enums\DocType;
use App\Enums\JournalSourceType;
use App\Services\JournalService;
use App\Services\SequenceService;
use Illuminate\Support\Facades\DB;

require __DIR__.'/boot.php';

[$iterations, $worker] = [(int) $argv[1], (int) $argv[2]];
$cash = (int) DB::table('accounts')->where('code', '1001')->value('id');
$sales = (int) DB::table('accounts')->where('code', ChartOfAccounts::SALES_REVENUE)->value('id');

// Start together: wait for the go file the test writes once every worker is up.
$go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nunabooks-concurrency-go-'.env('DB_DATABASE');
while (! file_exists($go)) {
    usleep(5_000);
}

for ($i = 1; $i <= $iterations; $i++) {
    startRound($go, $i, $worker);
    $start = microtime(true);
    try {
        $result = Tx::run(function () use ($i, $worker, $cash, $sales): array {
            // The lock order every service follows: the books lock, then numbers, then the journal.
            JournalService::readLockDate();
            $receipt = SequenceService::next(DocType::Receipt);
            // Hold the number while "the till works", so the other tills have to wait for it.
            usleep(3_000);
            if ($i % 5 === 0) {
                throw new RuntimeException('till lost power');
            }
            $entry = JournalService::post('2026-11-05', JournalSourceType::Sale, [
                Journal::debit($cash, 100 * $worker),
                Journal::credit($sales, 100 * $worker),
            ], sourceId: $i);
            // Sometimes the failure comes after the journal number is taken, too.
            if ($i % 7 === 0) {
                throw new RuntimeException('till lost power');
            }

            return ['receipt' => $receipt, 'entry' => $entry['entryNo']];
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'till lost power') {
            throw $e;
        }
        $result = [];
    }
    // When this till's write began and ended, rolled back or not: the test checks the tills overlapped.
    echo json_encode(['round' => $i, 'start' => $start, 'end' => microtime(true), ...$result]), "\n";
}
