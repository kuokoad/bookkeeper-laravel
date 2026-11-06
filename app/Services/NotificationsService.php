<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Principal;
use App\Domain\FinancialYear;
use App\Enums\PermissionModule;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\LedgerService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Things that genuinely need attention, each a condition that holds right
 * now, read from the books: a port of the old services/notifications.service.ts.
 * Filtered by permission, except that unbalanced books are everyone's
 * business. Reads only.
 */
final class NotificationsService
{
    /** @return list<array{id: string, tone: 'danger'|'warning'|'info', title: string, detail: string, href: string}> */
    public static function getNotices(Principal $user): array
    {
        $notices = [];
        $today = Clock::today();
        $plural = fn (int $n, string $word): string => "{$n} {$word}".($n === 1 ? '' : 's');

        if (! BalancesService::getTrialBalance()['balanced']) {
            $notices[] = ['id' => 'unbalanced', 'tone' => 'danger', 'title' => 'The books do not balance', 'detail' => 'Stop recording and report this.', 'href' => '/accounting'];
        }

        if ($user->can(PermissionModule::Products, 'view')) {
            $stock = CatalogService::getStockSummary();
            if ($stock['outOfStockCount'] > 0) {
                $notices[] = ['id' => 'out-of-stock', 'tone' => 'danger', 'title' => $plural($stock['outOfStockCount'], 'product').' out of stock', 'detail' => 'Nothing left to sell.', 'href' => '/products?low=1'];
            } elseif ($stock['lowStockCount'] > 0) {
                $notices[] = ['id' => 'low-stock', 'tone' => 'warning', 'title' => $plural($stock['lowStockCount'], 'product').' running low', 'detail' => 'Time to reorder.', 'href' => '/products?low=1'];
            }

            // Stock that has turned crowds out the warning about stock that is going to.
            $expiry = CatalogService::getExpirySummary($today);
            if ($expiry['expiredCount'] > 0) {
                $notices[] = ['id' => 'expired-stock', 'tone' => 'danger', 'title' => $plural($expiry['expiredCount'], 'product').' with expired stock', 'detail' => 'It cannot be sold. Write it off to take it out of the accounts.', 'href' => '/products?expiring=expired'];
            } elseif ($expiry['expiringSoonCount'] > 0) {
                // The days are named only while every product counted agrees on them.
                $notices[] = [
                    'id' => 'expiring-soon', 'tone' => 'warning',
                    'title' => $plural($expiry['expiringSoonCount'], 'product').($expiry['uniformWindow'] ? " expiring within {$expiry['warningDays']} days" : ' expiring soon'),
                    'detail' => 'Still sellable. Move it first.', 'href' => '/products?expiring=soon',
                ];
            }
        }

        if ($user->can(PermissionModule::Customers, 'view')) {
            $overdue = array_filter(LedgerService::getReceivablesAgeing($today), fn (array $row): bool => $row['days31to60'] + $row['days61to90'] + $row['over90'] > 0);
            if ($overdue !== []) {
                $notices[] = ['id' => 'overdue', 'tone' => 'warning', 'title' => $plural(count($overdue), 'customer').' owing over 30 days', 'detail' => 'Worth a reminder.', 'href' => '/accounting/receivables'];
            }
        }

        // Owner-level housekeeping.
        if ($user->can(PermissionModule::Settings, 'edit')) {
            $startMonth = (int) DB::table('business_settings')->where('id', 1)->value('financial_year_start_month');
            if ($startMonth > 0) {
                $lastYear = FinancialYear::for($today, $startMonth)->startYear - 1;
                // As the old app: "has entries" counts audit rows, not journal entries.
                if (DB::table('audit_logs')->exists() && ! YearEndCloseService::isYearClosed($lastYear)) {
                    $notices[] = ['id' => 'year-open', 'tone' => 'info', 'title' => 'Last financial year is not closed', 'detail' => 'Closing it fixes the figures and locks the period.', 'href' => '/accounting'];
                }
            }

            $backup = BackupStatusService::get();
            if ($backup['state'] !== 'current') {
                $notices[] = [
                    'id' => $backup['state'] === 'never' ? 'never-backed-up' : 'stale-backup',
                    'tone' => $backup['state'] === 'due' ? 'warning' : 'danger',
                    'title' => match (true) {
                        $backup['state'] === 'never' => 'No backup has ever been taken',
                        $backup['entriesSince'] > 0 => $backup['entriesSince'].' entr'.($backup['entriesSince'] === 1 ? 'y' : 'ies').' not yet backed up',
                        default => "Last backup was {$backup['daysSince']} days ago",
                    },
                    'detail' => BackupStatusService::describe($backup),
                    'href' => '/settings/health',
                ];
            }
        }

        return $notices;
    }
}
