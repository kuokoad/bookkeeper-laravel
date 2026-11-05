<?php

namespace App\Domain\Accounting;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Enums\PaymentAccountKind;

/**
 * The seeded chart of accounts. Codes are stable: services find accounts by
 * code, so a code here never changes meaning. Ported from the old
 * domain/accounting/chart-of-accounts.ts.
 */
final class ChartOfAccounts
{
    public const CASH = '1000';

    public const MOBILE_MONEY = '1010';

    public const BANK = '1020';

    public const ACCOUNTS_RECEIVABLE = '1100';

    public const INVENTORY = '1200';

    public const ACCOUNTS_PAYABLE = '2000';

    public const TAX_PAYABLE = '2100';

    public const NHIL_PAYABLE = '2110';

    public const GETFUND_PAYABLE = '2120';

    public const OWNERS_CAPITAL = '3000';

    public const OWNERS_DRAWINGS = '3100';

    public const RETAINED_EARNINGS = '3200';

    public const OPENING_BALANCE_EQUITY = '3900';

    public const SALES_REVENUE = '4000';

    public const SALES_DISCOUNTS = '4100';

    public const SALES_RETURNS = '4150';

    public const OTHER_INCOME = '4200';

    public const COST_OF_GOODS_SOLD = '5000';

    public const INVENTORY_SHRINKAGE = '5900';

    public const CASH_OVER_SHORT = '5910';

    public const OPERATING_EXPENSES = '6000';

    public static function normalBalanceFor(AccountType $type): NormalBalance
    {
        return match ($type) {
            AccountType::Asset, AccountType::Expense, AccountType::Cogs,
            AccountType::ContraEquity, AccountType::ContraRevenue => NormalBalance::Debit,
            AccountType::Liability, AccountType::Equity, AccountType::Revenue => NormalBalance::Credit,
        };
    }

    /**
     * @return list<array{code: string, name: string, type: AccountType, description: string, sortOrder: int}>
     */
    public static function systemAccounts(): array
    {
        return [
            ['code' => self::CASH, 'name' => 'Cash', 'type' => AccountType::Asset, 'description' => 'Heading for physical cash. Each till is a child account.', 'sortOrder' => 100],
            ['code' => self::MOBILE_MONEY, 'name' => 'Mobile Money', 'type' => AccountType::Asset, 'description' => 'Heading for mobile money wallets. Each wallet is a child account.', 'sortOrder' => 110],
            ['code' => self::BANK, 'name' => 'Bank', 'type' => AccountType::Asset, 'description' => 'Heading for bank accounts. Each account is a child account.', 'sortOrder' => 120],
            ['code' => self::ACCOUNTS_RECEIVABLE, 'name' => 'Accounts Receivable', 'type' => AccountType::Asset, 'description' => 'Money customers owe the shop for credit sales.', 'sortOrder' => 130],
            ['code' => self::INVENTORY, 'name' => 'Inventory', 'type' => AccountType::Asset, 'description' => 'Value of goods held for sale, at weighted average cost.', 'sortOrder' => 140],
            ['code' => self::ACCOUNTS_PAYABLE, 'name' => 'Accounts Payable', 'type' => AccountType::Liability, 'description' => 'Money the shop owes suppliers.', 'sortOrder' => 200],
            ['code' => self::TAX_PAYABLE, 'name' => 'VAT Payable', 'type' => AccountType::Liability, 'description' => 'VAT charged on sales, less VAT paid on purchases, not yet remitted. A debit balance means the authority owes the shop.', 'sortOrder' => 210],
            ['code' => self::NHIL_PAYABLE, 'name' => 'NHIL Payable', 'type' => AccountType::Liability, 'description' => 'National Health Insurance Levy collected on sales. Not reclaimable on purchases, so nothing is ever set against it.', 'sortOrder' => 211],
            ['code' => self::GETFUND_PAYABLE, 'name' => 'GETFund Levy Payable', 'type' => AccountType::Liability, 'description' => 'Ghana Education Trust Fund levy collected on sales. Not reclaimable on purchases.', 'sortOrder' => 212],
            ['code' => self::OWNERS_CAPITAL, 'name' => "Owner's Capital", 'type' => AccountType::Equity, 'description' => 'Money and goods the owner has put into the business.', 'sortOrder' => 300],
            ['code' => self::OWNERS_DRAWINGS, 'name' => "Owner's Drawings", 'type' => AccountType::ContraEquity, 'description' => 'Money or goods the owner has taken out for personal use.', 'sortOrder' => 310],
            ['code' => self::RETAINED_EARNINGS, 'name' => 'Retained Earnings', 'type' => AccountType::Equity, 'description' => 'Accumulated profit kept in the business from prior periods.', 'sortOrder' => 320],
            ['code' => self::OPENING_BALANCE_EQUITY, 'name' => 'Opening Balance Equity', 'type' => AccountType::Equity, 'description' => 'Balancing account used only when entering opening balances at setup. Should return to zero once setup is complete.', 'sortOrder' => 390],
            ['code' => self::SALES_REVENUE, 'name' => 'Sales Revenue', 'type' => AccountType::Revenue, 'description' => 'Income from selling goods.', 'sortOrder' => 400],
            ['code' => self::SALES_DISCOUNTS, 'name' => 'Sales Discounts', 'type' => AccountType::ContraRevenue, 'description' => 'Discounts given to customers, shown as a reduction of revenue.', 'sortOrder' => 410],
            ['code' => self::SALES_RETURNS, 'name' => 'Sales Returns', 'type' => AccountType::ContraRevenue, 'description' => 'Value of goods customers brought back, shown as a reduction of revenue rather than hidden by editing the original sale.', 'sortOrder' => 415],
            ['code' => self::OTHER_INCOME, 'name' => 'Other Income', 'type' => AccountType::Revenue, 'description' => 'Income that is not from selling stock, such as commission or services.', 'sortOrder' => 420],
            ['code' => self::COST_OF_GOODS_SOLD, 'name' => 'Cost of Goods Sold', 'type' => AccountType::Cogs, 'description' => 'What the goods sold actually cost the shop.', 'sortOrder' => 500],
            ['code' => self::INVENTORY_SHRINKAGE, 'name' => 'Inventory Shrinkage', 'type' => AccountType::Expense, 'description' => 'Value of stock lost, damaged, expired or written off.', 'sortOrder' => 590],
            ['code' => self::CASH_OVER_SHORT, 'name' => 'Cash Over / Short', 'type' => AccountType::Expense, 'description' => 'Unexplained differences found during cash or MoMo reconciliation. Kept visible rather than hidden by editing history.', 'sortOrder' => 591],
            ['code' => self::OPERATING_EXPENSES, 'name' => 'Operating Expenses', 'type' => AccountType::Expense, 'description' => 'Parent account for day-to-day running costs.', 'sortOrder' => 600],
        ];
    }

