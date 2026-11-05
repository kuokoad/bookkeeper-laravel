<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Accounting\Journal;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\JsCompat;
use App\Enums\AuditAction;
use App\Enums\DocType;
use App\Enums\JournalSourceType;
use App\Support\Clock;
use App\Support\Row;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moving money between the shop's own accounts: banking the day's cash,
 * drawing MoMo into the till.
 *
 *   Dr to-account (Bank)   Cr from-account (Cash)
 *
 * The total held, the profit and the owner's equity are unchanged. No
 * overdraft check, as in the old app. A port of the old transfer.service.ts.
 *
 * Input: ['businessDate', 'fromAccountId', 'toAccountId', 'amount',
 * 'reference'?, 'note'?, 'occurredAt'? => Carbon, 'isDemo'?].
 */
final class TransferService
{
    /** @return array{id: int, transferNo: string, journalEntryId: int} */
    public static function recordTransfer(array $input, Actor $actor): array
    {
        if ($input['amount'] <= 0) {
            throw new ValidationError('Enter an amount greater than zero.');
        }
        if ($input['fromAccountId'] === $input['toAccountId']) {
            throw new ValidationError('Choose two different accounts to move money between.');
        }

        return Tx::run(function () use ($input, $actor): array {
            $occurredAt = $input['occurredAt'] ?? Clock::now();
            $at = Clock::stamp($occurredAt);

            // Both accounts locked together, by id (Tx), then judged from and to, as the old app did.
            $locked = PaymentAccountService::lockAccounts([$input['fromAccountId'], $input['toAccountId']]);
            $usable = function (int $id) use ($locked): object {
                $account = $locked[$id] ?? throw new NotFoundError('Payment account', $id);
                if (! $account->is_active) {
                    throw new ValidationError("\"{$account->name}\" is archived. Choose an account still in use.");
                }

                return $account;
            };
            $from = $usable($input['fromAccountId']);
            $to = $usable($input['toAccountId']);

            JournalService::readLockDate();
            $transferNo = SequenceService::next(DocType::Transfer);
            $reference = self::blankToNull($input['reference'] ?? null);
            $note = self::blankToNull($input['note'] ?? null);

            $id = (int) DB::table('account_transfers')->insertGetId([
                'transfer_no' => $transferNo,
                'business_date' => $input['businessDate'],
                'occurred_at' => $at,
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount_minor' => $input['amount'],
                'reference' => $reference,
                'note' => $note,
                'status' => 'POSTED',
                'created_by' => $actor->id,
                'is_demo' => $input['isDemo'] ?? false,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $description = "{$transferNo} {$from->name} to {$to->name}";
            $posted = JournalService::post(
                $input['businessDate'],
                JournalSourceType::Transfer,
                [
                    Journal::debit((int) $to->gl_account_id, $input['amount'], ['paymentAccountId' => (int) $to->id, 'description' => "{$transferNo} from {$from->name}"]),
                    Journal::credit((int) $from->gl_account_id, $input['amount'], ['paymentAccountId' => (int) $from->id, 'description' => "{$transferNo} to {$to->name}"]),
                ],
                sourceId: $id,
                memo: $reference !== null ? "{$description} — {$reference}" : $description,
                isDemo: $input['isDemo'] ?? false,
                occurredAt: $occurredAt,
                actor: $actor,
            );
            DB::table('account_transfers')->where('id', $id)->update(['journal_entry_id' => $posted['entryId'], 'updated_at' => $at]);

            AuditService::write(AuditAction::Create, 'account_transfer', "{$transferNo}: {$from->name} to {$to->name}", $id, $actor, [
                'amountMinor' => $input['amount'],
                'entryNo' => $posted['entryNo'],
            ], $occurredAt);

            return ['id' => $id, 'transferNo' => $transferNo, 'journalEntryId' => $posted['entryId']];
        });
    }

    /**
     * Undo a transfer by a reversal dated today. The original row and entry
     * stay, so a transfer inside a closed period is corrected without
     * reopening it.
     */
    public static function voidTransfer(int $id, string $reason, Actor $actor, ?Carbon $now = null): void
    {
        $why = JsCompat::trim($reason);
        if (mb_strlen($why) < 3) {
            throw new ValidationError('Give a reason for voiding this transfer.');
        }
        $now ??= Clock::now();

        Tx::run(function () use ($id, $why, $actor, $now): void {
            $row = DB::table('account_transfers')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundError('Transfer', $id);
            if ($row->status === 'VOIDED') {
                throw new ConflictError('That transfer has already been voided.');
            }
            // Tx 3a: the accounts the reversal moves, shared, so archiving or counting one waits.
            PaymentAccountService::lockAccountsOfEntry($row->journal_entry_id);

            if ($row->journal_entry_id !== null) {
                JournalService::reverse(
                    (int) $row->journal_entry_id,
                    Clock::dateOf($now),
                    JournalSourceType::Transfer,
                    $id,
                    "Void of {$row->transfer_no}: {$why}",
                    occurredAt: $now,
                    actor: $actor,
                );
            }

            DB::table('account_transfers')->where('id', $id)->update([
                'status' => 'VOIDED',
                'voided_at' => Clock::stamp($now),
                'void_reason' => $why,
                'updated_at' => Clock::stamp($now),
            ]);
            AuditService::write(AuditAction::Void, 'account_transfer', "Voided {$row->transfer_no}", $id, $actor, ['reason' => $why], $now);
        });
    }

    // --- reads --------------------------------------------------------------

    /** Newest first, by the day the money moved. @return list<array> */
    public static function listTransfers(array $query = []): array
    {
        return DB::table('account_transfers')
            ->join('payment_accounts AS from_account', 'from_account.id', '=', 'account_transfers.from_account_id')
            ->join('payment_accounts AS to_account', 'to_account.id', '=', 'account_transfers.to_account_id')
            ->leftJoin('users', 'users.id', '=', 'account_transfers.created_by')
            ->orderByDesc('account_transfers.business_date')->orderByDesc('account_transfers.id')
            ->limit($query['limit'] ?? 50)->offset($query['offset'] ?? 0)
            ->get([
                'account_transfers.id', 'account_transfers.transfer_no', 'account_transfers.business_date',
                'account_transfers.from_account_id', 'from_account.name AS from_account_name',
                'account_transfers.to_account_id', 'to_account.name AS to_account_name',
                'account_transfers.amount_minor AS amount', 'account_transfers.reference', 'account_transfers.note',
                'account_transfers.status', 'account_transfers.void_reason', 'users.display_name AS created_by_name',
            ])->map(Row::camel(...))->all();
    }

    public static function countTransfers(): int
    {
        return DB::table('account_transfers')->count();
    }

    private static function blankToNull(?string $value): ?string
    {
        $trimmed = JsCompat::trim($value ?? '');

        return $trimmed === '' ? null : $trimmed;
    }
}
