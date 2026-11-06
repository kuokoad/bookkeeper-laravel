<?php

declare(strict_types=1);

/*
| Port of the old tests/services/notifications.test.ts (the notices; its
| write-off and per-product window cases test the inventory and catalog
| services, ported with them in Phase 3) and tests/services/backup-status.test.ts.
*/

use App\Auth\Principal;
use App\Domain\BusinessDate;
use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Services\AuditService;
use App\Services\BackupStatusService;
use App\Services\NotificationsService;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->owner = new Principal($this->actor->id, 'kwame', 'Kwame Owusu', UserRole::Owner);
    $this->till = new Principal(2, 'ama', 'Ama', UserRole::Staff, ['sales' => ['canView' => true, 'canCreate' => true, 'canEdit' => false, 'canVoid' => false]]);
    $this->ids = fn (Principal $p): array => array_column(NotificationsService::getNotices($p), 'id');
    $this->notice = fn (string $id): ?array => collect(NotificationsService::getNotices($this->owner))->firstWhere('id', $id);
    $this->recordBackupAt = fn (Carbon $at) => AuditService::write(AuditAction::Create, 'backup', 'Downloaded a backup', null, $this->actor, at: $at);
    $this->inDays = fn (int $days): string => BusinessDate::addDays(Clock::today(), $days);
    $this->deliver = fn (int $id, int $units, ?string $expiry): array => Shop::move([
        'productId' => $id, 'direction' => 'IN', 'qty' => $units * 1000, 'totalCost' => 100 * $units,
        'movementType' => 'PURCHASE', 'sourceType' => 'TEST', 'businessDate' => ($this->inDays)(-25),
        'occurredAt' => Clock::now(), 'userId' => $this->actor->id, 'batch' => ['kind' => 'NEW', 'expiryDate' => $expiry],
    ]);
});

describe('what the owner is told', function () {
    it('warns that no backup has ever been taken, and stops once one is recorded', function () {
        expect(($this->ids)($this->owner))->toContain('never-backed-up');
        ($this->recordBackupAt)(Clock::now());
        expect(($this->ids)($this->owner))->not->toContain('never-backed-up')->not->toContain('stale-backup');
    });

    it('warns again when the last backup is old', function () {
        ($this->recordBackupAt)(Clock::now()->subDays(8));
        expect(($this->notice)('stale-backup')['title'])->toContain('8 days ago');
    });

    it('reports products that have run out', function () {
        $milo = Shop::product($this->actor, 'Milo', 1_000, 1_400);
        Shop::openingStock($this->actor, $milo, 10_000, 10_000);
        Shop::stockOut($this->actor, $milo, 10_000, '2026-08-17');
        expect(($this->ids)($this->owner))->toContain('out-of-stock');
    });
});

describe('every notice is a real condition', function () {
    it('says nothing about stock, overdue money or broken books when there is none', function () {
        expect(($this->ids)($this->owner))->not->toContain('out-of-stock')->not->toContain('low-stock')->not->toContain('overdue')->not->toContain('unbalanced');
    });

    it('every notice links somewhere that can act on it', function () {
        foreach (NotificationsService::getNotices($this->owner) as $notice) {
            expect($notice['href'])->toStartWith('/')->and($notice['title'])->not->toBe('')->and($notice['detail'])->not->toBe('');
        }
    });

    it('reads and never writes', function () {
        DB::enableQueryLog();
        NotificationsService::getNotices($this->owner);
        foreach (DB::getQueryLog() as $q) {
            expect(strtolower(ltrim($q['query'])))->toStartWith('select');
        }
    });
});

describe('permission', function () {
    it('does not show till staff the owner housekeeping or stock warnings', function () {
        expect(($this->ids)($this->till))->not->toContain('never-backed-up')->not->toContain('year-open')->not->toContain('low-stock')->not->toContain('out-of-stock');
    });

    it('shows unbalanced books to EVERYONE', function () {
        Shop::postOpeningCapital();
        DB::table('journal_lines')->where('debit_minor', '>', 0)->update(['debit_minor' => DB::raw('debit_minor + 1')]);
        expect(($this->ids)($this->owner))->toContain('unbalanced')
            ->and(($this->ids)($this->till))->toContain('unbalanced');
    });
});

