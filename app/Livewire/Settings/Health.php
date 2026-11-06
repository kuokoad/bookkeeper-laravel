<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Auth\Principal;
use App\Auth\RoleGuard;
use App\Domain\Errors\DomainError;
use App\Enums\UserRole;
use App\Services\BackupStatusService;
use App\Services\PreflightService;
use App\Services\UpdateService;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Health & backup: the old (app)/settings/health page and its update panel.
 * The same PreflightService the nuna:preflight command runs, the backup
 * status, a download button (POST /settings/backup) and "Update now".
 * Restore stays on the command line, as in the old app.
 */
#[Title('Health & backup')]
final class Health extends Component
{
    /** @var array{ok: bool, reason?: string, changes?: list<array{hash: string, subject: string}>}|null */
    public ?array $check = null;

    public ?string $checkError = null;

    public ?string $startError = null;

    public bool $started = false;

    public bool $confirming = false;

    public function checkForUpdates(): void
    {
        $this->authorize('settings.edit');
        $this->checkError = null;
        if (($refused = $this->refusal('check for updates')) !== null) {
            $this->checkError = $refused;

            return;
        }
        $this->check = UpdateService::checkForUpdates(base_path());
    }

    public function startUpdate(): void
    {
        $this->authorize('settings.edit');
        $this->startError = null;
        if (($refused = $this->refusal('update the app')) !== null) {
            $this->startError = $refused;

            return;
        }
        if ((UpdateService::readUpdateStatus()['state'] ?? null) === 'running') {
            $this->startError = 'An update is already running.';

            return;
        }

        try {
            UpdateService::startUpdate(Principal::load((int) auth()->id())->actor());
        } catch (Throwable $e) {
            $this->startError = $e->getMessage();

            return;
        }
        $this->started = true;
    }

    public function render(): View
    {
        $user = Principal::load((int) auth()->id());
        $checks = PreflightService::run();
        $backup = BackupStatusService::get();
        $lastStatus = UpdateService::readUpdateStatus();

        return view('livewire.settings.health', [
            'canBackUp' => $user?->role === UserRole::Owner,
            'backup' => $backup,
            'backupLine' => BackupStatusService::describe($backup),
            'checks' => $checks,
            'failures' => count(array_filter($checks, fn (array $c): bool => $c['status'] === 'fail')),
            'warnings' => count(array_filter($checks, fn (array $c): bool => $c['status'] === 'warn')),
            'version' => UpdateService::getInstalledVersion(base_path()),
            'lastStatus' => $lastStatus,
            'isOwner' => $user?->role === UserRole::Owner,
            'unsupportedReason' => UpdateService::updateUnsupportedReason(),
            'updating' => $this->started || ($lastStatus['state'] ?? null) === 'running',
        ]);
    }

    /** Owner only, not merely settings edit: installing replaces the program that keeps the books. */
    private function refusal(string $what): ?string
    {
        try {
            RoleGuard::assertActorIsOwner(Principal::load((int) auth()->id())->actor(), $what);
        } catch (DomainError) {
            return 'Only the shop owner can update the app.';
        }

        return UpdateService::updateUnsupportedReason();
    }
}
