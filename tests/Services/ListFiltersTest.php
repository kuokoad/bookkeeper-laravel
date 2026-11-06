<?php

declare(strict_types=1);

/*
| The filter parsers the product and stock-movement lists use: ported from the
| "date ranges", "scalar parsers", "sorting" and "pagination" parts of the old
| tests/lib/filters.test.ts (its amount cases are in SalesFiltersTest; "query
| strings" and "the return trip from a form" test URL helpers the screens use,
| Phase 9), plus the list parsers: product and
| stock movement, and since Phase 6 the cashbook and account-statement ones
| (the old app had no tests for those two; these are new). Phase 7 added
| "covers a whole day at the end of a range", "describes every preset without
| throwing" (Filters::describeDateRange) and the report parsers.
*/

use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;

const FILTERS_TODAY = '2026-08-15'; // a Saturday, so the Monday-start week rule is exercised

describe('date ranges', function () {
    it('includes both ends of an explicit range', function () {
        expect(Filters::resolveDateRange('custom', '2026-08-01', '2026-08-15', FILTERS_TODAY))
            ->toBe(['range' => ['from' => '2026-08-01', 'to' => '2026-08-15'], 'preset' => 'custom']);
    });

    it('covers a whole day at the end of a range, whatever time a sale happened', function () {
        $range = Filters::resolveDateRange('custom', '2026-08-01', '2026-08-15', FILTERS_TODAY)['range'];
        $lateEvening = '2026-08-15';
        expect($lateEvening >= $range['from'] && $lateEvening <= $range['to'])->toBeTrue();
    });

    it('answers today and yesterday', function () {
        expect(Filters::resolveDateRange('today', null, null, FILTERS_TODAY)['range'])->toBe(['from' => '2026-08-15', 'to' => '2026-08-15'])
            ->and(Filters::resolveDateRange('yesterday', null, null, FILTERS_TODAY)['range'])->toBe(['from' => '2026-08-14', 'to' => '2026-08-14']);
    });

    it('starts the week on Monday', function () {
        expect(Filters::resolveDateRange('week', null, null, FILTERS_TODAY)['range'])->toBe(['from' => '2026-08-10', 'to' => '2026-08-15']);
    });

    it('gives last week as a complete Monday-to-Sunday week', function () {
        expect(Filters::resolveDateRange('last-week', null, null, FILTERS_TODAY)['range'])->toBe(['from' => '2026-08-03', 'to' => '2026-08-09']);
    });

    it('gives this month from the first, and last month in full', function () {
        expect(Filters::resolveDateRange('month', null, null, FILTERS_TODAY)['range'])->toBe(['from' => '2026-08-01', 'to' => '2026-08-15'])
            ->and(Filters::resolveDateRange('last-month', null, null, FILTERS_TODAY)['range'])->toBe(['from' => '2026-07-01', 'to' => '2026-07-31']);
    });

    it('handles a last-month that ends on the 29th of a leap February', function () {
        expect(Filters::resolveDateRange('last-month', null, null, '2028-03-10')['range'])->toBe(['from' => '2028-02-01', 'to' => '2028-02-29']);
    });

    it('gives this year from the first of January', function () {
        expect(Filters::resolveDateRange('year', null, null, FILTERS_TODAY)['range'])->toBe(['from' => '2026-01-01', 'to' => '2026-08-15']);
    });

    it('treats "everything" as a real range, so from is always <= to', function () {
        $range = Filters::resolveDateRange('all', null, null, FILTERS_TODAY)['range'];
        expect($range['from'])->toBe(Filters::EARLIEST_DATE)->and($range['from'] <= $range['to'])->toBeTrue();
    });

    it('falls back to this month for anything it does not recognise', function () {
        foreach (['', 'fortnight', 'DROP TABLE sales', '../../etc'] as $junk) {
            expect(Filters::resolveDateRange($junk, null, null, FILTERS_TODAY))
                ->toBe(['range' => ['from' => '2026-08-01', 'to' => '2026-08-15'], 'preset' => 'month']);
        }
    });

    it('swaps a custom range entered the wrong way round rather than showing nothing', function () {
        expect(Filters::resolveDateRange('custom', '2026-08-31', '2026-08-01', FILTERS_TODAY)['range'])->toBe(['from' => '2026-08-01', 'to' => '2026-08-31']);
    });

    it('accepts a custom range with only one end filled in', function () {
        expect(Filters::resolveDateRange('custom', '2026-08-05', null, FILTERS_TODAY)['range'])->toBe(['from' => '2026-08-05', 'to' => FILTERS_TODAY])
            ->and(Filters::resolveDateRange('custom', null, '2026-08-05', FILTERS_TODAY)['range'])->toBe(['from' => Filters::EARLIEST_DATE, 'to' => '2026-08-05']);
    });

    it('ignores an unparseable custom date instead of passing it to SQL', function () {
        expect(Filters::resolveDateRange('custom', 'yesterday', "'; DROP TABLE sales; --", FILTERS_TODAY)['preset'])->toBe('month');
    });

    it('rejects a date that looks right but is not a real day', function () {
        expect(Filters::resolveDateRange('custom', '2026-02-30', null, FILTERS_TODAY)['preset'])->toBe('month');
    });

    it('describes every preset without throwing', function () {
        foreach (Filters::DATE_PRESETS as $preset) {
            $resolved = Filters::resolveDateRange($preset, FILTERS_TODAY, FILTERS_TODAY, FILTERS_TODAY);
            expect(strlen(Filters::describeDateRange($resolved['range'], $resolved['preset'], FILTERS_TODAY)))->toBeGreaterThan(0);
        }
    });

    it('describes a range the way a report header reads it', function () {
        expect(Filters::describeDateRange(['from' => '0000-01-01', 'to' => FILTERS_TODAY], 'all', FILTERS_TODAY))->toBe('Everything up to 2026-08-15')
            ->and(Filters::describeDateRange(['from' => '2026-08-15', 'to' => '2026-08-15'], 'today', FILTERS_TODAY))->toBe('2026-08-15')
            ->and(Filters::describeDateRange(['from' => '0000-01-01', 'to' => '2026-08-10'], 'custom', FILTERS_TODAY))->toBe('Up to 2026-08-10')
            ->and(Filters::describeDateRange(['from' => '2026-08-01', 'to' => FILTERS_TODAY], 'month', FILTERS_TODAY))->toBe('2026-08-01 to today')
            ->and(Filters::describeDateRange(['from' => '2026-08-01', 'to' => FILTERS_TODAY], 'custom', FILTERS_TODAY))->toBe('2026-08-01 to 2026-08-15');
    });
});

