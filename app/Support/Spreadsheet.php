<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Errors\ValidationError;
use App\Domain\JsCompat;
use Generator;

/**
 * Read the first sheet of an uploaded .xlsx or .csv into rows of text. A port
 * of the old lib/spreadsheet.ts.
 *
 * Cells come back exactly as the file stores them ("1.7592592592592591", not
 * "1.76"), so rounding is a decision the caller makes and shows. An .xlsx keeps
 * the last calculated value of every formula, and that is what is read. Row i
 * of the result is spreadsheet row i + 1, gaps included.
 *
 * The .xlsx reader needs only zlib (gzinflate), not the zip extension.
 */
final class Spreadsheet
{
    /** An .xlsx is a zip of XML. A file that unpacks past this is not a price list. */
    private const MAX_UNPACKED_BYTES = 20 * 1024 * 1024;

    /** @return list<list<string>> */
    public static function read(string $fileName, string $bytes): array
    {
        $lower = strtolower($fileName);
        if (str_ends_with($lower, '.csv')) {
            // As TextDecoder('utf-8'): one leading BOM dropped by the decoder itself.
            $text = self::utf8($bytes);

            return self::parseCsv(str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text);
        }
        if (str_ends_with($lower, '.xlsx')) {
            return self::readXlsx($bytes);
        }
        throw new ValidationError('Choose a .xlsx or .csv file. Older .xls files: open and "Save As" .xlsx first.');
    }

