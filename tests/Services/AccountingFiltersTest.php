<?php

declare(strict_types=1);

/*
| The parsers Phase 9b moved out of the journal, ledger, trial balance,
| ageing and audit pages into ListFilters, so each page and its CSV read the
| URL the same way. New: the old pages parsed these inline.
*/

use App\Enums\AuditAction;
use App\Support\Filters\ListFilters;

const AF_TODAY = '2026-08-15';

it('parses the journal: this month by default, a kind only from its own list', function () {
    $list = ListFilters::parseJournalFilters([], AF_TODAY);
    expect($list['filters'])->toBe(['from' => '2026-08-01', 'to' => '2026-08-15'])
        ->and($list['carried'])->toBe([]);

    $list = ListFilters::parseJournalFilters(['from' => '2026-07-01', 'to' => 'junk', 'source' => 'SALE'], AF_TODAY);
    expect($list['filters'])->toBe(['from' => '2026-07-01', 'to' => '2026-08-15', 'sourceType' => 'SALE'])
        ->and($list['carried'])->toBe(['from' => '2026-07-01', 'source' => 'SALE'])
        ->and(ListFilters::parseJournalFilters(['source' => 'YEAR_END_CLOSE'], AF_TODAY)['source'])->toBeNull()
        ->and(ListFilters::parseJournalFilters(['source' => "SALE' OR 1=1"], AF_TODAY)['filters'])->not->toHaveKey('sourceType');
});

it('parses a ledger and the trial balance, showing today when no valid end date was given', function () {
    $ledger = ListFilters::parseLedgerFilters(['from' => '2026-02-30', 'to' => '2026-08-01'], AF_TODAY);
    expect($ledger['filters'])->toBe(['from' => '2026-08-01', 'to' => '2026-08-01'])
        ->and($ledger['toShown'])->toBe('2026-08-01')
        ->and(ListFilters::parseLedgerFilters(['to' => 'x'], AF_TODAY)['toShown'])->toBe(AF_TODAY);

    $tb = ListFilters::parseTrialBalanceFilters(['to' => 'junk'], AF_TODAY);
    expect($tb['filters'])->toBe(['to' => AF_TODAY])
        ->and(ListFilters::parseTrialBalanceFilters(['from' => '2026-01-01', 'to' => '2026-06-30'], AF_TODAY)['filters'])->toBe(['from' => '2026-01-01', 'to' => '2026-06-30']);
});

it('parses the ageing: as at today by default, the CSV `to` as well, a party and a status', function () {
    expect(ListFilters::parseAgeingFilters(['customer' => '7', 'status' => 'overdue'], 'customer', AF_TODAY))
        ->toMatchArray(['asAt' => AF_TODAY, 'isToday' => true, 'partyId' => 7, 'status' => 'overdue', 'filtered' => true, 'carried' => ['customer' => '7', 'status' => 'overdue']]);

    expect(ListFilters::parseAgeingFilters([], 'supplier', AF_TODAY)['filtered'])->toBeFalse()
        ->and(ListFilters::parseAgeingFilters(['to' => '2026-06-30'], 'supplier', AF_TODAY)['asAt'])->toBe('2026-06-30')
        ->and(ListFilters::parseAgeingFilters(['asAt' => '2026-13-01', 'status' => 'late', 'supplier' => '-1'], 'supplier', AF_TODAY))
        ->toMatchArray(['asAt' => AF_TODAY, 'partyId' => null, 'status' => null, 'filtered' => false]);
});

it('parses the audit log: unknown values narrow nothing, and the "to" day counts whole, in UTC', function () {
    $list = ListFilters::parseAuditFilters(['user' => '3', 'action' => 'VOID', 'entity' => 'sale', 'from' => '2026-08-01', 'to' => '2026-08-15', 'page' => '2'], ['sale', 'purchase'], 50);
    expect($list['resolved'])->toBe(['user' => 3, 'action' => 'VOID', 'entity' => 'sale', 'from' => '2026-08-01', 'to' => '2026-08-15'])
        ->and($list['filters']['action'])->toBe(AuditAction::Void)
        ->and($list['filters']['to']->toIso8601ZuluString())->toBe('2026-08-15T23:59:59Z')
        ->and($list['offset'])->toBe(50)
        ->and($list['carried'])->toBe(['user' => '3', 'action' => 'VOID', 'entity' => 'sale', 'from' => '2026-08-01', 'to' => '2026-08-15']);

    expect(ListFilters::parseAuditFilters(['entity' => 'nope', 'action' => 'HACK', 'user' => 'x'], ['sale'])['filters'])->toBe([]);
});

it('swaps a backwards from and to in the journal, ledger, trial balance and audit parsers', function () {
    $backwards = ['from' => '2026-08-10', 'to' => '2026-08-01'];
    $swapped = ['from' => '2026-08-01', 'to' => '2026-08-10'];

    expect(ListFilters::parseJournalFilters($backwards, AF_TODAY)['filters'])->toBe($swapped)
        ->and(ListFilters::parseLedgerFilters($backwards, AF_TODAY))->toMatchArray([...$swapped, 'filters' => $swapped, 'toShown' => '2026-08-10'])
        ->and(ListFilters::parseTrialBalanceFilters($backwards, AF_TODAY))->toMatchArray([...$swapped, 'filters' => $swapped])
        ->and(ListFilters::parseAuditFilters($backwards, ['sale'])['resolved'])->toBe($swapped);
});

it('runs a ledger asked only for a From after today to that day, not backwards to today', function () {
    expect(ListFilters::parseLedgerFilters(['from' => '2026-09-01'], AF_TODAY))->toMatchArray(['filters' => ['from' => '2026-09-01', 'to' => '2026-09-01'], 'to' => null, 'toShown' => '2026-09-01']);
});

it('starts a ledger at the 1st of the month when no from is given', function () {
    expect(ListFilters::parseLedgerFilters([], AF_TODAY))->toMatchArray(['from' => '2026-08-01', 'filters' => ['from' => '2026-08-01', 'to' => AF_TODAY], 'toShown' => AF_TODAY, 'carried' => []]);
});

it('starts a journal or ledger asked only for an earlier to at the 1st of that month, without swapping', function () {
    expect(ListFilters::parseJournalFilters(['to' => '2026-05-31'], AF_TODAY)['filters'])->toBe(['from' => '2026-05-01', 'to' => '2026-05-31'])
        ->and(ListFilters::parseLedgerFilters(['to' => '2026-05-31'], AF_TODAY))->toMatchArray(['from' => '2026-05-01', 'to' => '2026-05-31', 'toShown' => '2026-05-31'])
        ->and(ListFilters::parseJournalFilters(['to' => '2026-08-10'], AF_TODAY)['filters'])->toBe(['from' => '2026-08-01', 'to' => '2026-08-10']);
});