describe('scalar parsers', function () {
    it('accepts a positive integer id and nothing else', function () {
        expect(Filters::parseId('7'))->toBe(7);
        foreach (['0', '-1', '1.5', 'abc', '', ' ', '1 OR 1=1', '9e99'] as $junk) {
            expect(Filters::parseId($junk))->toBeNull($junk);
        }
        expect(Filters::parseId(null))->toBeNull();
    });

    it('accepts only whitelisted enum values', function () {
        $allowed = ['paid', 'unpaid'];
        expect(Filters::parseEnum('paid', $allowed))->toBe('paid')
            ->and(Filters::parseEnum('PAID', $allowed))->toBeNull()
            ->and(Filters::parseEnum('anything', $allowed))->toBeNull()
            ->and(Filters::parseEnum(null, $allowed))->toBeNull();
    });

    it('accepts only a real business date', function () {
        expect(Filters::parseDate('2026-08-15'))->toBe('2026-08-15')
            ->and(Filters::parseDate('2026-8-5'))->toBeNull()
            ->and(Filters::parseDate('2026-13-01'))->toBeNull()
            ->and(Filters::parseDate(null))->toBeNull();
    });

    it('trims a search term and treats blank as no filter', function () {
        expect(Filters::parseSearch('  coca cola '))->toBe('coca cola')
            ->and(Filters::parseSearch('   '))->toBeNull()
            ->and(Filters::parseSearch(''))->toBeNull()
            ->and(mb_strlen(Filters::parseSearch(str_repeat('x', 500))))->toBe(100);
    });
});

describe('sorting', function () {
    it('falls back rather than letting a query string reach an ORDER BY', function () {
        $allowed = ['date', 'amount'];
        expect(Filters::parseSort('amount', 'asc', $allowed, 'date'))->toBe(['sort' => 'amount', 'direction' => 'asc'])
            ->and(Filters::parseSort('total_minor; DROP TABLE sales', 'asc', $allowed, 'date')['sort'])->toBe('date')
            ->and(Filters::parseSort('date', 'sideways', $allowed, 'date')['direction'])->toBe('desc');
    });
});