    /** Bytes as UTF-8 text, a broken sequence becoming U+FFFD, as the old app's decoders did. */
    private static function utf8(string $bytes): string
    {
        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            return mb_scrub($bytes, 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }

    // --- CSV ----------------------------------------------------------------

    /**
     * RFC 4180: quoted fields may hold commas, doubled quotes and line breaks.
     *
     * @return list<list<string>>
     */
    public static function parseCsv(string $text): array
    {
        $input = str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;
        $rows = [];
        $row = [];
        $field = '';
        $quoted = false;
        $length = strlen($input);

        // Byte by byte is safe: every character that matters here is ASCII, and
        // UTF-8 never uses an ASCII byte inside a multi-byte character.
        for ($i = 0; $i < $length; $i++) {
            $ch = $input[$i];
            if ($quoted) {
                if ($ch === '"' && ($input[$i + 1] ?? '') === '"') {
                    $field .= '"';
                    $i++;
                } elseif ($ch === '"') {
                    $quoted = false;
                } else {
                    $field .= $ch;
                }
            } elseif ($ch === '"') {
                $quoted = true;
            } elseif ($ch === ',') {
                $row[] = $field;
                $field = '';
            } elseif ($ch === "\n" || $ch === "\r") {
                if ($ch === "\r" && ($input[$i + 1] ?? '') === "\n") {
                    $i++;
                }
                $row[] = $field;
                $rows[] = $row;
                $row = [];
                $field = '';
            } else {
                $field .= $ch;
            }
        }
        if ($quoted) {
            throw new ValidationError('The CSV file has a quote that is never closed.');
        }
        if ($field !== '' || $row !== []) {
            $row[] = $field;
            $rows[] = $row;
        }

        return $rows;
    }

    // --- XLSX ---------------------------------------------------------------

    private static function unreadable(): ValidationError
    {
        return new ValidationError('That file is not a readable .xlsx workbook.');
    }

    /** @return array<string, \Closure(): string> */
    private static function unzip(string $buffer): array
    {
        $length = strlen($buffer);
        $u16 = fn (int $at): int => $at + 2 <= $length ? unpack('v', $buffer, $at)[1] : throw self::unreadable();
        $u32 = fn (int $at): int => $at + 4 <= $length ? unpack('V', $buffer, $at)[1] : throw self::unreadable();

        // End of central directory: the last record in the file, before any comment.
        $eocd = -1;
        for ($i = $length - 22; $i >= max(0, $length - 65_557); $i--) {
            if ($u32($i) === 0x06054B50) {
                $eocd = $i;
                break;
            }
        }
        if ($eocd < 0) {
            throw self::unreadable();
        }

        $entries = [];
        $count = $u16($eocd + 10);
        $at = $u32($eocd + 16);
        for ($n = 0; $n < $count; $n++) {
            if ($at + 46 > $length || $u32($at) !== 0x02014B50) {
                throw self::unreadable();
            }
            $method = $u16($at + 10);
            $size = $u32($at + 20);
            $nameLength = $u16($at + 28);
            $extraLength = $u16($at + 30);
            $commentLength = $u16($at + 32);
            $local = $u32($at + 42);
            $name = substr($buffer, $at + 46, $nameLength);
            $entries[$name] = function () use ($buffer, $length, $u16, $u32, $local, $size, $method): string {
                if ($local + 30 > $length || $u32($local) !== 0x04034B50) {
                    throw self::unreadable();
                }
                $start = $local + 30 + $u16($local + 26) + $u16($local + 28);
                $data = substr($buffer, $start, $size);
                if ($method === 0) {
                    return $data;
                }
                if ($method === 8) {
                    $inflated = @gzinflate($data, self::MAX_UNPACKED_BYTES);

                    return $inflated === false ? throw self::unreadable() : $inflated;
                }
                throw self::unreadable();
            };
            $at += 46 + $nameLength + $extraLength + $commentLength;
        }

        return $entries;
    }

    private static function decodeXml(string $text): string
    {
        return preg_replace_callback('/&(#x[0-9a-f]+|#\d+|amp|lt|gt|quot|apos);/i', function (array $m): string {
            $code = $m[1];
            if ($code[0] === '#') {
                $point = ($code[1] === 'x' || $code[1] === 'X') ? hexdec(substr($code, 2)) : (int) substr($code, 1);

                return $point <= 0x10FFFF ? mb_chr((int) $point, 'UTF-8') : '';
            }

            return ['amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', 'apos' => "'"][strtolower($code)] ?? '';
        }, $text) ?? $text;
    }

    /**
     * Every <tag ...>body</tag> or <tag .../> in order, found with strpos
     * rather than a lazy regex, which a crafted file could make rescan to the
     * end of the text once per tag.
     *
     * @return Generator<array{attributes: string, body: string}>
     */
    private static function elements(string $xml, string $tag): Generator
    {
        $open = "<{$tag}";
        $close = "</{$tag}>";
        $at = 0;
        while (true) {
            $start = strpos($xml, $open, $at);
            if ($start === false) {
                return;
            }
            $next = $xml[$start + strlen($open)] ?? null;
            // `<c` must not match `<col` or `<cols`.
            if ($next === null || preg_match('/[\s>\/]/', $next) !== 1) {
                $at = $start + strlen($open);

                continue;
            }
            $tagEnd = strpos($xml, '>', $start);
            if ($tagEnd === false) {
                throw self::unreadable();
            }
            if ($xml[$tagEnd - 1] === '/') {
                yield ['attributes' => substr($xml, $start + strlen($open), $tagEnd - 1 - $start - strlen($open)), 'body' => ''];
                $at = $tagEnd + 1;

                continue;
            }
            $end = strpos($xml, $close, $tagEnd);
            if ($end === false) {
                throw self::unreadable();
            }
            yield ['attributes' => substr($xml, $start + strlen($open), $tagEnd - $start - strlen($open)), 'body' => substr($xml, $tagEnd + 1, $end - $tagEnd - 1)];
            $at = $end + strlen($close);
        }
    }

    /** The text of every <t> run, ignoring phonetic guides (<rPh>), as Excel shows it. */
    private static function runText(string $xml): string
    {
        $shown = '';
        $at = 0;
        while (($start = strpos($xml, '<rPh', $at)) !== false) {
            $end = strpos($xml, '</rPh>', $start);
            if ($end === false) {
                throw self::unreadable();
            }
            $shown .= substr($xml, $at, $start - $at);
            $at = $end + strlen('</rPh>');
        }
        $shown .= substr($xml, $at);
        $text = '';
        foreach (self::elements($shown, 't') as $run) {
            $text .= self::decodeXml($run['body']);
        }

        return $text;
    }

    private static function attribute(string $attributes, string $name): ?string
    {
        return preg_match('/\b'.preg_quote($name, '/').'="([^"]*)"/', $attributes, $m) === 1 ? $m[1] : null;
    }

    /** "AB12" → column 27 (zero-based) and row 12. */
    private static function cellPosition(string $ref): ?array
    {
        if (preg_match('/^([A-Z]+)(\d+)$/D', $ref, $m) !== 1) {
            return null;
        }
        $column = 0;
        foreach (str_split($m[1]) as $letter) {
            $column = $column * 26 + (ord($letter) - 64);
        }

        return ['column' => $column - 1, 'row' => (int) $m[2]];
    }

    private static function first(Generator $items): ?array
    {
        return $items->valid() ? $items->current() : null;
    }

    /** @return list<list<string>> */
    private static function readXlsx(string $buffer): array
    {
        $files = self::unzip($buffer);
        $read = fn (string $name): ?string => isset($files[$name]) ? self::utf8($files[$name]()) : null;

        // The first tab is the first <sheet> in the workbook, wherever its file lives.
        $workbook = $read('xl/workbook.xml');
        $rels = $read('xl/_rels/workbook.xml.rels');
        if ($workbook === null || $rels === null) {
            throw self::unreadable();
        }
        $firstSheet = self::first(self::elements($workbook, 'sheet'));
        $relId = $firstSheet === null ? null : self::attribute($firstSheet['attributes'], 'r:id');
        $target = null;
        if ($relId !== null) {
            foreach (self::elements($rels, 'Relationship') as $item) {
                if (self::attribute($item['attributes'], 'Id') === $relId) {
                    $target = self::attribute($item['attributes'], 'Target');
                    break;
                }
            }
        }
        if ($target === null || $target === '') {
            throw new ValidationError('The workbook has no sheets.');
        }
        $sheet = $read(str_starts_with($target, '/') ? substr($target, 1) : "xl/{$target}");
        if ($sheet === null) {
            throw new ValidationError('The workbook has no sheets.');
        }

        $shared = [];
        foreach (self::elements($read('xl/sharedStrings.xml') ?? '', 'si') as $item) {
            $shared[] = self::runText($item['body']);
        }

        $rows = [];
        $lastRow = 0;
        foreach (self::elements($sheet, 'c') as ['attributes' => $attributes, 'body' => $body]) {
            $position = self::cellPosition(self::attribute($attributes, 'r') ?? '');
            if ($position === null) {
                continue;
            }
            $type = self::attribute($attributes, 't');
            $value = self::decodeXml(self::first(self::elements($body, 'v'))['body'] ?? '');
            $text = match ($type) {
                's' => $shared[(int) $value] ?? '',
                'inlineStr' => self::runText($body),
                'b' => $value === '1' ? 'TRUE' : 'FALSE',
                'e' => '',
                default => $value,
            };
            // Formatted but empty cells are not data: a sheet styled down to row
            // 6000 is as long as its real rows.
            if (JsCompat::trim($text) === '') {
                continue;
            }
            $rows[$position['row'] - 1][$position['column']] = $text;
            $lastRow = max($lastRow, $position['row']);
        }

        // Fill the holes a sparse sheet leaves, so every row is a plain list of text.
        $result = [];
        for ($r = 0; $r < $lastRow; $r++) {
            $cells = $rows[$r] ?? [];
            $width = $cells === [] ? 0 : max(array_keys($cells)) + 1;
            $line = [];
            for ($c = 0; $c < $width; $c++) {
                $line[] = $cells[$c] ?? '';
            }
            $result[] = $line;
        }

        return $result;
    }
}
