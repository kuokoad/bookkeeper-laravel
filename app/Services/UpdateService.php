<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Updating the app from its own Health screen: a port of the old
 * services/update.service.ts. The shop PC is a git clone, so "is there an
 * update?" is "has the tracked branch moved on?". Updating is
 * scripts/update.ps1 (backup, stop Apache, pull, composer install, migrate,
 * start; roll back on failure), run by the "NunaBooksUpdate" scheduled task
 * the installer registers: the web server cannot update itself, and a child
 * of Apache would die with it when the script stops the service. The script
 * reports back through a status file outside the code.
 *
 * Nothing here touches the books.
 */
final class UpdateService
{
    public const TASK_NAME = 'NunaBooksUpdate';

    /** An update silent this long is not running: the PC was switched off mid-way. */
    public const STALE_AFTER_MINUTES = 30;

    /**
     * The environment git runs in: never waits for a password, and trusts
     * the app folder even when an Administrator cloned it (git's "dubious
     * ownership"), at the command-line scope git accepts for that.
     *
     * @return array<string, string>
     */
    public static function gitEnv(string $cwd): array
    {
        return [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'safe.directory',
            'GIT_CONFIG_VALUE_0' => str_replace('\\', '/', $cwd),
        ];
    }

    /** @return array{hash: string, date: string}|null */
    public static function getInstalledVersion(string $cwd): ?array
    {
        try {
            [$hash, $date] = array_pad(explode("\t", self::git($cwd, ['log', '-1', '--format=%h%x09%cs'])), 2, '');

            return $hash === '' ? null : ['hash' => $hash, 'date' => $date];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{ok: true, upstream: string, changes: list<array{hash: string, subject: string}>}|array{ok: false, reason: string} */
    public static function checkForUpdates(string $cwd): array
    {
        try {
            $upstream = self::git($cwd, ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}']);
        } catch (Throwable) {
            return ['ok' => false, 'reason' => 'This copy of the app is not linked to a place to download updates from. It needs to be installed with git clone, as the README describes.'];
        }
        try {
            self::git($cwd, ['fetch', '--quiet'], 60);
        } catch (Throwable) {
            return ['ok' => false, 'reason' => 'Could not reach the update server. Check this computer is online. If the repository is private, set up a deploy key on this computer once: the app cannot sign in to GitHub itself.'];
        }

        $log = self::git($cwd, ['log', '--format=%h%x09%s', "HEAD..{$upstream}"]);
        $changes = [];
        foreach (array_filter(explode("\n", $log), fn (string $l): bool => trim($l) !== '') as $line) {
            [$hash, $subject] = array_pad(explode("\t", rtrim($line, "\r"), 2), 2, '');
            $changes[] = ['hash' => $hash, 'subject' => $subject];
        }

        return ['ok' => true, 'upstream' => $upstream, 'changes' => $changes];
    }

    /** @return array{state: string, startedAt: string, finishedAt: ?string, from: ?string, to: ?string, message: string}|null */
    public static function readUpdateStatus(?string $path = null, ?Carbon $now = null): ?array
    {
        $path ??= (string) config('nuna.update_status_path');
        if (! is_file($path)) {
            return null;
        }
        $status = json_decode((string) file_get_contents($path), true);
        if (! is_array($status) || ! in_array($status['state'] ?? null, ['running', 'done', 'failed'], true)) {
            return null;
        }
        $now ??= Clock::now();
        if ($status['state'] === 'running' && $now->greaterThan(Carbon::parse((string) $status['startedAt'])->addMinutes(self::STALE_AFTER_MINUTES))) {
            return [...$status, 'state' => 'failed', 'message' => 'The last update never reported back. Check the app is on the version you expect, and look in storage/logs/update.log.'];
        }

        return $status;
    }

    /** Why this copy cannot update itself from the screen, or null when it can. */
    public static function updateUnsupportedReason(?string $env = null, ?string $os = null): ?string
    {
        if (($env ?? (string) config('app.env')) !== 'production') {
            return 'Updates run only on the shop computer, not in development.';
        }
        if (($os ?? PHP_OS_FAMILY) !== 'Windows') {
            return 'Updating from this screen needs Windows. Use git pull and scripts/update.ps1 instead.';
        }

        return null;
    }

    /** Marks the update running and hands over to the scheduled task; returns at once. */
    public static function startUpdate(?string $statusPath = null): void
    {
        $statusPath ??= (string) config('nuna.update_status_path');
        file_put_contents($statusPath, json_encode([
            'state' => 'running', 'startedAt' => Clock::now()->toIso8601ZuluString('millisecond'), 'finishedAt' => null, 'from' => null, 'to' => null, 'message' => 'Updating.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $result = Process::run(['schtasks', '/Run', '/TN', self::TASK_NAME]);
        if (! $result->successful()) {
            @unlink($statusPath);
            throw new \RuntimeException('Could not start the update task ('.self::TASK_NAME.'). Run scripts\\install.ps1 again, or scripts\\update.ps1 by hand. '.trim($result->errorOutput()));
        }
    }

    private static function git(string $cwd, array $arguments, int $timeout = 15): string
    {
        $result = Process::path($cwd)->env(self::gitEnv($cwd))->timeout($timeout)->run(['git', ...$arguments]);
        if (! $result->successful()) {
            throw new \RuntimeException(trim($result->errorOutput()));
        }

        return trim($result->output());
    }
}