describe('pagination', function () {
    it('starts at page one for anything that is not a page number', function () {
        foreach (['0', '-4', 'abc', '1.5', ''] as $junk) {
            expect(Filters::parsePage($junk)['page'])->toBe(1);
        }
        expect(Filters::parsePage(null)['page'])->toBe(1);
    });

    it('turns a page into an offset', function () {
        expect(Filters::parsePage('3', 50))->toBe(['page' => 3, 'pageSize' => 50, 'offset' => 100]);
    });

    it('caps the page size so one request cannot ask for everything', function () {
        expect(Filters::parsePage('1', 10_000)['pageSize'])->toBe(200);
    });

    it('clamps a page number to what the filtered set can actually show', function () {
        expect(Filters::clampPage(4, 27, 50))->toBe(1)
            ->and(Filters::clampPage(4, 260, 50))->toBe(4)
            ->and(Filters::clampPage(9, 260, 50))->toBe(6)
            ->and(Filters::clampPage(1, 0, 50))->toBe(1);
    });
});

describe('the product and stock-movement list parsers', function () {
    it('turns a product list URL into a validated query, dropping junk', function () {
        $parsed = ListFilters::parseProductFilters(['q' => ' milo ', 'category' => '3', 'supplier' => 'abc', 'stock' => 'low', 'archived' => 'everything', 'sort' => 'value; DROP', 'page' => '2']);
        expect($parsed['filters'])->toBe(['sort' => 'name', 'direction' => 'asc', 'search' => 'milo', 'categoryId' => 3, 'stockStatus' => 'low'])
            ->and($parsed['page'])->toBe(2)
            ->and($parsed['carried'])->toBe(['q' => ' milo ', 'category' => '3', 'supplier' => 'abc', 'stock' => 'low', 'archived' => 'everything', 'sort' => 'value; DROP']);
    });

    it('shows every stock movement by default, not just this month', function () {
        $parsed = ListFilters::parseStockMovementFilters(['movement' => 'SALE', 'product' => ['5', '6']], FILTERS_TODAY);
        expect($parsed['preset'])->toBe('all')
            ->and($parsed['filters'])->toBe(['from' => Filters::EARLIEST_DATE, 'to' => FILTERS_TODAY, 'productId' => 5, 'movementType' => 'SALE']);
    });

    it('refuses a movement type that is not one', function () {
        expect(ListFilters::parseStockMovementFilters(['movement' => 'THEFT'], FILTERS_TODAY)['filters'])->not->toHaveKey('movementType');
    });
});

describe('the cashbook and account-statement parsers', function () {
    it('turns an expenses URL into a validated query, dropping junk', function () {
        $parsed = ListFilters::parseCashbookFilters(['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-15', 'q' => ' taxi ', 'category' => '12',
            'account' => 'x', 'staff' => '2', 'status' => 'DELETED', 'min' => '10', 'sort' => 'amount', 'direction' => 'asc'], FILTERS_TODAY);

        expect($parsed['filters'])->toBe(['from' => '2026-08-01', 'to' => '2026-08-15', 'sort' => 'amount', 'direction' => 'asc',
            'categoryAccountId' => 12, 'staffId' => 2, 'search' => 'taxi', 'minAmount' => 1_000])
            ->and(ListFilters::parseCashbookFilters(['sort' => 'id; DROP'], FILTERS_TODAY)['filters']['sort'])->toBe('date');
    });

    it('takes a statement’s transaction type only from the journal’s own list', function () {
        $parsed = ListFilters::parseAccountFilters(['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-15', 'type' => 'EXPENSE', 'flow' => 'sideways'], FILTERS_TODAY);

        expect($parsed['filters'])->toBe(['from' => '2026-08-01', 'to' => '2026-08-15', 'sourceType' => 'EXPENSE'])
            ->and(ListFilters::parseAccountFilters(['type' => 'NONSENSE'], FILTERS_TODAY)['filters'])->not->toHaveKey('sourceType')
            ->and(ListFilters::accountFiltersHideMovements($parsed['filters']))->toBeTrue()
            ->and(ListFilters::accountFiltersHideMovements(['from' => '2026-08-01', 'to' => '2026-08-15']))->toBeFalse();
    });
});
