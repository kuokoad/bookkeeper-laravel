<?php

declare(strict_types=1);

/*
| Ported from the old tests/lib/spreadsheet.test.ts: what the product import
| receives from an uploaded file.
*/

use App\Domain\Errors\ValidationError;
use App\Support\Spreadsheet;

/** A minimal zip, deflated, built the way Excel lays one out. */
function sheetZip(array $files): string
{
    $locals = '';
    $centrals = '';
    $offset = 0;
    foreach ($files as $name => $text) {
        $data = gzdeflate($text);
        $local = pack('VvvvvvVVVvv', 0x04034B50, 0, 0, 8, 0, 0, 0, strlen($data), strlen($text), strlen($name), 0);
        $central = pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 0, 0, 0, 8, 0, 0, 0, strlen($data), strlen($text), strlen($name), 0, 0, 0, 0, 0, $offset);
        $locals .= $local.$name.$data;
        $centrals .= $central.$name;
        $offset += 30 + strlen($name) + strlen($data);
    }
    $end = pack('VvvvvVVv', 0x06054B50, 0, 0, 0, count($files), strlen($centrals), $offset, 0);

    return $locals.$centrals.$end;
}

function sheetWorkbook(string $sheet): string
{
    return sheetZip([
        'xl/workbook.xml' => '<workbook><sheets><sheet name="A" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => $sheet,
    ]);
}

describe('reading a price list', function () {
    it('reads the first tab of an .xlsx, keeping row numbers and unrounded formula values', function () {
        // Two tabs; the FIRST tab lives in sheet2.xml, as it does after tabs are reordered.
        $workbook = sheetZip([
            'xl/workbook.xml' => '<workbook xmlns:r="r"><sheets><sheet name="Prices" sheetId="2" r:id="rId9"/><sheet name="Old" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId9" Target="worksheets/sheet2.xml"/></Relationships>',
            'xl/sharedStrings.xml' => '<sst><si><t>PRODUCT</t></si><si><t>cost price</t></si><si><r><t>KRAFT </t></r><r><t>BOWL &amp; LID</t></r><rPh><t>x</t></rPh></si></sst>',
            'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>wrong tab</t></is></c></row></sheetData></worksheet>',
            'xl/worksheets/sheet2.xml' => '<worksheet><sheetData>'
                .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="s"><v>1</v></c></row>'
                // A formula keeps its last calculated value, unrounded; an empty styled cell is self-closing.
                .'<row r="3"><c r="A3" t="s"><v>2</v></c><c r="B3" s="4"/><c r="C3"><f>B2/1.08</f><v>1.7592592592592591</v></c></row>'
                .'</sheetData></worksheet>',
        ]);

        expect(Spreadsheet::read('JCG.xlsx', $workbook))->toBe([
            ['PRODUCT', '', 'cost price'],
            [],
            ['KRAFT BOWL & LID', '', '1.7592592592592591'],
        ]);
    });

    it('reads CSV with quoted commas, doubled quotes, line breaks and a BOM', function () {
        expect(Spreadsheet::parseCsv("\u{FEFF}name,price\r\n\"Bowl, 750ml\",\"2.50\"\r\n\"Say \"\"hi\"\"\nthere\",3\n"))->toBe([
            ['name', 'price'],
            ['Bowl, 750ml', '2.50'],
            ["Say \"hi\"\nthere", '3'],
        ]);
    });

    it('drops formatted empty cells, so a sheet styled down to row 6000 is two rows long', function () {
        $styled = sheetWorkbook(
            '<worksheet><cols><col min="1" max="2"/></cols><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>name</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>Bowl</t></is></c><c r="B2" s="3"/></row><row r="6000"><c r="B6000" s="3"/></row></sheetData></worksheet>',
        );
        expect(Spreadsheet::read('a.xlsx', $styled))->toBe([['name'], ['Bowl']]);
    });

    it('refuses an unclosed cell promptly instead of rescanning the sheet for every tag', function () {
        $crafted = sheetWorkbook('<worksheet><sheetData>'.str_repeat('<c r="A1">', 200_000));
        $started = microtime(true);
        expect(fn () => Spreadsheet::read('a.xlsx', $crafted))->toThrow(ValidationError::class)
            ->and(microtime(true) - $started)->toBeLessThan(1.0);
    });

    it('ignores a cell placed beyond the last row or column Excel has, instead of building the rows up to it', function () {
        $crafted = sheetWorkbook(
            '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>name</t></is></c></row>'
            .'<row r="400000000"><c r="A400000000" t="inlineStr"><is><t>far</t></is></c></row>'
            .'<row r="2"><c r="XFE2" t="inlineStr"><is><t>wide</t></is></c></row></sheetData></worksheet>',
        );
        expect(Spreadsheet::read('a.xlsx', $crafted))->toBe([['name']]);
    });

    it('refuses a file that is not .xlsx or .csv, or is not really a workbook', function () {
        expect(fn () => Spreadsheet::read('old.xls', str_repeat("\0", 10)))->toThrow(ValidationError::class)
            ->and(fn () => Spreadsheet::read('fake.xlsx', 'not a zip'))->toThrow(ValidationError::class);
    });
});

it('reads a CSV with a broken byte as the old decoder did, with a replacement character', function () {
    expect(Spreadsheet::read('list.csv', "name\nBowl\xFF\n"))->toBe([['name'], ["Bowl\u{FFFD}"]]);
});

it('keeps a bare inch mark inside a field as a character, not the start of a quote that swallows the next row', function () {
    expect(Spreadsheet::parseCsv("name,price\nPipe 1/2\" PVC,5.00\nPipe 3/4\" PVC,7.00\n"))->toBe([
        ['name', 'price'], ['Pipe 1/2" PVC', '5.00'], ['Pipe 3/4" PVC', '7.00'],
    ])
        // An odd number of them no longer reads as a quote left open.
        ->and(Spreadsheet::parseCsv("name\n12\" ruler\n"))->toBe([['name'], ['12" ruler']])
        // A quoted field still works as before.
        ->and(Spreadsheet::parseCsv("\"Bowl, 750ml\",2.50\n"))->toBe([['Bowl, 750ml', '2.50']]);
});
