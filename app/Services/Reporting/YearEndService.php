<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\FinancialYear;
use App\Domain\Money;
use App\Services\SettingsService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * The year-end pack an accountant is handed, composed entirely from the
 * reporting services the app uses all year, plus the movement in the
 * owner's stake. A port of the old reporting/year-end.service.ts.
 */
final class YearEndService
{
    /** @return list<FinancialYear> The years the shop has entries in, newest first. */
    public static function availableFinancialYears(): array
    {
        $startMonth = (int) SettingsService::get()->financial_year_start_month;
        $span = DB::table('journal_entries')->selectRaw('MIN(journal_entries.entry_date) AS earliest, MAX(journal_entries.entry_date) AS latest')->first();

        if ($span->earliest === null || $span->latest === null) {
            // Nothing posted yet: offer the year we are in, so the page still works.
            $today = Clock::today();

            return [FinancialYear::between($today, $today, $startMonth)[0]];
        }

        return FinancialYear::between((string) $span->earliest, (string) $span->latest, $startMonth);
    }

    /**
     * Drawings as the movement on 3100 within the year, closing entries left
     * out: a closed year's drawings account ends at zero.
     */
    private static function drawingsDuringYear(FinancialYear $year): int
    {
        foreach (BalancesService::getAccountBalances(['from' => $year->start, 'to' => $year->end, 'excludeClosing' => true]) as $account) {
            if ($account['code'] === ChartOfAccounts::OWNERS_DRAWINGS) {
                return $account['balance'];
            }
        }

        return 0;
    }

    /** Opening + capital − drawings + opening balances + profit, checked against the closing stake. */
    public static function equityMovement(array $opening, array $closing, int $profitForYear, int $drawings): array
    {
        $capitalIntroduced = Money::subtract($closing['ownersCapital'], $opening['ownersCapital']);
        $openingBalancesRecognised = Money::subtract($closing['openingBalanceEquity'], $opening['openingBalanceEquity']);
        $expected = $opening['totalEquity'] + $capitalIntroduced - $drawings + $openingBalancesRecognised + $profitForYear;
        $difference = Money::subtract($closing['totalEquity'], $expected);

        return [
            'openingEquity' => $opening['totalEquity'],
            'capitalIntroduced' => $capitalIntroduced,
            'drawings' => $drawings,
            'openingBalancesRecognised' => $openingBalancesRecognised,
            'profitForYear' => $profitForYear,
            'closingEquity' => $closing['totalEquity'],
            'reconciles' => $difference === 0,
            'difference' => $difference,
        ];
    }

    public static function getYearEndPack(int $startYear): array
    {
        $settings = SettingsService::get();
        $startMonth = (int) $settings->financial_year_start_month;
        $year = FinancialYear::of($startYear, $startMonth);
        $previous = $year->previous($startMonth);
        $period = ['from' => $year->start, 'to' => $year->end];

        $profitAndLoss = FinancialService::getProfitAndLoss($period);
        $balanceSheet = FinancialService::getBalanceSheet($year->end);
        // The opening position is the previous year's end, not this year's first day.
        $previousBalanceSheet = FinancialService::getBalanceSheet($previous->end);

        $lockedBefore = $settings->books_locked_before === null ? null : (string) $settings->books_locked_before;
        $today = Clock::today();
        // Ageing measures the gap to now: a provisional pack ages to today, a finished year to its end.
        $ageingAsAt = $today < $year->end ? $today : $year->end;

        return [
            'year' => $year,
            'previous' => $previous,
            'shop' => [
                'name' => $settings->business_name,
                'address' => $settings->address,
                'phone' => $settings->phone,
                'email' => $settings->email,
                'currencyCode' => $settings->currency_code,
            ],
            'profitAndLoss' => $profitAndLoss,
            'previousProfitAndLoss' => FinancialService::getProfitAndLoss(['from' => $previous->start, 'to' => $previous->end]),
            'balanceSheet' => $balanceSheet,
            'previousBalanceSheet' => $previousBalanceSheet,
            'cashFlow' => FinancialService::getCashFlow($period),
            'trialBalance' => BalancesService::getTrialBalance(['to' => $year->end]),
            'receivables' => LedgerService::getReceivablesAgeing($ageingAsAt),
            'payables' => LedgerService::getPayablesAgeing($ageingAsAt),
            'ageingAsAt' => $ageingAsAt,
            'equity' => self::equityMovement($previousBalanceSheet, $balanceSheet, $profitAndLoss['netProfit'], self::drawingsDuringYear($year)),
            'integrity' => LedgerService::checkBooksIntegrity(),
            'entryCount' => DB::table('journal_entries')->whereBetween('journal_entries.entry_date', [$year->start, $year->end])->count(),
            'isLocked' => $lockedBefore !== null && $lockedBefore >= $year->end,
            'booksLockedBefore' => $lockedBefore,
            // A year still in progress is a draft and must say so.
            'isProvisional' => $today <= $year->end,
        ];
    }
}
