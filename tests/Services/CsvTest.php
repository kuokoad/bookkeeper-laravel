<?php

declare(strict_types=1);

/*
| Ported from the old tests/lib/csv.test.ts: escaping (formula injection
| included), the file shape, spreadsheet number formats and filenames, for
| App\Reports\Csv\Csv. The whole old file. (undefined has no PHP equivalent;
| null covers it.)
*/

use App\Domain\Quantity;
use App\Reports\Csv\Csv;

describe('escaping', function () {
    it('leaves ordinary values alone', function () {
        expect(Csv::escapeField('Milo 400g'))->toBe('Milo 400g')
            ->and(Csv::escapeField(42))->toBe('42')
            ->and(Csv::escapeField(null))->toBe('');
    });

    it('quotes fields containing commas, quotes or newlines', function () {
        expect(Csv::escapeField('Rice, local'))->toBe('"Rice, local"')
            ->and(Csv::escapeField('He said "hi"'))->toBe('"He said ""hi"""')
            ->and(Csv::escapeField("line1\nline2"))->toBe("\"line1\nline2\"");
    });

    it('neutralises spreadsheet formula injection', function () {
        expect(Csv::escapeField('=1+1'))->toBe("'=1+1")
            ->and(Csv::escapeField('+cmd'))->toBe("'+cmd")
            ->and(Csv::escapeField('-2+3'))->toBe("'-2+3")
            ->and(Csv::escapeField('@SUM(A1)'))->toBe("'@SUM(A1)");
    });

    it('leaves genuine negative numbers as numbers', function () {
        expect(Csv::escapeField('-704.85'))->toBe('-704.85')
            ->and(Csv::escapeField('-1'))->toBe('-1')
            ->and(Csv::escapeField('-25.5'))->toBe('-25.5')
            ->and(Csv::escapeField(Csv::money(-70_485)))->toBe('-704.85');
    });

    it('quotes AND neutralises when both apply', function () {
        expect(Csv::escapeField('=HYPERLINK("http://x"),y'))->toBe('"\'=HYPERLINK(""http://x""),y"');
    });
});

describe('toCsv', function () {
    it('writes a header row and data rows with CRLF', function () {
        $csv = Csv::toCsv(['Name', 'Amount'], [['Milo', '46.00'], ['Bread', '12.00']]);
        expect(str_starts_with($csv, "\u{FEFF}"))->toBeTrue()
            ->and($csv)->toContain("Name,Amount\r\n")
            ->and($csv)->toContain("Milo,46.00\r\n")
            ->and(str_ends_with($csv, "\r\n"))->toBeTrue();
    });

    it('handles an empty result set without losing the headers', function () {
        expect(Csv::toCsv(['Name', 'Amount'], []))->toBe("\u{FEFF}Name,Amount\r\n");
    });
});

describe('number formatting for spreadsheets', function () {
    it('writes money as a plain decimal a spreadsheet can sum', function () {
        expect(Csv::money(125_000))->toBe('1250.00')
            ->and(Csv::money(5))->toBe('0.05')
            ->and(Csv::money(-2_500))->toBe('-25.00')
            ->and(Csv::money(123_456_789))->not->toContain(',')
            ->and(Csv::money(123_456_789))->not->toContain('GHS');
    });

    it('writes quantities without grouping', function () {
        expect(Csv::qty(3_000))->toBe('3')
            ->and(Csv::qty(Quantity::parse('2.5')))->toBe('2.5')
            ->and(Csv::qty(Quantity::parse('1200')))->toBe('1200');
    });
});

describe('filenames', function () {
    it('describes the report and its period', function () {
        expect(Csv::filename('Profit and Loss', '2026-08-01', '2026-08-31'))->toBe('bookkeeper_profit-and-loss_2026-08-01_2026-08-31.csv');
    });

    it('omits a duplicated single-day period', function () {
        expect(Csv::filename('Sales', '2026-08-17', '2026-08-17'))->toBe('bookkeeper_sales_2026-08-17.csv');
    });

    it('strips anything unsafe from the report name', function () {
        expect(Csv::filename('Stock / Valuation!'))->toBe('bookkeeper_stock-valuation-.csv');
    });
});
