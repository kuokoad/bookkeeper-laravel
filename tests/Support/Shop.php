<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Domain\FinancialYear;
use App\Enums\AccountType;
use App\Enums\AdjustmentReason;
use App\Enums\BusinessType;
use App\Enums\JournalSourceType;
use App\Enums\Look;
use App\Services\Actor;
use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\JournalService;
use App\Services\SaleService;
use App\Services\SettingsService;
use App\Services\StockAdjustmentService;
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

    /**
     * An account's balance on its normal side, a heading including its
     * children: the old reporting getAccountBalanceByCode, until the reports
     * arrive in Phase 7.
     */
    public static function balance(string $code): int
    {
        $account = DB::table('accounts')->where('code', $code)->first();
        if ($account === null) {
            return 0;
        }
        $ids = DB::table('accounts')->where('parent_id', $account->id)->pluck('id')->push($account->id)->all();
        $totals = DB::table('journal_lines')->whereIn('account_id', $ids)
            ->selectRaw('COALESCE(SUM(debit_minor), 0) AS d, COALESCE(SUM(credit_minor), 0) AS c')->first();

        return Journal::signedBalance(AccountType::from($account->type), (int) $totals->d, (int) $totals->c);
    }

    /** Every journal line together: debits equal credits. */
    public static function trialBalanced(): bool
    {
        $totals = DB::table('journal_lines')->selectRaw('COALESCE(SUM(debit_minor), 0) AS d, COALESCE(SUM(credit_minor), 0) AS c')->first();

        return (int) $totals->d === (int) $totals->c;
    }

    /** A product the way the old tests made one: pcs, 5.00 cost, 8.00 selling unless told otherwise. */
    public static function product(Actor $actor, string $name, int $costPrice = 500, int $sellingPrice = 800, array $extra = []): int
    {
        return CatalogService::createProduct(['name' => $name, 'costPrice' => $costPrice, 'sellingPrice' => $sellingPrice, 'unit' => 'pcs', ...$extra], $actor);
    }

    /** Opening stock through a real adjustment: the only legitimate route. */
    public static function openingStock(Actor $actor, int $productId, int $qtyMilli, int $totalCost, string $date = '2026-08-16'): array
    {
        return StockAdjustmentService::createStockAdjustment([
            'businessDate' => $date,
            'reason' => AdjustmentReason::OpeningStock,
            'items' => [['productId' => $productId, 'direction' => 'IN', 'qty' => $qtyMilli, 'totalCost' => $totalCost]],
        ], $actor);
    }

    /** A one-line adjustment out (DAMAGED unless told otherwise), costed at the average. */
    public static function stockOut(Actor $actor, int $productId, int $qtyMilli, string $date = '2026-08-16', AdjustmentReason $reason = AdjustmentReason::Damaged): array
    {
        return StockAdjustmentService::createStockAdjustment([
            'businessDate' => $date,
            'reason' => $reason,
            'items' => [['productId' => $productId, 'direction' => 'OUT', 'qty' => $qtyMilli]],
        ], $actor);
    }

    /** A movement straight through the gateway, in its own write, as the old tests did with writeTransaction. */
    public static function move(array $input): array
    {
        return Tx::run(fn (): array => InventoryService::recordStockMovement($input));
    }

    public static function productRow(int $id): object
    {
        return DB::table('products')->where('id', $id)->first();
    }

    public static function setting(string $column, mixed $value): void
    {
        DB::table('business_settings')->where('id', 1)->update([$column => $value]);
    }

    /** The seeded till account of a kind: CASH, MOBILE_MONEY, BANK. */
    public static function paymentAccount(string $kind = 'CASH'): int
    {
        return (int) DB::table('payment_accounts')->where('kind', $kind)->orderBy('id')->value('id');
    }

    public static function customer(Actor $actor, string $name = 'Ama Serwaa', ?int $creditLimit = null, array $extra = []): int
    {
        return CustomerService::createCustomer(['name' => $name, 'creditLimit' => $creditLimit, ...$extra], $actor);
    }

    /**
     * A sale the old tests' way: lines of [productId, qty] or full line arrays,
     * paid in cash in full unless tenders are given. Extra keys go to createSale.
     */
    public static function sell(Actor $actor, array $items, ?array $tenders = null, array $extra = []): array
    {
        $lines = array_map(fn (array $i): array => array_is_list($i) ? ['productId' => $i[0], 'qty' => $i[1]] : $i, $items);

        return SaleService::createSale([
            'businessDate' => '2026-08-16',
            'items' => $lines,
            'tenders' => $tenders ?? [],
            ...$extra,
        ], $actor);
    }

    /** One tax at one rate (VAT), the levies switched off: the old helpers/tax setSingleTax. */
    public static function setSingleTax(int $rateBp, bool $inclusive = false): void
    {
        self::setting('tax_enabled', true);
        self::setting('tax_inclusive', $inclusive);
        DB::table('tax_components')->where('code', '<>', 'VAT')->update(['is_active' => false]);
        DB::table('tax_components')->where('code', 'VAT')->update(['rate_bp' => $rateBp, 'is_active' => true]);
    }

    /** NHIL 2.5, GETFund 2.5 and VAT 15, all on: the old setGhanaTaxes. */
    public static function setGhanaTaxes(bool $inclusive = false): void
    {
        self::setting('tax_enabled', true);
        self::setting('tax_inclusive', $inclusive);
        DB::table('tax_components')->update(['is_active' => true]);
    }

    public static function setNoTax(): void
    {
        self::setting('tax_enabled', false);
    }

    /** The calendar month containing a date, inclusive: the old helpers/clock monthOf. */
    public static function monthOf(string $date): array
    {
        $last = FinancialYear::daysInMonth((int) substr($date, 0, 4), (int) substr($date, 5, 2));

        return ['from' => substr($date, 0, 7).'-01', 'to' => substr($date, 0, 7).'-'.str_pad((string) $last, 2, '0', STR_PAD_LEFT)];
    }

    /** The stock and the books agree: trial balance, stock value against 1200, A/R against its control, and every product's replay. */
    public static function assertBooksHealthy(string $label): void
    {
        expect(self::trialBalanced())->toBeTrue("{$label}: trial balance")
            ->and(InventoryService::getInventoryValue())->toBe(self::balance(ChartOfAccounts::INVENTORY), "{$label}: inventory vs GL")
            ->and(CustomerService::getTotalReceivables())->toBe(self::balance(ChartOfAccounts::ACCOUNTS_RECEIVABLE), "{$label}: A/R subledger vs control");
        foreach (DB::table('products')->pluck('id') as $id) {
            expect(InventoryService::verifyProductStock((int) $id)['ok'])->toBeTrue("{$label}: stock drift p{$id}");
        }
    }

    /** @return list<string> */
    public static function settingsAuditSummaries(): array
    {
        return DB::table('audit_logs')->where('action', 'SETTINGS_CHANGE')->orderByDesc('id')->pluck('summary')->all();
    }
}
