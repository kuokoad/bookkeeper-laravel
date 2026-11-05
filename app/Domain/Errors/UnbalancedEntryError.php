<?php

declare(strict_types=1);

namespace App\Domain\Errors;

/** Debits did not equal credits. The transaction must roll back. */
final class UnbalancedEntryError extends DomainError
{
    /** @param  array<string, mixed>  $details */
    public function __construct(int $totalDebit, int $totalCredit, array $details = [])
    {
        parent::__construct(
            'UNBALANCED_ENTRY',
            "Journal entry does not balance: debit {$totalDebit} !== credit {$totalCredit}",
            'This transaction could not be saved because its accounting entries did not balance. Nothing was changed.',
            array_merge(['totalDebit' => $totalDebit, 'totalCredit' => $totalCredit], $details),
        );
    }
}
