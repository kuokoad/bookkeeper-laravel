<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Errors\UnbalancedEntryError;
use App\Domain\Errors\ValidationError;
use App\Domain\Money;
use App\Enums\AccountType;
use App\Enums\NormalBalance;

/**
 * Journal-entry construction: one-sided lines, the balance check and
 * reversals. Pure; the journal service posts what this builds. A port of the
 * old src/domain/accounting/journal.ts.
 */
final class Journal
{
    /** @param  array{paymentAccountId?: int, customerId?: int, supplierId?: int, description?: string}  $options */
    public static function debit(int $accountId, int $amount, array $options = []): DraftLine
    {
        self::assertPositive($amount, 'debit');

        return self::line($accountId, $amount, 0, $options);
    }

    /** @param  array{paymentAccountId?: int, customerId?: int, supplierId?: int, description?: string}  $options */
    public static function credit(int $accountId, int $amount, array $options = []): DraftLine
    {
        self::assertPositive($amount, 'credit');

        return self::line($accountId, 0, $amount, $options);
    }

    /** A debit when positive, a credit of the magnitude when negative. */
    public static function postSigned(int $accountId, int $amount, array $options = []): DraftLine
    {
        return $amount >= 0 ? self::debit($accountId, $amount, $options) : self::credit($accountId, Money::absolute($amount), $options);
    }

    /** @param  list<DraftLine>  $lines */
    public static function totalDebits(array $lines): int
    {
        return Money::sum(array_map(fn (DraftLine $l): int => $l->debit, $lines));
    }

    /** @param  list<DraftLine>  $lines */
    public static function totalCredits(array $lines): int
    {
        return Money::sum(array_map(fn (DraftLine $l): int => $l->credit, $lines));
    }

    /**
     * Lines that move no money (a fully paid sale has no receivable).
     *
     * @param  list<DraftLine>  $lines
     * @return list<DraftLine>
     */
    public static function dropZeroLines(array $lines): array
    {
        return array_values(array_filter($lines, fn (DraftLine $l): bool => ! ($l->debit === 0 && $l->credit === 0)));
    }

    /** @param  list<DraftLine>  $lines */
    public static function assertLinesWellFormed(array $lines): void
    {
        foreach ($lines as $index => $line) {
            $n = $index + 1;
            if ($line->debit < 0 || $line->credit < 0) {
                throw new ValidationError("Journal line {$n} has a negative amount.");
            }
            if ($line->debit !== 0 && $line->credit !== 0) {
                throw new ValidationError("Journal line {$n} is both a debit and a credit. Each line must be one or the other.");
            }
            if ($line->accountId <= 0) {
                throw new ValidationError("Journal line {$n} has an invalid account.");
            }
        }
    }

    /** @param  list<DraftLine>  $lines */
    public static function assertBalanced(array $lines): void
    {
        if ($lines === []) {
            throw new ValidationError('A journal entry must have at least one line.');
        }
        if (count($lines) < 2) {
            throw new ValidationError('A journal entry must have at least two lines to balance.');
        }
        self::assertLinesWellFormed($lines);

        $debits = self::totalDebits($lines);
        $credits = self::totalCredits($lines);
        if ($debits !== $credits) {
            throw new UnbalancedEntryError($debits, $credits, ['difference' => Money::subtract($debits, $credits), 'lineCount' => count($lines)]);
        }
        if ($debits === 0) {
            throw new ValidationError('A journal entry must move a non-zero amount.');
        }
    }

    /**
     * Drop zero lines, then insist the rest balance.
     *
     * @param  list<DraftLine>  $lines
     * @return list<DraftLine>
     */
    public static function finalise(array $lines): array
    {
        $cleaned = self::dropZeroLines($lines);
        self::assertBalanced($cleaned);

        return $cleaned;
    }

    public static function normalBalanceOf(AccountType $type): NormalBalance
    {
        return ChartOfAccounts::normalBalanceFor($type);
    }

    /** An account's balance on its natural side, so cash and sales both read positive. */
    public static function signedBalance(AccountType $type, int $debits, int $credits): int
    {
        return self::normalBalanceOf($type) === NormalBalance::Debit
            ? Money::subtract($debits, $credits)
            : Money::subtract($credits, $debits);
    }

    /**
     * Mirror every line, so the original and its reversal net to zero.
     *
     * @param  list<DraftLine>  $lines
     * @return list<DraftLine>
     */
    public static function reverse(array $lines): array
    {
        return array_map(fn (DraftLine $l): DraftLine => new DraftLine(
            $l->accountId,
            $l->credit,
            $l->debit,
            $l->paymentAccountId,
            $l->customerId,
            $l->supplierId,
            $l->description === null ? null : "Reversal: {$l->description}",
        ), $lines);
    }

    private static function assertPositive(int $amount, string $side): void
    {
        if ($amount < 0) {
            throw new ValidationError("A {$side} amount cannot be negative (received {$amount}). Post it to the opposite side instead.", ['amount' => $amount, 'side' => $side]);
        }
    }

    private static function line(int $accountId, int $debit, int $credit, array $options): DraftLine
    {
        return new DraftLine(
            $accountId,
            $debit,
            $credit,
            $options['paymentAccountId'] ?? null,
            $options['customerId'] ?? null,
            $options['supplierId'] ?? null,
            $options['description'] ?? null,
        );
    }
}