describe('being told about dates', function () {
    it('says nothing when no stock carries a date, or every date is comfortably ahead', function () {
        ($this->deliver)(Shop::product($this->actor, 'Rice 5kg', 100, 200), 20, null);
        ($this->deliver)(Shop::product($this->actor, 'Milo 400g', 100, 200), 20, ($this->inDays)(300));
        expect(($this->ids)($this->owner))->not->toContain('expired-stock')->not->toContain('expiring-soon');
    });

    it('warns when a date falls inside the window, counting products rather than crates', function () {
        $milk = Shop::product($this->actor, 'Evaporated Milk', 100, 200);
        ($this->deliver)($milk, 10, ($this->inDays)(7));
        ($this->deliver)($milk, 10, ($this->inDays)(14));
        $notice = ($this->notice)('expiring-soon');
        expect($notice['tone'])->toBe('warning')
            ->and($notice['title'])->toBe('1 product expiring within 30 days')
            ->and($notice['href'])->toBe('/products?expiring=soon');
    });

    it('raises it to danger once something has turned, crowding out the expiring one', function () {
        ($this->deliver)(Shop::product($this->actor, 'Evaporated Milk', 100, 200), 20, ($this->inDays)(-24));
        ($this->deliver)(Shop::product($this->actor, 'Tea Bread', 100, 200), 20, ($this->inDays)(16));
        $notice = ($this->notice)('expired-stock');
        expect($notice['tone'])->toBe('danger')
            ->and($notice['href'])->toBe('/products?expiring=expired')
            ->and(($this->ids)($this->owner))->not->toContain('expiring-soon');
    });

    it('still tells a building materials yard about stock it has already dated', function () {
        DB::table('business_settings')->where('id', 1)->update(['business_type' => 'building_materials', 'feature_expiry_batches' => false]);
        ($this->deliver)(Shop::product($this->actor, 'Evaporated Milk', 100, 200), 20, ($this->inDays)(-24));
        expect(($this->ids)($this->owner))->toContain('expired-stock');
    });

    it('names the number of days only while every product agrees on it', function () {
        ($this->deliver)(Shop::product($this->actor, 'Evaporated Milk', 100, 200), 10, ($this->inDays)(20));
        expect(($this->notice)('expiring-soon')['title'])->toContain('within 30 days');
        ($this->deliver)(Shop::product($this->actor, 'Tea Bread', 100, 200, ['warnDays' => 3]), 10, ($this->inDays)(2));
        expect(($this->notice)('expiring-soon')['title'])->toBe('2 products expiring soon');
    });

    it('hides both from someone who cannot see products', function () {
        ($this->deliver)(Shop::product($this->actor, 'Evaporated Milk', 100, 200), 20, ($this->inDays)(-24));
        expect(($this->ids)($this->till))->not->toContain('expired-stock')->not->toContain('expiring-soon');
    });
});

describe('money owed and the year', function () {
    it('tells the owner about customers owing over 30 days', function () {
        $rice = Shop::product($this->actor, 'Rice', 500, 800);
        Shop::openingStock($this->actor, $rice, 10_000, 5_000, ($this->inDays)(-60));
        $customer = Shop::customer($this->actor);
        Shop::sell($this->actor, [[$rice, 1_000]], [], ['businessDate' => ($this->inDays)(-45), 'customerId' => $customer, 'termsDays' => 5]);
        expect(($this->notice)('overdue')['title'])->toBe('1 customer owing over 30 days');
    });

    it('says when last financial year is not closed', function () {
        Shop::postOpeningCapital();
        expect(($this->notice)('year-open')['tone'])->toBe('info');
    });
});

