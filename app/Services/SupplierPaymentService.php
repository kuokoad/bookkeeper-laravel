<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\JsCompat;
use App\Domain\Money;
use App\Enums\AuditAction;
use App\Enums\DocType;
use App\Enums\JournalSourceType;
use App\Support\Clock;
use App\Support\Row;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Paying a supplier what is owed: Dr Accounts Payable (tagged with the
 * supplier), Cr Cash/MoMo/Bank. A port of the old supplier-payment.service.ts.
 *
 * Input: ['supplierId', 'businessDate', 'paymentAccountId', 'amount', 'reference'?, 'note'?,
 * 'allocations'? => list of ['purchaseId', 'amount'], 'occurredAt'? => Carbon, 'isDemo'?].
 * Without allocations the payment settles the oldest unpaid purchases first.
 */
final class SupplierPaymentService
{
    /** @return array{paymentId: int, paymentNo: string, journalEntryId: int, newBalance: int} */
    public static function recordSupplierPayment(array $input, Actor $actor): array
    {
        if ($input['amount'] <= 0) {
            throw new ValidationError('Enter an amount greater than zero.');
        }
        // Caught here, not by the unique index, whose error would blame "someone else".
        $purchaseIds = array_map(fn (array $a): int => (int) $a['purchaseId'], $input['allocations'] ?? []);
        if (count($purchaseIds) !== count(array_unique($purchaseIds))) {
            throw new ValidationError('Each purchase can appear only once in a payment. Put the whole amount for a purchase on one line.');
        }

        return Tx::run(function () use ($input, $actor): array {
            $occurredAt = $input['occurredAt'] ?? Clock::now();
            $at = Clock::stamp($occurredAt);

            // Lock order (Tx): the supplier, then the purchases it settles.
            $supplier = SupplierService::lockSupplier($input['supplierId']) ?? throw new NotFoundError('Supplier', $input['supplierId']);
            // Tx 3a: the account the money leaves, shared, so archiving it waits.
            $account = PaymentAccountService::lockAccounts([$input['paymentAccountId']])[$input['paymentAccountId']] ?? throw new NotFoundError('Payment account', $input['paymentAccountId']);
            if (! $account->is_active) {
                throw new ValidationError("Payment account \"{$account->name}\" is not active.");
            }

            // More than owed is the shop's policy: off by default, since it is
            // usually a typo; on, the excess settles against the next delivery.
            $currentBalance = SupplierService::getSupplierBalance($input['supplierId']);
            $settings = DB::table('business_settings')->where('id', 1)->first();
            $currency = $settings->currency_code ?? 'GHS';
            if ($input['amount'] > $currentBalance && ! ($settings->allow_overpayment ?? false)) {
                throw new ValidationError(
                    "You owe {$supplier->name} ".Money::format($currentBalance, $currency).'. You cannot pay more than that. '
                        .'To pay in advance, switch on "Allow paying more than is owed" in Settings.',
                    ['currentBalance' => $currentBalance, 'amount' => $input['amount']],
                );
            }

            JournalService::readLockDate();
            $paymentNo = SequenceService::next(DocType::PaymentOut);
            $paymentId = (int) DB::table('supplier_payments')->insertGetId([
                'payment_no' => $paymentNo,
                'supplier_id' => $input['supplierId'],
                'business_date' => $input['businessDate'],
                'occurred_at' => $at,
                'payment_account_id' => $input['paymentAccountId'],
                'amount_minor' => $input['amount'],
                'reference' => $input['reference'] ?? null,
                'note' => $input['note'] ?? null,
                'status' => 'POSTED',
                'created_by' => $actor->id,
                'is_demo' => $input['isDemo'] ?? false,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $allocations = $input['allocations'] ?? self::autoAllocate($input['supplierId'], $input['amount']);
            foreach ($allocations as $allocation) {
                if ($allocation['amount'] <= 0) {
                    continue;
                }
                $purchase = DB::table('purchases')->where('id', $allocation['purchaseId'])->first() ?? throw new NotFoundError('Purchase', $allocation['purchaseId']);
                if ($purchase->supplier_id === null || (int) $purchase->supplier_id !== (int) $input['supplierId']) {
                    throw new ValidationError('That purchase belongs to a different supplier.');
                }
                $outstanding = PurchaseService::getPurchaseOutstanding((int) $allocation['purchaseId']);
                if ($allocation['amount'] > $outstanding) {
                    throw new ValidationError("Cannot allocate more than is outstanding on {$purchase->purchase_no}.");
                }
                DB::table('supplier_payment_allocations')->insert([
                    'payment_id' => $paymentId,
                    'purchase_id' => $allocation['purchaseId'],
                    'amount_minor' => $allocation['amount'],
                    'created_at' => $at,
                ]);
            }

            $posted = JournalService::post(
                $input['businessDate'],
                JournalSourceType::SupplierPayment,
                [
                    Journal::debit(JournalService::accountIdByCode(ChartOfAccounts::ACCOUNTS_PAYABLE), $input['amount'], [
                        'supplierId' => (int) $input['supplierId'],
                        'description' => "{$paymentNo} settles debt",
                    ]),
                    Journal::credit((int) $account->gl_account_id, $input['amount'], [
                        'paymentAccountId' => (int) $input['paymentAccountId'],
                        'description' => "{$paymentNo} paid to {$supplier->name}",
                    ]),
                ],
                sourceId: $paymentId,
                memo: "{$paymentNo} — payment to {$supplier->name}",
                isDemo: $input['isDemo'] ?? false,
                occurredAt: $occurredAt,
                actor: $actor,
            );
            DB::table('supplier_payments')->where('id', $paymentId)->update(['journal_entry_id' => $posted['entryId'], 'updated_at' => $at]);

            AuditService::write(AuditAction::Create, 'supplier_payment', "{$paymentNo}: paid ".Money::format($input['amount'], $currency)." to {$supplier->name}", $paymentId, $actor, [
                'amountMinor' => $input['amount'],
                'entryNo' => $posted['entryNo'],
            ], $occurredAt);

            return ['paymentId' => $paymentId, 'paymentNo' => $paymentNo, 'journalEntryId' => $posted['entryId'], 'newBalance' => Money::subtract($currentBalance, $input['amount'])];
        });
    }

    /** Oldest unpaid purchase first. @return list<array{purchaseId: int, amount: int}> */
    private static function autoAllocate(int $supplierId, int $amount): array
    {
        $allocations = [];
        $remaining = $amount;
        foreach (PurchaseService::getOpenPurchases($supplierId) as $purchase) {
            if ($remaining <= 0) {
                break;
            }
            $applied = min($purchase['outstandingMinor'], $remaining);
            $allocations[] = ['purchaseId' => $purchase['id'], 'amount' => $applied];
            $remaining = Money::subtract($remaining, $applied);
        }

        return $allocations;
    }

    /**
     * Void a payment: the money comes back and the debt is restored. The
     * allocations stay, as the record of what it paid; every reader counts
     * only posted payments, so marking it voided frees the purchases.
     */
    public static function voidSupplierPayment(int $paymentId, string $reason, Actor $actor, ?Carbon $now = null): void
    {
        $why = JsCompat::trim($reason);
        if (mb_strlen($why) < 3) {
            throw new ValidationError('Give a reason for voiding this payment.');
        }
        $now ??= Clock::now();

        Tx::run(function () use ($paymentId, $why, $actor, $now): void {
            $supplierId = DB::table('supplier_payments')->where('id', $paymentId)->value('supplier_id');
            if ($supplierId !== null) {
                SupplierService::lockSupplier((int) $supplierId);
            }
            $payment = DB::table('supplier_payments')->where('id', $paymentId)->lockForUpdate()->first() ?? throw new NotFoundError('Supplier payment', $paymentId);
            if ($payment->status === 'VOIDED') {
                throw new ConflictError('That payment has already been voided.');
            }
            // Tx 3a: the accounts the reversal moves, shared, so archiving or counting one waits.
            PaymentAccountService::lockAccountsOfEntry($payment->journal_entry_id);

            if ($payment->journal_entry_id !== null) {
                JournalService::reverse(
                    (int) $payment->journal_entry_id,
                    Clock::dateOf($now),
                    JournalSourceType::SupplierPayment,
                    $paymentId,
                    "Void of {$payment->payment_no}: {$why}",
                    occurredAt: $now,
                    actor: $actor,
                );
            }

            DB::table('supplier_payments')->where('id', $paymentId)->update([
                'status' => 'VOIDED',
                'voided_at' => Clock::stamp($now),
                'void_reason' => $why,
                'updated_at' => Clock::stamp($now),
            ]);
            AuditService::write(AuditAction::Void, 'supplier_payment', "Voided payment {$payment->payment_no}", $paymentId, $actor, ['reason' => $why], $now);
        });
    }

    // --- reads --------------------------------------------------------------

    /** @return list<array> */
    /** Whether a payment is this supplier's, however old: the supplier page voids only its own. */
    public static function paymentBelongsToSupplier(int $paymentId, int $supplierId): bool
    {
        return DB::table('supplier_payments')->where('supplier_payments.id', $paymentId)->where('supplier_payments.supplier_id', $supplierId)->exists();
    }

    public static function listSupplierPayments(?int $supplierId = null, int $limit = 100): array
    {
        $q = DB::table('supplier_payments')
            ->join('suppliers', 'suppliers.id', '=', 'supplier_payments.supplier_id')
            ->join('payment_accounts', 'payment_accounts.id', '=', 'supplier_payments.payment_account_id');
        if ($supplierId !== null) {
            $q->where('supplier_payments.supplier_id', $supplierId);
        }

        return $q->orderByDesc('supplier_payments.occurred_at')->orderByDesc('supplier_payments.id')
            ->limit(min($limit, 500))
            ->get([
                'supplier_payments.id', 'supplier_payments.payment_no', 'supplier_payments.supplier_id', 'suppliers.name AS supplier_name',
                'supplier_payments.business_date', 'supplier_payments.occurred_at', 'supplier_payments.amount_minor',
                'payment_accounts.name AS account_name', 'supplier_payments.reference', 'supplier_payments.status',
            ])
            ->map(Row::camel(...))->all();
    }
}
