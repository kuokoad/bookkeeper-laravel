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

require_once __DIR__.'/run.php';

define('IMPORT_DB', runDatabase('nunabooks_import'));

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

afterEach(fn () => dropRunDatabases(IMPORT_DB));

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

it('reconciles straight after the import when the old app kept its database in WAL mode, as the real one does', function () {
    $copy = oldCopy('PRAGMA journal_mode = WAL');
    expect(glob($copy.'-*'))->toBe([]);

    $import = artisan('nuna:import-sqlite', $copy);
    expect($import->successful())->toBeTrue($import->output())
        // Reading must leave nothing beside the copy: the next read refuses a -wal or -shm.
        ->and(glob($copy.'-*'))->toBe([]);

    $reconcile = artisan('nuna:reconcile', $copy);
    expect($reconcile->successful())->toBeTrue($reconcile->output())
        ->and($reconcile->output())->toContain('Zero differences.')
        ->and(glob($copy.'-*'))->toBe([]);
});

it('leaves nothing beside a WAL-mode copy when the import is refused, so the next try is not turned away for a -wal', function () {
    $copy = oldCopy('PRAGMA journal_mode = WAL');
    artisan('nuna:import-sqlite', $copy);

    $again = artisan('nuna:import-sqlite', $copy);
    expect($again->successful())->toBeFalse()
        ->and($again->output())->toContain('The target database is not empty')
        ->and(glob($copy.'-*'))->toBe([]);
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

it('reconcile ignores a backup taken after the import, but not any other new audit row', function () {
    $copy = oldCopy();
    expect(artisan('nuna:import-sqlite', $copy)->successful())->toBeTrue();
    $audit = DB::connection('import')->table('audit_logs');
    $audit->insert(['action' => 'CREATE', 'entity_type' => 'backup', 'summary' => 'Backup taken by hand (1 journal entries, verified)']);

    $clean = artisan('nuna:reconcile', $copy);
    expect($clean->successful())->toBeTrue($clean->output())
        ->and($clean->output())->toContain('Checked 1 backup audit rows added after the import, not compared')
        ->and($clean->output())->toContain('Zero differences.');

    $id = $audit->insertGetId(['action' => 'VOID', 'entity_type' => 'sale', 'summary' => 'Sale voided']);
    $dirty = artisan('nuna:reconcile', $copy);
    expect($dirty->successful())->toBeFalse()
        ->and($dirty->output())->toContain("audit_logs id={$id}: here, but not in the old database")
        ->and($dirty->output())->toContain('1 difference(s)');
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

it('reconcile v2 compares every report through the new services and finds zero differences', function () {
    $copy = oldCopy();
    expect(artisan('nuna:import-sqlite', $copy)->successful())->toBeTrue();

    $reconcile = artisan('nuna:reconcile', $copy);
    expect($reconcile->successful())->toBeTrue($reconcile->output())
        ->and($reconcile->output())->toContain('financial years, each with its P&L, tax return, cash flow, sales by day, by product (and per product, SQL against line by line) and by category, and purchases by day')
        ->and($reconcile->output())->toContain('each with its trial balance, balance sheet and ageing')
        ->and($reconcile->output())->toMatch('/Checked [1-9]\d* report figures/')
        ->and($reconcile->output())->toContain('Zero differences.');

    // As at a date of the user's choosing too.
    $asAt = artisan('nuna:reconcile', $copy, '--as-at=2026-09-30');
    expect($asAt->successful())->toBeTrue($asAt->output())
        ->and($asAt->output())->toContain('Zero differences.');
});

it('reconcile v2 reports a report figure that moved', function () {
    $copy = oldCopy();
    expect(artisan('nuna:import-sqlite', $copy)->successful())->toBeTrue();
    // Credit sales made due long ago: the ageing moves buckets, though no journal line moved.
    DB::connection('import')->table('sales')->whereNotNull('customer_id')->update(['due_date' => '2026-01-01']);
    // A taxable value moved: the tax return's box disagrees. The fixture carries no tax, and the box takes
    // only taxed sales (fifth audit, finding 8), so the first sale gains a tax line here on the new side only.
    $first = (int) DB::connection('import')->table('sales')->min('id');
    DB::connection('import')->table('sale_taxes')->insert(['sale_id' => $first, 'code' => 'VAT', 'name' => 'VAT', 'rate_bp' => 1_500, 'basis' => 'NET', 'amount_minor' => 0, 'created_at' => '2026-01-01 00:00:00.000']);
    // A pesewa more into a till: the cash flow (per source and per account) and the balance sheet's cash line disagree.
    DB::connection('import')->statement('UPDATE journal_lines SET debit_minor = debit_minor + 1 WHERE payment_account_id IS NOT NULL AND debit_minor > 0 ORDER BY id LIMIT 1');

    // A sale line's cost moved by a pesewa: sales by product and by category disagree.
    DB::connection('import')->statement('UPDATE sale_items SET total_cost_minor = total_cost_minor + 1 ORDER BY id LIMIT 1');
    // A delivery's total moved: purchases by day (total and net) disagree.
    DB::connection('import')->statement("UPDATE purchases SET subtotal_minor = subtotal_minor + 1, total_minor = total_minor + 1 WHERE kind = 'PURCHASE' ORDER BY id LIMIT 1");

    $output = artisan('nuna:reconcile', $copy)->output();
    expect($output)->toMatch('/receivables ageing at \S+ \d+ \w+: old (-?\d+|NULL), new/')
        ->and($output)->toMatch('/sales by product \S+ product \d+ cost: old -?\d+, new -?\d+/')
        ->and($output)->toMatch('/sales by category \S+ category \w+ cost: old -?\d+, new -?\d+/')
        ->and($output)->toMatch('/purchases by day \S+ \S+ net: old \d+, new \d+/')
        ->and($output)->toMatch('/tax return \S+ taxable sales: old \d+, new \d+/')
        ->and($output)->toMatch('/cash flow \S+ [A-Z_]+ in: old \d+, new \d+/')
        ->and($output)->toMatch('/cash flow \S+ account \d+ in: old \d+, new \d+/')
        ->and($output)->toMatch('/balance sheet at \S+ cash \d+: old -?\d+, new -?\d+/');
});
