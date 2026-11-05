<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Errors\InvariantViolatedError;
use App\Enums\DocType;
use Illuminate\Support\Facades\DB;

/**
 * Document numbering: RCP-00001, INV-00001, JE-000001.
 *
 * Gap-free. The number is taken inside the business transaction: the UPDATE
 * holds the sequence row's lock until commit, so a second till waits rather
 * than taking the same number, and a rollback hands the number back. A port
 * of the old sequence.service.ts.
 */
final class SequenceService
{
    public static function format(string $prefix, int $value, int $padding): string
    {
        return $prefix.str_pad((string) $value, $padding, '0', STR_PAD_LEFT);
    }

    /** Reserve the next number. Must run inside the transaction that uses it. */
    public static function next(DocType $type): string
    {
        Tx::assertOpen('Taking a document number');

        $updated = DB::update(
            'UPDATE `sequences` SET `next_number` = `next_number` + 1, `updated_at` = CURRENT_TIMESTAMP(3) WHERE `doc_type` = ?',
            [$type->value],
        );
        if ($updated === 0) {
            throw new InvariantViolatedError("No numbering sequence configured for document type \"{$type->value}\".", ['docType' => $type->value]);
        }

        $row = DB::selectOne('SELECT `next_number`, `prefix`, `padding` FROM `sequences` WHERE `doc_type` = ?', [$type->value]);

        return self::format($row->prefix, (int) $row->next_number - 1, (int) $row->padding);
    }

    /** What the next number would be, without taking it. For display only. */
    public static function peek(DocType $type): ?string
    {
        $row = DB::table('sequences')->where('doc_type', $type->value)->first();

        return $row === null ? null : self::format($row->prefix, (int) $row->next_number, (int) $row->padding);
    }
}