    /** @return list<array{code: string, name: string}> The owner can add, rename or deactivate these. */
    public static function defaultExpenseCategories(): array
    {
        return [
            ['code' => '6010', 'name' => 'Rent'],
            ['code' => '6020', 'name' => 'Electricity'],
            ['code' => '6030', 'name' => 'Water'],
            ['code' => '6040', 'name' => 'Internet & Airtime'],
            ['code' => '6050', 'name' => 'Transport'],
            ['code' => '6060', 'name' => 'Staff Wages'],
            ['code' => '6070', 'name' => 'Repairs & Maintenance'],
            ['code' => '6080', 'name' => 'Packaging'],
            ['code' => '6090', 'name' => 'Bank Charges'],
            ['code' => '6100', 'name' => 'MoMo Charges'],
            ['code' => '6110', 'name' => 'Licences & Permits'],
            ['code' => '6900', 'name' => 'Miscellaneous'],
        ];
    }

    /** @return list<array{code: string, name: string}> */
    public static function defaultIncomeCategories(): array
    {
        return [
            ['code' => '4210', 'name' => 'Commission'],
            ['code' => '4220', 'name' => 'Service Income'],
            ['code' => '4290', 'name' => 'Miscellaneous Income'],
        ];
    }

    /**
     * Each payment account owns its own GL leaf under a heading. No mobile
     * network is hard-coded: MTN is only the seeded example.
     *
     * @return list<array{name: string, kind: PaymentAccountKind, provider: ?string, glCode: string, glName: string, parentCode: string, isDefault: bool, sortOrder: int}>
     */
    public static function defaultPaymentAccounts(): array
    {
        return [
            ['name' => 'Cash', 'kind' => PaymentAccountKind::Cash, 'provider' => null, 'glCode' => '1001', 'glName' => 'Cash on Hand', 'parentCode' => self::CASH, 'isDefault' => true, 'sortOrder' => 10],
            ['name' => 'MTN MoMo', 'kind' => PaymentAccountKind::MobileMoney, 'provider' => 'MTN', 'glCode' => '1011', 'glName' => 'MTN Mobile Money', 'parentCode' => self::MOBILE_MONEY, 'isDefault' => false, 'sortOrder' => 20],
            ['name' => 'Bank Account', 'kind' => PaymentAccountKind::Bank, 'provider' => null, 'glCode' => '1021', 'glName' => 'Bank Account', 'parentCode' => self::BANK, 'isDefault' => false, 'sortOrder' => 30],
        ];
    }
}
