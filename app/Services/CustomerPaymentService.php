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
 * Money received from a customer against what they owe:
 * Dr Cash/MoMo/Bank, Cr Accounts Receivable (tagged with the customer).
 * A port of the old customer-payment.service.ts.
 *
 * Input: ['customerId', 'businessDate', 'paymentAccountId', 'amount', 'reference'?, 'note'?,
 * 'allocations'? => list of ['saleId', 'amount'], 'occurredAt'? => Carbon, 'isDemo'?].
 * Without allocations the payment settles the oldest unpaid sales first.
 */
final class CustomerPaymentService
{
    /** @return array{paymentId: int, paymentNo: string, journalEntryId: int, newBalance: int} */
    public static function recordCustomerPayment(array $input, Actor $actor): array
    {
        if ($input['amount'] <= 0) {
            throw new ValidationError('Enter an amount greater than zero.');
        }
        // Caught here, not by the unique index, whose error would blame "someone else".
        $saleIds = array_map(fn (array $a): int => (int) $a['saleId'], $input['allocations'] ?? []);
        if (count($saleIds) !== count(array_unique($saleIds))) {
            throw new ValidationError('Each sale can appear only once in a payment. Put the whole amount for a sale on one line.');
        }

        return Tx::run(function () use ($input, $actor): array {
            $occurredAt = $input['occurredAt'] ?? Clock::now();
            $at = Clock::stamp($occurredAt);

            // Lock order (Tx): the customer, then the sales it settles.
            $customer = CustomerService::lockCustomer($input['customerId']) ?? throw new NotFoundError('Customer', $input['customerId']);
            // The sales this may settle, locked, so a void cannot slip between the
            // outstanding read and the allocation.
            DB::table('sales')->where('customer_id', $input['customerId'])->where('status', 'POSTED')->orderBy('id')->lockForUpdate()->get(['id']);
            // Then (Tx 3a) the account the money goes into, shared, so archiving it waits.
            $account = PaymentAccountService::lockAccounts([$input['paymentAccountId']])[$input['paymentAccountId']] ?? throw new NotFoundError('Payment account', $input['paymentAccountId']);
            if (! $account->is_active) {
                throw new ValidationError("Payment account \"{$account->name}\" is not active.");
            }

            // More than owed is the shop's policy: off by default, since at a
            // counter it is usually a typo; on, the excess stays as a credit.
            $currentBalance = CustomerService::getCustomerBalance($input['customerId']);
            $settings = DB::table('business_settings')->where('id', 1)->first();
            $currency = $settings->currency_code ?? 'GHS';
            if ($input['amount'] > $currentBalance && ! ($settings->allow_overpayment ?? false)) {
                throw new ValidationError(
                    "{$customer->name} owes ".Money::format($currentBalance, $currency).'. You cannot receive more than that. '
                        .'To accept advance payments, switch on "Allow paying more than is owed" in Settings.',
                    ['currentBalance' => $currentBalance, 'amount' => $input['amount']],
                );
            }

            JournalService::readLockDate();
            $paymentNo = SequenceService::next(DocType::PaymentIn);
            $paymentId = (int) DB::table('customer_payments')->insertGetId([
                'payment_no' => $paymentNo,
                'customer_id' => $input['customerId'],
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

            $allocations = $input['allocations'] ?? self::autoAllocate($input['customerId'], $input['amount']);
            if (Money::sum(array_column($allocations, 'amount')) > $input['amount']) {
                throw new ValidationError('The amounts allocated to invoices exceed the payment.');
            }
            foreach ($allocations as $allocation) {
                if ($allocation['amount'] <= 0) {
                    continue;
                }
                $sale = DB::table('sales')->where('id', $allocation['saleId'])->first() ?? throw new NotFoundError('Sale', $allocation['saleId']);
                if ((int) $sale->customer_id !== (int) $input['customerId'] || $sale->customer_id === null) {
                    throw new ValidationError('That sale belongs to a different customer.');
                }
                if ($sale->status === 'VOIDED') {
                    throw new ValidationError('That sale has been voided and cannot be paid.');
                }
                $outstanding = SaleService::getSaleOutstanding($allocation['saleId']);
                if ($allocation['amount'] > $outstanding) {
                    throw new ValidationError("Cannot allocate more than is outstanding on {$sale->receipt_no}.", ['outstanding' => $outstanding, 'requested' => $allocation['amount']]);
                }
                DB::table('customer_payment_allocations')->insert([
                    'payment_id' => $paymentId,
                    'sale_id' => $allocation['saleId'],
                    'amount_minor' => $allocation['amount'],
                    'created_at' => $at,
                ]);
            }

            $receivable = JournalService::accountIdByCode(ChartOfAccounts::ACCOUNTS_RECEIVABLE);
            $posted = JournalService::post(
                $input['businessDate'],
                JournalSourceType::CustomerPayment,
                [
                    Journal::debit((int) $account->gl_account_id, $input['amount'], [
                        'paymentAccountId' => (int) $input['paymentAccountId'],
                        'description' => "{$paymentNo} from {$customer->name}",
                    ]),
                    Journal::credit((int) $receivable, $input['amount'], [
                        'customerId' => (int) $input['customerId'],
                        'description' => "{$paymentNo} settles debt",
                    ]),
                ],
                sourceId: $paymentId,
                memo: "{$paymentNo} — payment from {$customer->name}",
                isDemo: $input['isDemo'] ?? false,
                occurredAt: $occurredAt,
                actor: $actor,
            );
            DB::table('customer_payments')->where('id', $paymentId)->update(['journal_entry_id' => $posted['entryId'], 'updated_at' => $at]);

            AuditService::write(AuditAction::Create, 'customer_payment', "{$paymentNo}: received ".Money::format($input['amount'], $currency)." from {$customer->name}", $paymentId, $actor, [
                'amountMinor' => $input['amount'],
                'paymentAccount' => $account->name,
                'entryNo' => $posted['entryNo'],
            ], $occurredAt);

            return ['paymentId' => $paymentId, 'paymentNo' => $paymentNo, 'journalEntryId' => $posted['entryId'], 'newBalance' => Money::subtract($currentBalance, $input['amount'])];
        });
    }

    /** Oldest unpaid sale first: the convention a shop owner expects. @return list<array{saleId: int, amount: int}> */
    private static function autoAllocate(int $customerId, int $amount): array
    {
        $unpaid = DB::table('sales')->where('sales.customer_id', $customerId)->where('sales.status', 'POSTED')
            ->orderBy('sales.business_date')->orderBy('sales.id')->pluck('id');

        $owed = SaleService::getOutstandingBySale(['customerId' => $customerId]);

        $allocations = [];
        $remaining = $amount;
        foreach ($unpaid as $saleId) {
            if ($remaining <= 0) {
                break;
            }
            $outstanding = $owed[(int) $saleId] ?? 0;
            if ($outstanding <= 0) {
                continue;
            }
            $applied = min($outstanding, $remaining);
            $allocations[] = ['saleId' => (int) $saleId, 'amount' => $applied];
            $remaining = Money::subtract($remaining, $applied);
        }

        return $allocations;
    }

    /**
     * Void a payment: the money goes back out and the debt is restored. The
     * allocations stay, as the record of what it paid; every reader counts
     * only posted payments, so marking it voided frees the sales.
     */
    public static function voidCustomerPayment(int $paymentId, string $reason, Actor $actor, ?Carbon $now = null): void
    {
        $why = JsCompat::trim($reason);
        if (mb_strlen($why) < 3) {
            throw new ValidationError('Give a reason for voiding this payment.');
        }
        $now ??= Clock::now();

        Tx::run(function () use ($paymentId, $why, $actor, $now): void {
            $customerId = DB::table('customer_payments')->where('id', $paymentId)->value('customer_id');
            if ($customerId !== null) {
                CustomerService::lockCustomer((int) $customerId);
            }
            $payment = DB::table('customer_payments')->where('id', $paymentId)->lockForUpdate()->first() ?? throw new NotFoundError('Customer payment', $paymentId);
            if ($payment->status === 'VOIDED') {
                throw new ConflictError('That payment has already been voided.');
            }
            // Tx 3a: the accounts the reversal moves, shared, so archiving or counting one waits.
            PaymentAccountService::lockAccountsOfEntry($payment->journal_entry_id);

            if ($payment->journal_entry_id !== null) {
                JournalService::reverse(
                    (int) $payment->journal_entry_id,
                    Clock::dateOf($now),
                    JournalSourceType::CustomerPayment,
                    $paymentId,
                    "Void of {$payment->payment_no}: {$why}",
                    occurredAt: $now,
                    actor: $actor,
                );
            }

            DB::table('customer_payments')->where('id', $paymentId)->update([
                'status' => 'VOIDED',
                'voided_at' => Clock::stamp($now),
                'void_reason' => $why,
                'updated_at' => Clock::stamp($now),
            ]);
            AuditService::write(AuditAction::Void, 'customer_payment', "Voided payment {$payment->payment_no}", $paymentId, $actor, ['reason' => $why], $now);
        });
    }

    // --- reads --------------------------------------------------------------

    /** @return list<array> */
    public static function listCustomerPayments(?int $customerId = null, int $limit = 100): array
    {
        $q = DB::table('customer_payments')
            ->join('customers', 'customers.id', '=', 'customer_payments.customer_id')
            ->join('payment_accounts', 'payment_accounts.id', '=', 'customer_payments.payment_account_id');
        if ($customerId !== null) {
            $q->where('customer_payments.customer_id', $customerId);
        }

        return $q->orderByDesc('customer_payments.occurred_at')->orderByDesc('customer_payments.id')
            ->limit(min($limit, 500))
            ->get([
                'customer_payments.id', 'customer_payments.payment_no', 'customer_payments.customer_id', 'customers.name AS customer_name',
                'customer_payments.business_date', 'customer_payments.occurred_at', 'customer_payments.amount_minor',
                'payment_accounts.name AS account_name', 'customer_payments.reference', 'customer_payments.status',
            ])
            ->map(Row::camel(...))->all();
    }

    /** Unpaid sales for a customer, oldest first: what the payment form offers. @return list<array> */
    public static function getOpenSales(int $customerId): array
    {
        $outstanding = SaleService::getOutstandingBySale(['customerId' => $customerId]);

        return DB::table('sales')->where('sales.customer_id', $customerId)->where('sales.status', 'POSTED')
            ->orderBy('sales.business_date')->orderBy('sales.id')
            ->get(['sales.id', 'sales.receipt_no', 'sales.business_date', 'sales.total_minor'])
            ->map(fn (object $row): array => [...Row::camel($row), 'outstandingMinor' => $outstanding[(int) $row->id] ?? 0])
            ->filter(fn (array $row): bool => $row['outstandingMinor'] > 0)
            ->values()
            ->all();
    }
}
