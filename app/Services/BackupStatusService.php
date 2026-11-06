<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How long ago the shop last took a backup, and whether to say so yet: a
 * port of the old services/backup.service.ts. There is no backup table:
 * each backup writes an audit row (entity "backup", action CREATE).
 *
 * Two rules, either one enough: a week without a backup whatever happened,
 * or a day once there is trading nobody has saved.
 */
final class BackupStatusService
{
    private const DUE_AFTER_DAYS = 1;

    private const OVERDUE_AFTER_DAYS = 7;

    /** @return array{lastTakenAt: ?Carbon, daysSince: ?int, entriesSince: int, state: 'never'|'current'|'due'|'overdue'} */
    public static function get(?Carbon $now = null): array
    {
        $now ??= Clock::now();
        $last = DB::table('audit_logs')->where('audit_logs.entity_type', 'backup')->where('audit_logs.action', 'CREATE')
            ->orderByDesc('audit_logs.created_at')->value('audit_logs.created_at');
        $lastTakenAt = $last === null ? null : Clock::fromStamp($last);

        // Everything, when there has never been a backup: all of it is unsaved.
        $entriesSince = DB::table('journal_entries')
            ->when($last !== null, fn ($q) => $q->where('journal_entries.created_at', '>', $last))
            ->count();

        if ($lastTakenAt === null) {
            return ['lastTakenAt' => null, 'daysSince' => null, 'entriesSince' => $entriesSince, 'state' => 'never'];
        }

        $daysSince = intdiv($now->getTimestampMs() - $lastTakenAt->getTimestampMs(), 86_400_000);
        $state = match (true) {
            $daysSince >= self::OVERDUE_AFTER_DAYS => 'overdue',
            $entriesSince > 0 && $daysSince >= self::DUE_AFTER_DAYS => 'due',
            default => 'current',
        };

        return ['lastTakenAt' => $lastTakenAt, 'daysSince' => $daysSince, 'entriesSince' => $entriesSince, 'state' => $state];
    }

    /** One line a person can act on, for the dashboard and the Health screen. */
    public static function describe(array $status): string
    {
        $one = $status['entriesSince'] === 1;
        $entries = $status['entriesSince'].' entr'.($one ? 'y' : 'ies');
        $haveBeen = $one ? 'has been' : 'have been';

        switch ($status['state']) {
            case 'never':
                return $status['entriesSince'] === 0
                    ? 'No backup has ever been taken. Nothing has been recorded yet, so start the habit now.'
                    : "No backup has ever been taken. {$entries} would be lost with this computer.";
            case 'overdue':
            case 'due':
                $when = match ($status['daysSince']) {
                    0 => 'earlier today',
                    1 => 'yesterday',
                    default => "{$status['daysSince']} days ago",
                };

                return $status['entriesSince'] === 0
                    ? "Last backup was {$when}, and nothing has been posted since."
                    : "Last backup was {$when}. {$entries} {$haveBeen} posted since.";
            default:
                return $status['daysSince'] === 0
                    ? 'Backed up earlier today. Nothing posted since.'
                    : "Backed up {$status['daysSince']} day".($status['daysSince'] === 1 ? '' : 's').' ago. Nothing posted since.';
        }
    }
}
