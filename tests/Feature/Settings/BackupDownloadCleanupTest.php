<?php

declare(strict_types=1);

/*
| Review finding: the cleanup deleted any backup-download folder over an hour
| old, so a slow download still running had its dump deleted under it. Now a
| folder goes only when no live download holds its lock, however old it is.
*/

use App\Http\Controllers\Settings\BackupController;
use Illuminate\Support\Facades\File;

it('keeps a download folder whose lock is held, however old, and deletes an abandoned one', function () {
    $live = storage_path('app/backup-download-test-live');
    $left = storage_path('app/backup-download-test-left');
    foreach ([$live, $left] as $dir) {
        File::ensureDirectoryExists($dir);
        file_put_contents("{$dir}/dump.sql.gz", 'x');
        touch($dir, time() - 7200);
    }
    // A download still running: it holds its lock, as the controller does.
    $held = fopen($live.DIRECTORY_SEPARATOR.BackupController::LOCK_FILE, 'c');
    flock($held, LOCK_EX);

    try {
        expect(BackupController::deleteIfAbandoned($live))->toBeFalse()
            ->and(is_file("{$live}/dump.sql.gz"))->toBeTrue()
            ->and(BackupController::deleteIfAbandoned($left))->toBeTrue()
            ->and(is_dir($left))->toBeFalse();
    } finally {
        flock($held, LOCK_UN);
        fclose($held);
        File::deleteDirectory($live);
        File::deleteDirectory($left);
    }

    // Once its download ends, the same folder is a leftover.
    File::ensureDirectoryExists($live);
    expect(BackupController::deleteIfAbandoned($live))->toBeTrue();
});
