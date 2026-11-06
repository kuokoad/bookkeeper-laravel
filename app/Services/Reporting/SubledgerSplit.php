<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Money;
use Illuminate\Support\Facades\DB;

/**
 * A control account split by the sign of each party's own balance: what is
 * owed TO the shop, and what the shop holds FOR someone in credit. The two
 * still sum to the control account. A port of the old
 * reporting/subledger-split.ts.
 */
final class SubledgerSplit
{
    /** @return array{owed: int, inCredit: int, net: int, creditCount: int} */
    public static function getReceivablesSplit(?string $asAt = null): array
    {
        return self::split(ChartOfAccounts::ACCOUNTS_RECEIVABLE, 'customer_id', $asAt, debitNormal: true);
    }

    /** @return array{owed: int, inCredit: int, net: int, creditCount: int} */
    public static function getPayablesSplit(?string $asAt = null): array
    {
        return self::split(ChartOfAccounts::ACCOUNTS_PAYABLE, 'supplier_id', $asAt, debitNormal: false);
    }

    private static function split(string $code, string $party, ?string $asAt, bool $debitNormal): array
    {
        $query = DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('accounts.code', $code)
            ->whereNotNull("journal_lines.{$party}")
            ->groupBy("journal_lines.{$party}")
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor), 0) AS d, COALESCE(SUM(journal_lines.credit_minor), 0) AS c');
        if ($asAt !== null) {
            $query->where('journal_entries.entry_date', '<=', $asAt);
        }

        $owed = 0;
        $inCredit = 0;
        $creditCount = 0;
        foreach ($query->get() as $row) {
            $balance = $debitNormal ? Money::subtract((int) $row->d, (int) $row->c) : Money::subtract((int) $row->c, (int) $row->d);
            if ($balance > 0) {
                $owed = Money::add($owed, $balance);
            } elseif ($balance < 0) {
                $inCredit = Money::subtract($inCredit, $balance);
                $creditCount++;
            }
        }

        return ['owed' => $owed, 'inCredit' => $inCredit, 'net' => Money::subtract($owed, $inCredit), 'creditCount' => $creditCount];
    }
}