describe('backup status', function () {
    beforeEach(function () {
        $this->now = Carbon::parse('2026-08-24 18:00:00', 'UTC');
        $this->backup = fn (int $daysAgo) => ($this->recordBackupAt)($this->now->copy()->subDays($daysAgo));
        $this->entry = function (int $daysAgo, string $no): void {
            $at = Clock::stamp($this->now->copy()->subDays($daysAgo));
            DB::table('journal_entries')->insert(['entry_no' => $no, 'entry_date' => '2026-08-20', 'occurred_at' => $at, 'source_type' => 'SALE', 'source_id' => 1, 'memo' => 'test', 'created_at' => $at]);
        };
        $this->status = fn (): array => BackupStatusService::get($this->now);
    });

    it('a shop that has never backed up is told so before it trades, and plainly once it has', function () {
        expect(($this->status)()['state'])->toBe('never')
            ->and(BackupStatusService::describe(($this->status)()))->toContain('start the habit now');
        ($this->entry)(0, 'JE-1');
        expect(($this->status)()['entriesSince'])->toBe(1)
            ->and(BackupStatusService::describe(($this->status)()))->toContain('would be lost');
    });

    it('is left alone on the same day', function () {
        ($this->backup)(0);
        ($this->entry)(0, 'JE-1');
        expect(($this->status)()['state'])->toBe('current');
    });

    it('is due after a day of unsaved trading, and overdue after a week of it', function () {
        ($this->backup)(2);
        ($this->entry)(1, 'JE-1');
        expect(($this->status)())->toMatchArray(['state' => 'due', 'daysSince' => 2, 'entriesSince' => 1]);
        $this->now = $this->now->copy()->addDays(7);
        expect(($this->status)()['state'])->toBe('overdue');
    });

    it('agrees its verb with the count', function () {
        ($this->backup)(2);
        ($this->entry)(1, 'JE-1');
        expect(BackupStatusService::describe(($this->status)()))->toContain('1 entry has been');
        ($this->entry)(1, 'JE-2');
        expect(BackupStatusService::describe(($this->status)()))->toContain('2 entries have been');
    });

    it('counts only what came after the backup, and the most recent backup is the one that counts', function () {
        ($this->entry)(10, 'JE-old');
        ($this->backup)(20);
        ($this->backup)(5);
        ($this->entry)(2, 'JE-new');
        expect(($this->status)())->toMatchArray(['entriesSince' => 1, 'daysSince' => 5]);
    });

    it('a shut shop is still reminded after a week, and told nothing is at risk', function () {
        ($this->entry)(45, 'JE-before');
        ($this->backup)(30);
        expect(($this->status)())->toMatchArray(['state' => 'overdue', 'entriesSince' => 0])
            ->and(BackupStatusService::describe(($this->status)()))->toContain('nothing has been posted since');
    });

    it('is left alone inside the week when nothing has been posted', function () {
        ($this->entry)(10, 'JE-before');
        ($this->backup)(3);
        expect(($this->status)()['state'])->toBe('current');
    });

    it('gives the owner a notice when trading is unsaved, and the day count when only the week rule fires', function () {
        ($this->recordBackupAt)(Clock::now()->subDays(9));
        Shop::postOpeningCapital(Clock::today());
        $notice = ($this->notice)('stale-backup');
        expect($notice['href'])->toBe('/settings/health')->and($notice['tone'])->toBe('danger')->and($notice['title'])->toBe('1 entry not yet backed up');
    });

    it('gives nothing while the backup is recent and nothing is unsaved', function () {
        ($this->recordBackupAt)(Clock::now()->subDays(2));
        expect(($this->ids)($this->owner))->not->toContain('stale-backup')->not->toContain('never-backed-up');
    });

    it('tells the day count when the week rule fires with nothing unsaved', function () {
        ($this->recordBackupAt)(Clock::now()->subDays(30));
        expect(($this->notice)('stale-backup')['title'])->toBe('Last backup was 30 days ago');
    });
});
