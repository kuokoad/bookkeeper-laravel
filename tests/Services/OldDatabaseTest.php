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

it('tidy leaves a -wal that has rows in it, so the next open still refuses the copy', function () {
    file_put_contents($this->copy.'-wal', 'rows');
    touch($this->copy.'-shm');

    OldDatabase::tidy($this->copy);

    expect(is_file($this->copy.'-wal'))->toBeTrue()
        ->and(is_file($this->copy.'-shm'))->toBeTrue()
        ->and(fn () => OldDatabase::open($this->copy))->toThrow(RuntimeException::class, '-wal or -shm');
});

it('tidy removes an empty -wal and its -shm, and is quiet when there are none', function () {
    touch($this->copy.'-wal');
    touch($this->copy.'-shm');

    OldDatabase::tidy($this->copy);
    OldDatabase::tidy($this->copy);

    expect(glob($this->copy.'-*'))->toBe([]);
});

it('leaves nothing beside a WAL-mode file that turns out not to be a NunaBooks database', function () {
    $other = $this->dir.DIRECTORY_SEPARATOR.'other.db';
    $pdo = new PDO('sqlite:'.$other);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE t (x)');
    $pdo = null;

    expect(fn () => OldDatabase::open($other))->toThrow(RuntimeException::class, 'not a NunaBooks database')
        ->and(glob($other.'-*'))->toBe([]);
});

it('reads every table from one snapshot, so a write to the file mid-import cannot land in it', function () {
    $old = OldDatabase::open($this->copy);
    $before = (int) $old->query('SELECT COUNT(*) FROM units')->fetchColumn();

    $writer = new PDO('sqlite:'.$this->copy, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 0]);
    expect(fn () => $writer->exec("UPDATE units SET name = name || 'x'"))->toThrow(PDOException::class, 'locked')
        ->and((int) $old->query('SELECT COUNT(*) FROM units')->fetchColumn())->toBe($before);
    unset($old, $writer);
});
