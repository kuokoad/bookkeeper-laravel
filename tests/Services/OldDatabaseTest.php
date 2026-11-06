<?php

declare(strict_types=1);

/*
| The importer and reconcile read a COPY of the old database, never the live
| file, and read it as one snapshot. The copies live in a temp folder; the
| real live path is only ever named in config, never created.
*/

use App\Services\Migration\OldDatabase;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nuna-olddb-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    $this->copy = $this->dir.DIRECTORY_SEPARATOR.'bookkeeper.db';
    copy(base_path('tests/Fixtures/old-demo.db'), $this->copy);
});

afterEach(function () {
    foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->dir);
});

it('refuses the live old database, however its path is spelled', function () {
    config(['nuna.old_live_database' => $this->copy]);
    $spelled = PHP_OS_FAMILY === 'Windows' ? strtoupper($this->copy) : $this->dir.DIRECTORY_SEPARATOR.'.'.DIRECTORY_SEPARATOR.'bookkeeper.db';

    expect(fn () => OldDatabase::open($spelled))->toThrow(RuntimeException::class, 'the old app\'s live database');
});

it('refuses a file with a -wal or -shm beside it, which may not hold every saved row yet', function (string $suffix) {
    touch($this->copy.$suffix);

    expect(fn () => OldDatabase::open($this->copy))->toThrow(RuntimeException::class, 'copy bookkeeper.db while the old app is stopped');
})->with(['-wal', '-shm']);

it('reads every table from one snapshot, so a write to the file mid-import cannot land in it', function () {
    $old = OldDatabase::open($this->copy);
    $before = (int) $old->query('SELECT COUNT(*) FROM units')->fetchColumn();

    $writer = new PDO('sqlite:'.$this->copy, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 0]);
    expect(fn () => $writer->exec("UPDATE units SET name = name || 'x'"))->toThrow(PDOException::class, 'locked')
        ->and((int) $old->query('SELECT COUNT(*) FROM units')->fetchColumn())->toBe($before);
    unset($old, $writer);
});
