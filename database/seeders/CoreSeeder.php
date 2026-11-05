<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\ChartOfAccounts;
use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Enums\DocType;
use App\Services\TaxService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Baseline every shop needs: the settings row, document numbering, the chart
 * of accounts, the default payment accounts and the Ghana tax components.
 *
 * A port of the old src/db/seed/core.ts `seedCore`. Idempotent: existing rows
 * are never touched, so re-running it on a live shop changes nothing but the
 * audit entry that says it ran.
 */
class CoreSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedBusinessSettings();
            $this->seedSequences();
            $this->seedChartOfAccounts();
            $this->seedPaymentAccounts();
            $this->seedTaxComponents();
            TaxService::syncDerivedSettings();

            DB::table('audit_logs')->insert([
                'action' => AuditAction::Create->value,
                'entity_type' => 'system',
                'entity_id' => 'core-seed',
                'summary' => 'Baseline chart of accounts, sequences and payment accounts verified',
            ]);
        });
    }

    private function seedBusinessSettings(): void
    {
        if (! DB::table('business_settings')->where('id', 1)->exists()) {
            DB::table('business_settings')->insert(['id' => 1]);
        }
    }

    private function seedSequences(): void
    {
        foreach (DocType::cases() as $type) {
            DB::table('sequences')->insertOrIgnore([
                'doc_type' => $type->value,
                'prefix' => $type->prefix(),
                'padding' => $type->padding(),
                'next_number' => 1,
            ]);
        }
    }

    private function seedChartOfAccounts(): void
    {
        foreach (ChartOfAccounts::systemAccounts() as $definition) {
            $this->upsertAccount($definition['code'], $definition['name'], $definition['type'], $definition['description'], $definition['sortOrder']);
        }

        $operatingExpenses = $this->accountId(ChartOfAccounts::OPERATING_EXPENSES);
        foreach (ChartOfAccounts::defaultExpenseCategories() as $index => $category) {
            $this->upsertAccount($category['code'], $category['name'], AccountType::Expense, "Operating expense: {$category['name']}.", 610 + $index, $operatingExpenses);
        }

        $otherIncome = $this->accountId(ChartOfAccounts::OTHER_INCOME);
        foreach (ChartOfAccounts::defaultIncomeCategories() as $index => $category) {
            $this->upsertAccount($category['code'], $category['name'], AccountType::Revenue, "Other income: {$category['name']}.", 430 + $index, $otherIncome);
        }
    }

    private function seedPaymentAccounts(): void
    {
        foreach (ChartOfAccounts::defaultPaymentAccounts() as $definition) {
            $glId = DB::table('accounts')->where('code', $definition['glCode'])->value('id') ?? -1;
            $exists = DB::table('payment_accounts')
                ->whereRaw('LOWER(`name`) = LOWER(?)', [$definition['name']])
                ->orWhere('gl_account_id', $glId)
                ->exists();
            if ($exists) {
                continue;
            }

            $glAccountId = $this->upsertAccount(
                $definition['glCode'],
                $definition['glName'],
                AccountType::Asset,
                "Ledger account backing the \"{$definition['name']}\" payment account.",
                $definition['sortOrder'],
                $this->accountId($definition['parentCode']),
            );

            DB::table('payment_accounts')->insert([
                'name' => $definition['name'],
                'kind' => $definition['kind']->value,
                'provider' => $definition['provider'],
                'gl_account_id' => $glAccountId,
                'is_active' => true,
                'is_default' => $definition['isDefault'],
                'sort_order' => $definition['sortOrder'],
                'is_demo' => false,
            ]);
        }
    }

    /**
     * Seeded at statutory rates, all on the net. Whether a shop charges tax at
     * all is business_settings.tax_enabled, which starts off: most small shops
     * are not VAT-registered. A shop that had already set a single rate keeps
     * it as VAT, and the levies arrive switched off.
     */
    private function seedTaxComponents(): void
    {
        if (DB::table('tax_components')->exists()) {
            return;
        }

        $existingRate = (int) DB::table('business_settings')->where('id', 1)->value('tax_rate_bp');
        $preserving = $existingRate > 0;

        $definitions = [
            ['code' => 'NHIL', 'name' => 'NHIL', 'rate_bp' => 250, 'account' => ChartOfAccounts::NHIL_PAYABLE, 'sort_order' => 10],
            ['code' => 'GETFUND', 'name' => 'GETFund', 'rate_bp' => 250, 'account' => ChartOfAccounts::GETFUND_PAYABLE, 'sort_order' => 20],
            ['code' => 'VAT', 'name' => 'VAT', 'rate_bp' => 1_500, 'account' => ChartOfAccounts::TAX_PAYABLE, 'sort_order' => 30],
        ];

        foreach ($definitions as $definition) {
            $isVat = $definition['code'] === 'VAT';
            DB::table('tax_components')->insert([
                'code' => $definition['code'],
                'name' => $definition['name'],
                'rate_bp' => $preserving && $isVat ? $existingRate : $definition['rate_bp'],
                'is_recoverable' => true,
                'gl_account_id' => $this->accountId($definition['account']),
                'sort_order' => $definition['sort_order'],
                'is_active' => $preserving ? $isVat : true,
            ]);
        }

        if ($preserving) {
            DB::table('audit_logs')->insert([
                'action' => AuditAction::Create->value,
                'entity_type' => 'system',
                'entity_id' => 'tax-components',
                'summary' => 'Kept the existing tax rate on upgrade; NHIL and GETFund added but switched off',
                'metadata' => json_encode(['existingRateBp' => $existingRate]),
            ]);
        }
    }

    private function upsertAccount(string $code, string $name, AccountType $type, string $description, int $sortOrder, ?int $parentId = null): int
    {
        $existing = DB::table('accounts')->where('code', $code)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('accounts')->insertGetId([
            'code' => $code,
            'name' => $name,
            'type' => $type->value,
            'normal_balance' => ChartOfAccounts::normalBalanceFor($type)->value,
            'description' => $description,
            'sort_order' => $sortOrder,
            'parent_id' => $parentId,
            'is_system' => true,
            'is_active' => true,
        ]);
    }

    private function accountId(string $code): int
    {
        $id = DB::table('accounts')->where('code', $code)->value('id');
        if ($id === null) {
            throw new RuntimeException("Account {$code} is missing; the chart of accounts seed is inconsistent.");
        }

        return (int) $id;
    }
}
