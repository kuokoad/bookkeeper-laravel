<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Auth\Principal;
use App\Auth\RoleGuard;
use App\Domain\Errors\DomainError;
use App\Http\Controllers\Controller;
use App\Services\BackupService;
use App\Services\SettingsService;
use App\Support\HttpThrottle;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Downloads a verified backup from the Health screen: the old api/backup
 * route, as a POST (it writes an audit row and is expensive). Made in a
 * throwaway folder and removed once read, so copies do not pile up beside
 * the database they protect. An unverified copy is never sent.
 */
final class BackupController extends Controller
{
    public function __invoke(): Response
    {
        if (Gate::denies('settings.edit')) {
            return response('You do not have permission to download a backup.', 403, ['Content-Type' => 'text/plain']);
        }
        $principal = Principal::load((int) auth()->id());
        // D31: owner only, as updates are: the file holds every customer and every figure.
        try {
            RoleGuard::assertActorIsOwner($principal->actor(), 'download a backup');
        } catch (DomainError) {
            return response('Only the shop owner can download a backup.', 403, ['Content-Type' => 'text/plain']);
        }
        if (($throttled = HttpThrottle::check("backup:{$principal->id}", HttpThrottle::BACKUP)) !== null) {
            return $throttled;
        }

        // A timeout is fatal and skips every `finally`, leaving the scratch database,
        // the dump and the password file behind; a closed tab must not stop it halfway either.
        set_time_limit(0);
        ignore_user_abort(true);
        // FastCGI can still kill a very long one, skipping the `finally` and leaving the dump and
        // the password file behind. Each download holds a lock in its folder until it ends, and
        // the OS lets go when its process dies: a folder whose lock can be taken is a leftover.
        foreach (File::glob(storage_path('app/backup-download-*'), GLOB_ONLYDIR) as $other) {
            self::deleteIfAbandoned($other);
        }
        $directory = storage_path('app/backup-download-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory);
        $lock = fopen($directory.DIRECTORY_SEPARATOR.self::LOCK_FILE, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            return response('The backup could not be made: its working folder could not be locked.', 500, ['Content-Type' => 'text/plain']);
        }
        try {
            $result = BackupService::createBackup($directory, 1);
            $body = (string) file_get_contents($result['path']);
            $shop = trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', SettingsService::get()->business_name), '-');
            $filename = strtolower($shop === '' ? 'shop' : $shop).'-'.basename($result['path']);
            BackupService::recordBackupTaken($result['entries'], $principal->actor(), 'from the Health screen');

            return response($body, 200, [
                'Content-Type' => 'application/gzip',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Content-Length' => (string) strlen($body),
                // A backup is a point-in-time copy; a cached one would be a lie.
                'Cache-Control' => 'no-store',
            ]);
        } catch (Throwable $e) {
            // Never answer a failed backup with something that looks like a file.
            Log::error('Backup download failed: '.$e->getMessage());

            return response('The backup could not be made: '.$e->getMessage(), 500, ['Content-Type' => 'text/plain']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            File::deleteDirectory($directory);
        }
    }

    /** Held by a download for as long as it runs. */
    public const LOCK_FILE = 'in-progress.lock';

    /** A download folder no live download holds: delete it. One still held is left alone, however old. */
    public static function deleteIfAbandoned(string $directory): bool
    {
        $lock = @fopen($directory.DIRECTORY_SEPARATOR.self::LOCK_FILE, 'c');
        if ($lock === false) {
            return false;
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return false;
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        File::deleteDirectory($directory);

        return true;
    }
}
