<?php

declare(strict_types=1);

/*
| Ported from the old tests/lib/dashboard.test.ts: the dashboard's windows,
| daily series and classification (App\Domain\Dashboard). The whole file.
*/

use App\Domain\Dashboard;

describe('dashboard periods', function () {
    it('preserves original inclusive windows across calendar boundaries', function () {
        expect(Dashboard::dashboardPeriod('2026-03-01', 'week'))->toBe(['from' => '2026-02-22', 'to' => '2026-03-01'])
            ->and(Dashboard::dashboardPeriod('2024-03-01', 'month'))->toBe(['from' => '2024-01-31', 'to' => '2024-03-01'])
            ->and(Dashboard::dashboardRangeHref('week', 'quarter'))->toBe('/dashboard?sales=week&spend=quarter')
            ->and(Dashboard::dashboardRangeHref('month', 'quarter'))->toBe('/dashboard?sales=month&spend=quarter')
            ->and(Dashboard::dashboardRangeHref('week', 'month'))->toBe('/dashboard?sales=week&spend=month');
    });

    it('includes both boundaries and confirmed zero-transaction days in charts', function () {
        expect(Dashboard::dashboardDailySeries([['businessDate' => '2026-03-01', 'total' => 100]], ['from' => '2026-02-28', 'to' => '2026-03-02']))->toBe([
            ['label' => '02-28', 'value' => 0],
            ['label' => '03-01', 'value' => 100],
            ['label' => '03-02', 'value' => 0],
        ]);
    });

    it('falls back to a month for an unknown range', function () {
        expect(Dashboard::dashboardRange('quarter'))->toBe('quarter')
            ->and(Dashboard::dashboardRange('junk'))->toBe('month')
            ->and(Dashboard::dashboardRange(null))->toBe('month');
    });
});

describe('dashboard financial classification', function () {
    it('includes every overdue bucket exactly once and reconciles to outstanding', function () {
        $row = ['current' => 100, 'days1to30' => 200, 'days31to60' => 300, 'days61to90' => 400, 'over90' => 500];
        expect(Dashboard::summariseAgeing([$row], 1500))->toBe(['overdue' => 1400, 'notYetDue' => 100, 'agedTotal' => 1500, 'unaged' => 0, 'reconciles' => true]);
    });

    it('exposes unallocated ledger credits without giving them an invented due date', function () {
        $row = ['current' => 100, 'days1to30' => 200, 'days31to60' => 0, 'days61to90' => 0, 'over90' => 0];
        expect(Dashboard::summariseAgeing([$row], 250))->toMatchArray(['overdue' => 200, 'notYetDue' => 100, 'unaged' => -50, 'reconciles' => false]);
    });

    it('does not allow opposite account differences to hide each other', function () {
        $rows = [
            ['paymentAccountId' => 1, 'unresolvedDifference' => 100],
            ['paymentAccountId' => 2, 'unresolvedDifference' => -100],
            ['paymentAccountId' => 3, 'unresolvedDifference' => 0],
        ];
        expect(array_column(Dashboard::reconciliationIssues($rows), 'paymentAccountId'))->toBe([1, 2]);
    });

    it('does not treat balanced totals as passing every integrity check', function () {
        $check = [
            'trialBalanced' => true, 'totalDebit' => 100, 'totalCredit' => 100, 'difference' => 0,
            'unbalancedEntries' => [['id' => 1, 'entryNo' => 'J1', 'difference' => 10]],
            'receivablesMatch' => false, 'payablesMatch' => true, 'untracedEntries' => 1,
        ];
        expect(array_column(Dashboard::integrityChecks($check), 'passed'))->toBe([true, false, false, true, false]);
    });
});
