<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/** One journal line before it is posted: exactly one of debit and credit is non-zero. */
final readonly class DraftLine
{
    public function __construct(
        public int $accountId,
        public int $debit,
        public int $credit,
        public ?int $paymentAccountId = null,
        public ?int $customerId = null,
        public ?int $supplierId = null,
        public ?string $description = null,
    ) {}
}
