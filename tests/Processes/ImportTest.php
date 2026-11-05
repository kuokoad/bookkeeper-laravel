<?php

declare(strict_types=1);

/*
| The importer and reconcile, end to end through artisan, on a database made
| by the OLD app's own migrations and demo seed (tests/Fixtures/old-demo.db).
| Each test works on a copy, in its own MySQL database, because the import
| commits.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\SupplierService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

const IMPORT_DB = 'nunabooks_import';

function importEnv(): array
{
    return ['DB_DATABASE' => IMPORT_DB, 'APP_ENV' => 'testing'];
}

function artisan(string ...$arguments): ProcessResult
{
    return Process::env(importEnv())->path(base_path())->timeout(300)->run([PHP_BINARY, 'artisan', ...$arguments]);
}

/** A fresh copy of the old demo database, optionally altered with SQL first. */
function oldCopy(string ...$statements): string
{
    $path = tempnam(sys_get_temp_dir(), 'old-demo-').'.db';
    copy(base_path('tests/Fixtures/old-demo.db'), $path);
    if ($statements !== []) {
        $pdo = new PDO('sqlite:'.$path);
        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
        $pdo = null;
    }

    return $path;
}

beforeEach(function () {
    DB::statement('CREATE DATABASE IF NOT EXISTS `'.IMPORT_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin');
    $fresh = artisan('migrate:fresh', '--force');
    expect($fresh->successful())->toBeTrue($fresh->errorOutput().$fresh->output());
    config(['database.connections.import' => array_merge(config('database.connections.mysql'), ['database' => IMPORT_DB])]);
});

it('imports the old database unchanged, and reconcile finds zero differences', function () {
    $copy = oldCopy();
    $import = artisan('nuna:import-sqlite', $copy);
    expect($import->successful())->toBeTrue($import->output());

    $reconcile = artisan('nuna:reconcile', $copy);
    expect($reconcile->successful())->toBeTrue($reconcile->output())
        ->and($reconcile->output())->toContain('Zero differences.');

    // Spot checks, written out by hand rather than with the importer's own formula.
    $newSale = DB::connection('import')->table('sales')->orderBy('id')->first();
    expect($newSale->receipt_no)->toBe('RCP-00001')
        ->and($newSale->total_minor)->toBe(1_600_000)
        // 1793897230798 ms in the old file.
        ->and($newSale->occurred_at)->toBe('2026-11-05 16:47:10.798')
        ->and(DB::connection('import')->table('users')->where('username', 'owner')->value('password_hash'))->toStartWith('scrypt$32768$8$1$');
});

it('carries the logo across byte for byte, and reconcile compares it', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $copy = oldCopy("UPDATE business_settings SET logo_data = X'".bin2hex($png)."', logo_mime = 'image/png', logo_width = 1, logo_height = 1 WHERE id = 1");

    expect(artisan('nuna:import-sqlite', $copy)->successful())->toBeTrue()
        ->and(DB::connection('import')->table('business_settings')->value('logo_data'))->toBe($png);

    // One byte changed in MySQL must show up.
    DB::connection('import')->table('business_settings')->update(['logo_data' => substr($png, 0, -1).'!']);
    $reconcile = artisan('nuna:reconcile', $copy);
    expect($reconcile->successful())->toBeFalse()
        ->and($reconcile->output())->toContain('business_settings id=1 logo_data');
});

it('reconcile catches a changed name, a moved timestamp and a missing row', function () {
    $copy = oldCopy();
    expect(artisan('nuna:import-sqlite', $copy)->successful())->toBeTrue();
    $db = DB::connection('import');
    $db->table('customers')->where('id', 1)->update(['name' => $db->table('customers')->where('id', 1)->value('name').'.']);
    $db->statement('UPDATE sales SET occurred_at = occurred_at + INTERVAL 1 HOUR WHERE id = 2');
    $db->table('quotation_items')->where('id', 1)->delete();

    $output = artisan('nuna:reconcile', $copy)->output();
    expect($output)->toContain('customers id=1 name')
        ->and($output)->toContain('sales id=2 occurred_at')
        ->and($output)->toMatch('/quotation_items id=\d+ (line_no|quotation_id)|quotation_items id=1/');
});

it('refuses a target that already holds data, writing nothing', function () {
    $copy = oldCopy();
    expect(artisan('nuna:import-sqlite', $copy)->successful())->toBeTrue();

    $again = artisan('nuna:import-sqlite', $copy);
    expect($again->successful())->toBeFalse()
        ->and($again->output())->toContain('The target database is not empty');
});

it('commits nothing, and names the row, when MySQL refuses one', function () {
    // A carton with no size: the old CHECK let it through (README differences).
    $copy = oldCopy("UPDATE products SET alt_unit = 'box', alt_factor_milli = NULL, alt_cost_price_minor = NULL, alt_selling_price_minor = NULL WHERE id = 1");
    $import = artisan('nuna:import-sqlite', $copy);

    expect($import->successful())->toBeFalse()
        ->and($import->output())->toContain('products #1')
        ->and($import->output())->toContain('ck_products_alt_unit')
        ->and(DB::connection('import')->table('journal_entries')->count())->toBe(0)
        ->and(DB::connection('import')->table('units')->pluck('symbol')->all())->toBe(['pcs']);
});

