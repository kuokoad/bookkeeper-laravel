<?php

declare(strict_types=1);

namespace App\Domain\Errors;

/**
 * The sale could only be filled from expired stock. There IS enough; it has
 * passed its date, and somebody senior has to say so before it leaves the
 * shop. The batch refs travel with the error so they can go and look.
 */
final class ExpiredStockError extends DomainError
{
    /** @param  list<string>  $batchRefs */
    public function __construct(string $productName, string $qtyExpired, array $batchRefs)
    {
        $refs = implode(', ', $batchRefs);
        parent::__construct(
            'EXPIRED_STOCK',
            "Expired stock required for {$productName}: {$qtyExpired} from {$refs}",
            "The only stock left of {$productName} has passed its date ({$refs}). "
                .'Someone who can approve it has to allow the sale, or the goods should be written off.',
            ['productName' => $productName, 'qtyExpired' => $qtyExpired, 'batchRefs' => $batchRefs],
        );
    }
}
