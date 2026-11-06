<?php

declare(strict_types=1);

/*
| Port of the old tests/services/update.test.ts: "Check for updates" and what
| the Health page says about the last update, with real git repositories.
| The update itself (scripts/update.ps1) stops the web server, so it is not
| run here.
*/

use App\Services\UpdateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;

function gitIn(string $cwd, string ...$arguments): string
{
    $result = Process::path($cwd)->timeout(60)->run(['git', '-c', 'user.name=test', '-c', 'user.email=test@example.com', ...$arguments]);
    expect($result->successful())->toBeTrue($result->errorOutput());

    return $result->output();
}

function commitIn(string $cwd, string $file, string $message): void
{
    file_put_contents($cwd.DIRECTORY_SEPARATOR.$file, $message);
    gitIn($cwd, 'add', $file);
    gitIn($cwd, 'commit', '-q', '-m', $message);
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nuna-update-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function () {
    // git marks its objects read-only on Windows.
    Process::run(PHP_OS_FAMILY === 'Windows' ? ['cmd', '/c', 'rmdir', '/s', '/q', $this->dir] : ['rm', '-rf', $this->dir]);
});

describe('checking for updates', function () {
    beforeEach(function () {
        $origin = $this->dir.DIRECTORY_SEPARATOR.'origin.git';
        gitIn($this->dir, 'init', '-q', '--bare', '-b', 'main', $origin);
        $this->developer = $this->dir.DIRECTORY_SEPARATOR.'developer';
        gitIn($this->dir, 'clone', '-q', $origin, $this->developer);
        commitIn($this->developer, 'a.txt', 'First release');
        gitIn($this->developer, 'push', '-q', 'origin', 'main');
        $this->shop = $this->dir.DIRECTORY_SEPARATOR.'shop';
        gitIn($this->dir, 'clone', '-q', $origin, $this->shop);
    });

    it('says the shop is on the newest version when nothing has been released since', function () {
        expect(UpdateService::checkForUpdates($this->shop))->toBe(['ok' => true, 'upstream' => 'origin/main', 'changes' => []])
            ->and(UpdateService::getInstalledVersion($this->shop)['hash'])->not->toBe('');
    });

    it('lists what has been released since, newest first', function () {
        commitIn($this->developer, 'b.txt', 'Receipts show the tax number');
        commitIn($this->developer, 'c.txt', 'Fix: stock count after a return');
        gitIn($this->developer, 'push', '-q', 'origin', 'main');

        $result = UpdateService::checkForUpdates($this->shop);
        expect($result['ok'])->toBeTrue()
            ->and(array_column($result['changes'], 'subject'))->toBe(['Fix: stock count after a return', 'Receipts show the tax number']);
    });

    it('explains instead of failing when the copy was not installed with git clone', function () {
        $loose = $this->dir.DIRECTORY_SEPARATOR.'loose';
        gitIn($this->dir, 'init', '-q', '-b', 'main', $loose);
        commitIn($loose, 'a.txt', 'Copied by hand');

        $result = UpdateService::checkForUpdates($loose);
        expect($result['ok'])->toBeFalse()->and($result['reason'])->toContain('git clone')
            ->and(UpdateService::getInstalledVersion($this->dir.DIRECTORY_SEPARATOR.'nowhere'))->toBeNull();
    });
});

it('marks the app folder safe at the command-line scope git trusts', function () {
    $result = Process::path($this->dir)->env(UpdateService::gitEnv($this->dir))->run(['git', 'config', '--show-scope', '--get-all', 'safe.directory']);
    expect(preg_split('/\r?\n/', trim($result->output())))->toContain("command\t".str_replace('\\', '/', $this->dir));
});

describe('the last update, as the Health page reports it', function () {
    beforeEach(function () {
        $this->started = Carbon::parse('2026-10-19T09:00:00Z');
        $this->path = $this->dir.DIRECTORY_SEPARATOR.'update-status.json';
        $this->write = fn (string $state, string $message = 'Updating.') => file_put_contents($this->path, json_encode(['state' => $state, 'startedAt' => '2026-10-19T09:00:00.000Z', 'finishedAt' => null, 'from' => 'abc1234', 'to' => null, 'message' => $message]));
    });

    it('is nothing when there has never been an update', function () {
        expect(UpdateService::readUpdateStatus($this->path))->toBeNull();
    });

    it('reads back what the updater wrote', function () {
        ($this->write)('done', 'Updated.');
        expect(UpdateService::readUpdateStatus($this->path, $this->started))->toMatchArray(['state' => 'done', 'message' => 'Updated.']);
    });

    it('stops calling an update "running" once it has been silent too long, so the button unlocks', function () {
        ($this->write)('running');
        expect(UpdateService::readUpdateStatus($this->path, $this->started->copy()->addMinute())['state'])->toBe('running')
            ->and(UpdateService::readUpdateStatus($this->path, $this->started->copy()->addMinutes(UpdateService::STALE_AFTER_MINUTES)->addSecond())['state'])->toBe('failed');
    });

    it('ignores a damaged status file rather than breaking the Health page', function () {
        file_put_contents($this->path, '{ not json');
        expect(UpdateService::readUpdateStatus($this->path))->toBeNull();
    });
});

it('allows updating from the screen only on a production Windows install', function () {
    expect(UpdateService::updateUnsupportedReason('local', 'Windows'))->not->toBeNull()
        ->and(UpdateService::updateUnsupportedReason('production', 'Linux'))->not->toBeNull()
        ->and(UpdateService::updateUnsupportedReason('production', 'Windows'))->toBeNull();
});