it('finds a link to a row that does not exist', function () {
    $copy = oldCopy('PRAGMA foreign_keys = OFF', 'UPDATE sales SET customer_id = 9999 WHERE id = 1');
    $import = artisan('nuna:import-sqlite', $copy);

    expect($import->successful())->toBeFalse()
        ->and($import->output())->toContain('sales.customer_id points at customers rows that do not exist: 9999');
});

it('reconcile reports a figure that moved, to the pesewa', function () {
    $copy = oldCopy();
    expect(artisan('nuna:import-sqlite', $copy)->successful())->toBeTrue();
    DB::connection('import')->table('journal_lines')->where('id', 1)->increment('debit_minor', 1);
    DB::connection('import')->table('journal_lines')->where('id', 2)->increment('credit_minor', 1);

    $reconcile = artisan('nuna:reconcile', $copy);
    expect($reconcile->successful())->toBeFalse()
        ->and($reconcile->output())->toContain('difference(s)')
        ->and($reconcile->output())->toMatch('/trial balance \d+ d: old \d+, new \d+/');
});

it('leaves an imported shop whose stock the inventory service proves, product by product', function () {
    $import = artisan('nuna:import-sqlite', oldCopy());
    expect($import->successful())->toBeTrue($import->output());

    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('import');
    try {
        $replayed = InventoryService::verifyAllStock();
        $cheap = InventoryService::verifyStockAgainstLedger();
        $coverage = InventoryService::verifyBatchCoverage();
        $value = InventoryService::getInventoryValue();
        $fromLedger = InventoryService::getInventoryValueFromLedger();
    } finally {
        DB::setDefaultConnection($default);
    }

    expect($replayed)->not->toBe([])
        ->and(array_filter($replayed, fn (array $row): bool => ! $row['ok']))->toBe([])
        ->and(array_filter($cheap, fn (array $row): bool => ! $row['ok']))->toBe([])
        ->and(array_filter($coverage, fn (array $row): bool => ! $row['ok']))->toBe([])
        ->and($value)->toBe($fromLedger);
});

it('leaves an imported shop whose receivables the sale and customer services agree on, customer by customer', function () {
    $import = artisan('nuna:import-sqlite', oldCopy());
    expect($import->successful())->toBeTrue($import->output());

    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('import');
    try {
        $balances = CustomerService::getAllCustomerBalances();
        $total = CustomerService::getTotalReceivables();
        $outstanding = SaleService::getOutstandingBySale();
        $owners = DB::table('sales')->whereIn('id', array_keys($outstanding))->whereNotNull('customer_id')->pluck('customer_id', 'id')->all();
        $control = (int) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', ChartOfAccounts::ACCOUNTS_RECEIVABLE)->selectRaw('SUM(debit_minor) - SUM(credit_minor) AS b')->value('b');
    } finally {
        DB::setDefaultConnection($default);
    }

    // The demo shop has no unallocated or advance payments, so each customer's
    // open invoices add up to exactly what the ledger says they owe.
    $byCustomer = [];
    foreach ($owners as $saleId => $customerId) {
        $byCustomer[(int) $customerId] = ($byCustomer[(int) $customerId] ?? 0) + $outstanding[$saleId];
    }
    expect($balances)->not->toBe([])
        ->and($total)->toBe($control)
        ->and(array_filter($byCustomer, fn (int $owed): bool => $owed !== 0))->toEqual($balances);
});

it('leaves an imported shop whose payables the purchase and supplier services agree on, supplier by supplier', function () {
    $import = artisan('nuna:import-sqlite', oldCopy());
    expect($import->successful())->toBeTrue($import->output());

    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('import');
    try {
        $balances = SupplierService::getAllSupplierBalances();
        $total = SupplierService::getTotalPayables();
        $outstanding = PurchaseService::getOutstandingByPurchase();
        $owners = DB::table('purchases')->whereIn('id', array_keys($outstanding))->whereNotNull('supplier_id')->pluck('supplier_id', 'id')->all();
        $control = (int) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', ChartOfAccounts::ACCOUNTS_PAYABLE)->selectRaw('COALESCE(SUM(credit_minor) - SUM(debit_minor), 0) AS b')->value('b');
    } finally {
        DB::setDefaultConnection($default);
    }

    // Each supplier's open deliveries add up to exactly what the ledger says the shop owes them.
    $bySupplier = [];
    foreach ($owners as $purchaseId => $supplierId) {
        $bySupplier[(int) $supplierId] = ($bySupplier[(int) $supplierId] ?? 0) + $outstanding[$purchaseId];
    }
    expect($total)->toBe($control)
        ->and(array_filter($bySupplier, fn (int $owed): bool => $owed !== 0))->toEqual(array_filter($balances, fn (int $owed): bool => $owed !== 0));
});
