<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Enums\BusinessType;
use App\Enums\JournalSourceType;
use App\Enums\Look;
use App\Services\Actor;
use App\Services\JournalService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * Helpers the service tests share, in the spirit of the old tests/helpers.
 * Everything runs on the seeded baseline inside the test's transaction.
 */
final class Shop
{
    /**
     * A signed-in person. Inserted directly until the user service arrives in
     * Phase 8; only the id and name matter to the services under test.
     */
    public static function owner(string $username = 'kwame'): Actor
    {
        $id = (int) DB::table('users')->insertGetId([
            'username' => $username,
            'display_name' => ucfirst($username),
            'role' => 'OWNER',
            'password_hash' => 'not-a-real-hash',
        ]);

        return new Actor($id, $username);
    }

    public static function accountId(string $code): int
    {
        return (int) DB::table('accounts')->where('code', $code)->value('id');
    }

    /** The current settings, as an input ready to be changed and saved. */
    public static function settingsInput(array $changes = []): array
    {
        $s = SettingsService::get();

        return array_merge([
            'businessName' => $s->business_name,
            'tagline' => $s->tagline,
            'address' => $s->address,
            'phone' => $s->phone,
            'email' => $s->email,
            'currencyCode' => $s->currency_code,
            'currencySymbol' => $s->currency_symbol,
            'look' => Look::from($s->look),
            'businessType' => BusinessType::from($s->business_type),
            'features' => SettingsService::features($s),
            'taxEnabled' => (bool) $s->tax_enabled,
            'taxInclusive' => (bool) $s->tax_inclusive,
            'lowStockThresholdMilli' => (int) $s->low_stock_threshold_milli,
            'allowNegativeStock' => (bool) $s->allow_negative_stock,
            'expiryWarningDays' => (int) $s->expiry_warning_days,
            'expiryBlocksSales' => (bool) $s->expiry_blocks_sales,
            'allowOverpayment' => (bool) $s->allow_overpayment,
            'defaultTermsDays' => (int) $s->default_terms_days,
            'financialYearStartMonth' => (int) $s->financial_year_start_month,
        ], $changes);
    }

    /** The owner puts money in: one balanced opening entry, inside its own write. */
    public static function postOpeningCapital(string $date = '2026-08-17', int $amount = 50_000): array
    {
        return Tx::run(fn (): array => JournalService::post(
            $date,
            JournalSourceType::OpeningBalance,
            [
                Journal::debit(self::accountId('1001'), $amount),
                Journal::credit(self::accountId(ChartOfAccounts::OWNERS_CAPITAL), $amount),
            ],
            memo: 'Owner puts money in',
            isOpening: true,
        ));
    }

    /** @return list<string> */
    public static function settingsAuditSummaries(): array
    {
        return DB::table('audit_logs')->where('action', 'SETTINGS_CHANGE')->orderByDesc('id')->pluck('summary')->all();
    }
}
