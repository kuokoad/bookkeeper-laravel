<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Database\OpeningBatches;
use App\Database\Tx;
use App\Domain\BusinessDate;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Enums\AdjustmentReason;
use App\Enums\AuditAction;
use App\Enums\BusinessType;
use App\Enums\Feature;
use App\Services\Actor;
use App\Services\AuditService;
use App\Services\CashbookService;
use App\Services\CatalogService;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\PaymentAccountService;
use App\Services\PurchaseService;
use App\Services\QuotationService;
use App\Services\ReconciliationService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use App\Services\SupplierPaymentService;
use App\Services\SupplierService;
use App\Services\UnitsService;
use App\Support\Clock;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Development-only demo data: a Ghanaian building materials yard. A port of
 * the old src/db/seed/demo*.ts. Everything is recorded through the real
 * services, so the demo exercises stock, costing, payables, receivables and
 * the journal exactly as production does; nothing writes a total or a
 * balance directly. Every row is marked is_demo.
 *
 * The credentials are published because they must never exist on a real
 * shop's machine. Real sign-in arrives in Phase 8; until then the hashes are
 * Laravel's (Hash::make).
 */
class DemoSeeder extends Seeder
{
    /** What CoreSeeder creates the settings row with: nobody has named the shop. */
    private const DEFAULT_BUSINESS_NAME = 'My Shop';

    /** The demo shop's own name, safe to write over because a previous seed set it. */
    private const DEMO_BUSINESS_NAME = 'Structural Supplies';

    public const DEMO_OWNER = ['username' => 'owner', 'displayName' => 'Demo Owner', 'password' => 'demo-owner-2026'];

    public const DEMO_STAFF = ['username' => 'ama', 'displayName' => 'Ama (Demo Staff)', 'password' => 'demo-staff-2026', 'pin' => '8351'];

    /** The old defaultStaffPermissions(). */
    private const STAFF_PERMISSIONS = [
        'sales' => [true, true, false, false],
        'products' => [true, false, false, false],
        'inventory' => [true, false, false, false],
        'customers' => [true, true, false, false],
    ];

    private const UNITS = [
        ['symbol' => 'bag', 'name' => 'Bag', 'decimalPlaces' => 0],
        ['symbol' => 'load', 'name' => 'Load', 'decimalPlaces' => 0],
        ['symbol' => 'length', 'name' => 'Length', 'decimalPlaces' => 0],
        ['symbol' => 'roll', 'name' => 'Roll', 'decimalPlaces' => 0],
        ['symbol' => 'sheet', 'name' => 'Sheet', 'decimalPlaces' => 0],
        ['symbol' => 'kg', 'name' => 'Kilogram', 'decimalPlaces' => 3],
        ['symbol' => 'trip', 'name' => 'Trip', 'decimalPlaces' => 0],
        ['symbol' => 'box', 'name' => 'Box', 'decimalPlaces' => 0],
    ];

    private const CATEGORIES = ['Cement', 'Steel', 'Plumbing', 'Roofing', 'Fixings', 'Services'];

    /** name, category, sku, unit, cost, price, minStock, opening qty, opening value (cedis); alt; service. */
    private const PRODUCTS = [
        ['Cement 50kg', 'Cement', 'CEM50', 'bag', 86, 96, 100, 400, 34_400],
        ['Sand (tipper load)', 'Cement', 'SAND-TIP', 'load', 900, 1_100, 1, 4, 3_600],
        ['Chippings 3/4in (tipper load)', 'Cement', 'CHIP34', 'load', 1_150, 1_380, 1, 3, 3_450],
        ['Iron rod 12mm', 'Steel', 'ROD12', 'length', 96, 112, 50, 220, 21_120],
        ['Iron rod 16mm', 'Steel', 'ROD16', 'length', 172, 198, 30, 120, 20_640],
        ['Binding wire', 'Steel', 'BWIRE', 'roll', 88, 108, 10, 40, 3_520],
        ['PVC pipe 4in', 'Plumbing', 'PVC4', 'length', 118, 142, 20, 90, 10_620],
        ['PVC pipe 2in', 'Plumbing', 'PVC2', 'length', 54, 68, 30, 140, 7_560],
        ['PVC elbow 4in', 'Plumbing', 'ELB4', 'pcs', 16, 24, 40, 200, 3_200, ['unit' => 'box', 'factor' => 10, 'price' => 220]],
        ['Roofing sheet aluzinc 3m', 'Roofing', 'ROOF3M', 'sheet', 182, 214, 40, 160, 29_120],
        ['Roofing nails 3in', 'Fixings', 'NAIL3', 'kg', 14, 19, 25, 120, 1_680],
        // Sold, never stocked: transport to site is priced as a non-stock product.
        ['Cartage to site', 'Services', 'CARTAGE', 'trip', 0, 350, 0, 0, 0, null, true],
    ];

    /** @var list<array{businessDate: string, run: Closure}> */
    private array $steps = [];

    private bool $inDateOrder = true;

    public function run(): void
    {
        $this->seedDemo();
    }

    /**
     * The old seedDemo(db, now).
     *
     * $inDateOrder false reproduces the seed as it stood when
     * tests/Fixtures/old-demo.db was made (old commit c243788): opening stock
     * dated today and every step run as it is registered, module by module.
     * The old app's ISSUE-001 fix (1343655) then dated opening stock 90 days
     * back and ran the dated steps in date order, which is the default here.
     * Only the fixture comparison test passes false.
     */
    public function seedDemo(?Carbon $now = null, bool $inDateOrder = true): void
    {
        $now ??= Clock::now();
        $this->inDateOrder = $inDateOrder;
        $this->steps = [];

        $this->seedUser(self::DEMO_OWNER, 'OWNER', $now);
        $this->seedUser(self::DEMO_STAFF, 'STAFF', $now);

        $owner = DB::table('users')->where('username', self::DEMO_OWNER['username'])->first();
        if ($owner !== null) {
            $actor = new Actor((int) $owner->id, $owner->username);
            $today = Clock::dateOf($now);

            // Opening stock is dated before the first trade, the way a shop starts.
            $this->seedCatalog($actor, $inDateOrder ? BusinessDate::addDays($today, -90) : $today);

            // Purchases, sales and the converted quote run in date order, so
            // each product's stock ledger reads in sequence.
            $this->seedPurchases($actor, $today);
            $this->seedSales($actor, $today);
            // After the sales are registered: converting a quote makes a sale,
            // and the sales seed refuses to run once any sale exists.
            $this->seedQuotations($actor, $today);
            // Stable: same-day steps keep the order they were registered in.
            usort($this->steps, fn (array $a, array $b): int => strcmp($a['businessDate'], $b['businessDate']));
            foreach ($this->steps as $step) {
                ($step['run'])();
            }

            // After trading, because its counts compare against what the tills hold.
            $this->seedCashbook($actor, $today);

            // Whatever is left on the shelf gets an undated opening batch.
            OpeningBatches::open($today);
        }

        Tx::run(function () use ($now): void {
            // The demo name and address only if nobody has chosen one.
            $current = DB::table('business_settings')->where('id', 1)->value('business_name');
            $named = $current !== null && $current !== self::DEFAULT_BUSINESS_NAME && $current !== self::DEMO_BUSINESS_NAME;
            $features = Feature::defaults(BusinessType::BuildingMaterials);

            DB::table('business_settings')->where('id', 1)->update([
                ...($named ? [] : [
                    'business_name' => self::DEMO_BUSINESS_NAME,
                    'tagline' => 'Cement, steel, roofing and plumbing',
                    'address' => 'Plot 12, Spintex Road, Accra',
                    'phone' => '+233 24 000 0000',
                    // A yard demo that came up as general retail would hide the
                    // Quotations menu the seeded quotes are sitting in.
                    'business_type' => BusinessType::BuildingMaterials->value,
                    'feature_expiry_batches' => $features[Feature::ExpiryBatches->value],
                    'feature_quotations' => $features[Feature::Quotations->value],
                ]),
                'has_demo_data' => true,
                'setup_completed_at' => Clock::stamp($now),
                'updated_at' => Clock::stamp($now),
            ]);

            AuditService::write(AuditAction::SeedDemo, 'system', 'Demo data seeded — not for production use', 'demo-seed', at: $now);
        });
    }

    /**
     * Records the trading tables hold that the demo seed did not write: the
     * old countRealRecords, counted from is_demo like the purge.
     */
    public static function countRealRecords(): int
    {
        $total = 0;
        foreach (['sales', 'purchases', 'stock_adjustments', 'journal_entries'] as $table) {
            $total += DB::table($table)->where('is_demo', false)->count();
        }

        return $total;
    }

    /** Queue a dated step; in the fixture's old order, run it now. */
    private function at(string $businessDate, Closure $run): void
    {
        if ($this->inDateOrder) {
            $this->steps[] = ['businessDate' => $businessDate, 'run' => $run];
        } else {
            $run();
        }
    }

    private static function m(float $cedis): int
    {
        return Money::fromMajor($cedis);
    }

    private static function u(float $units): int
    {
        return Quantity::fromUnits($units);
    }

    private static function daysBefore(string $date, int $days): string
    {
        return BusinessDate::addDays($date, -$days);
    }

    /** The old createUser for first-run setup (no actor): the user, a staff member's permissions, the audit row. */
    private function seedUser(array $demo, string $role, Carbon $now): void
    {
        if (DB::table('users')->where('username', $demo['username'])->exists()) {
            return;
        }

        Tx::run(function () use ($demo, $role, $now): void {
            $at = Clock::stamp($now);
            $id = (int) DB::table('users')->insertGetId([
                'username' => $demo['username'],
                'display_name' => $demo['displayName'],
                'role' => $role,
                'password_hash' => Hash::make($demo['password']),
                'pin_hash' => isset($demo['pin']) ? Hash::make($demo['pin']) : null,
                'is_active' => true,
                'must_change_password' => false,
                'created_by' => null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            if ($role === 'STAFF') {
                foreach (self::STAFF_PERMISSIONS as $module => [$view, $create, $edit, $void]) {
                    DB::table('user_permissions')->insert([
                        'user_id' => $id, 'module' => $module,
                        'can_view' => $view, 'can_create' => $create, 'can_edit' => $edit, 'can_void' => $void,
                        'created_at' => $at, 'updated_at' => $at,
                    ]);
                }
            }
            AuditService::write(AuditAction::Create, 'user', 'Created '.strtolower($role)." account \"{$demo['username']}\"", $id, null, [
                'role' => $role,
                'displayName' => $demo['displayName'],
            ], $now);
        });
    }

    /** @return array<string, int> */
    private static function productIds(): array
    {
        return DB::table('products')->pluck('id', 'name')->map(fn ($id): int => (int) $id)->all();
    }

    private static function need(array $ids, string $name, string $what = 'product'): int
    {
        return $ids[$name] ?? throw new RuntimeException("Demo seed expected {$what} \"{$name}\"");
    }

    /** @return array{CASH: object, MOBILE_MONEY: object, BANK: object}|null the first account of each kind */
    private static function tills(): ?array
    {
        $found = [];
        foreach (DB::table('payment_accounts')->orderBy('id')->get() as $account) {
            $found[$account->kind] ??= $account;
        }

        return isset($found['CASH'], $found['MOBILE_MONEY'], $found['BANK']) ? $found : null;
    }

    private function seedCatalog(Actor $actor, string $businessDate): void
    {
        // Idempotent: if any product exists, the demo catalogue is not added.
        if (DB::table('products')->exists()) {
            return;
        }

        $categoryIds = [];
        foreach (self::CATEGORIES as $name) {
            $categoryIds[$name] = (int) (DB::table('categories')->where('name', $name)->value('id') ?? CatalogService::createCategory(['name' => $name], $actor));
        }

        foreach (self::UNITS as $unit) {
            $exists = array_filter(UnitsService::listUnits(), fn (object $u): bool => mb_strtolower($u->symbol) === $unit['symbol']);
            if ($exists === []) {
                UnitsService::createUnit($unit, $actor);
            }
        }

        $openingItems = [];
        foreach (self::PRODUCTS as $p) {
            [$name, $category, $sku, $unit, $cost, $price, $minStock, $openingQty, $openingValue] = $p;
            $alt = $p[9] ?? null;
            $service = $p[10] ?? false;

            $productId = CatalogService::createProduct([
                'name' => $name,
                'sku' => $sku,
                'categoryId' => $categoryIds[$category] ?? null,
                'unit' => $unit,
                ...($alt !== null ? ['altUnit' => $alt['unit'], 'altFactor' => self::u($alt['factor']), 'altSellingPrice' => self::m($alt['price'])] : []),
                'costPrice' => self::m($cost),
                'sellingPrice' => self::m($price),
                'minStock' => self::u($minStock),
                'trackInventory' => ! $service,
            ], $actor);
            DB::table('products')->where('id', $productId)->update(['is_demo' => true]);

            // Nothing to open a service with: no quantity ever sat in the yard.
            if (! $service) {
                $openingItems[] = ['productId' => $productId, 'direction' => 'IN', 'qty' => self::u($openingQty), 'totalCost' => self::m($openingValue)];
            }
        }
        DB::table('categories')->update(['is_demo' => true]);

        // One opening-stock document for the whole shop: Dr Inventory, Cr Opening Balance Equity.
        StockAdjustmentService::createStockAdjustment([
            'businessDate' => $businessDate,
            'reason' => AdjustmentReason::OpeningStock,
            'note' => 'Demo opening stock',
            'items' => $openingItems,
            'isDemo' => true,
        ], $actor);
    }

    private function seedPurchases(Actor $actor, string $today): void
    {
        if (DB::table('purchases')->exists()) {
            return;
        }
        $ids = self::productIds();
        $tills = self::tills();
        if ($tills === null) {
            return;
        }
        $bank = (int) $tills['BANK']->id;
        $purchase = fn (array $input) => $this->at($input['businessDate'], fn () => PurchaseService::createPurchase($input, $actor));

        $ghacem = SupplierService::createSupplier(['name' => 'Ghacem Depot, Tema', 'contactPerson' => 'Mr Adjei', 'phone' => '030 222 1100', 'address' => 'Heavy Industrial Area, Tema'], $actor);
        $steelWorks = SupplierService::createSupplier(['name' => 'Tema Steel Works', 'contactPerson' => 'Hajia Fati', 'phone' => '024 555 6677'], $actor);
        $pipesAndSheets = SupplierService::createSupplier(['name' => 'Amasaman Pipes & Sheets', 'contactPerson' => 'Selorm', 'phone' => '027 909 1234'], $actor);
        DB::table('suppliers')->update(['is_demo' => true]);

        // A truckload of cement, paid on delivery.
        $purchase([
            'supplierId' => $ghacem,
            'businessDate' => self::daysBefore($today, 74),
            'invoiceNo' => 'GH-4471',
            'items' => [['productId' => self::need($ids, 'Cement 50kg'), 'qty' => self::u(600), 'unitCost' => self::m(84)]],
            'tenders' => [['paymentAccountId' => $bank, 'amount' => self::m(50_400)]],
            'isDemo' => true,
        ]);

        // Steel, part paid: leaves a payable, and re-averages every rod in the yard.
        $purchase([
            'supplierId' => $steelWorks,
            'businessDate' => self::daysBefore($today, 46),
            'invoiceNo' => 'TSW-90233',
            'items' => [
                ['productId' => self::need($ids, 'Iron rod 12mm'), 'qty' => self::u(200), 'unitCost' => self::m(99)],
                ['productId' => self::need($ids, 'Iron rod 16mm'), 'qty' => self::u(80), 'unitCost' => self::m(178)],
            ],
            'tenders' => [['paymentAccountId' => $bank, 'amount' => self::m(20_000)]],
            'note' => 'Balance due end of month',
            'isDemo' => true,
        ]);

        // Entirely on credit.
        $purchase([
            'supplierId' => $pipesAndSheets,
            'businessDate' => self::daysBefore($today, 17),
            'invoiceNo' => 'APS-1188',
            'items' => [
                ['productId' => self::need($ids, 'PVC pipe 4in'), 'qty' => self::u(60), 'unitCost' => self::m(121)],
                ['productId' => self::need($ids, 'PVC pipe 2in'), 'qty' => self::u(80), 'unitCost' => self::m(55)],
                ['productId' => self::need($ids, 'Roofing sheet aluzinc 3m'), 'qty' => self::u(90), 'unitCost' => self::m(186)],
            ],
            'invoiceDiscount' => self::m(250),
            'tenders' => [],
            'isDemo' => true,
        ]);

        // A second cement load after the price moved: bags bought at two prices.
        $purchase([
            'supplierId' => $ghacem,
            'businessDate' => self::daysBefore($today, 9),
            'invoiceNo' => 'GH-4620',
            'items' => [['productId' => self::need($ids, 'Cement 50kg'), 'qty' => self::u(600), 'unitCost' => self::m(89)]],
            'tenders' => [['paymentAccountId' => $bank, 'amount' => self::m(30_000)]],
            'note' => 'Balance on account',
            'isDemo' => true,
        ]);

        // A part payment against what is owed.
        $payment = [
            'supplierId' => $steelWorks,
            'businessDate' => self::daysBefore($today, 20),
            'paymentAccountId' => (int) $tills['MOBILE_MONEY']->id,
            'amount' => self::m(6_000),
            'reference' => 'MM-8850119',
            'isDemo' => true,
        ];
        $this->at($payment['businessDate'], fn () => SupplierPaymentService::recordSupplierPayment($payment, $actor));

        $this->at($today, fn () => DB::table('purchases')->where('is_demo', false)->update(['is_demo' => true]));
    }

    private function seedSales(Actor $actor, string $today): void
    {
        if (DB::table('sales')->exists()) {
            return;
        }
        $ids = self::productIds();
        $tills = self::tills();
        if ($tills === null) {
            return;
        }
        [$cash, $momo, $bank] = [(int) $tills['CASH']->id, (int) $tills['MOBILE_MONEY']->id, (int) $tills['BANK']->id];
        $sale = fn (array $input) => $this->at($input['businessDate'], fn () => SaleService::createSale($input, $actor));
        $item = fn (string $name, float $qty, ?float $unitPrice = null): array => [
            'productId' => self::need($ids, $name),
            'qty' => self::u($qty),
            ...($unitPrice !== null ? ['unitPrice' => self::m($unitPrice)] : []),
        ];

        // Contractors on terms: what makes receivables and ageing worth looking at.
        $adom = CustomerService::createCustomer(['name' => 'Adom Construction Ltd', 'phone' => '024 111 2233', 'address' => 'Adenta, Accra', 'creditLimit' => self::m(60_000)], $actor);
        $mensah = CustomerService::createCustomer(['name' => 'Mensah & Sons Builders', 'phone' => '020 444 5566', 'creditLimit' => self::m(25_000)], $actor);
        $walkIn = CustomerService::createCustomer(['name' => 'Kofi Owusu', 'phone' => '027 777 8899', 'creditLimit' => null], $actor);
        DB::table('customers')->update(['is_demo' => true]);

        // Two months back: a big cash order off the yard.
        $sale(['businessDate' => self::daysBefore($today, 63), 'items' => [$item('Cement 50kg', 120), $item('Iron rod 12mm', 40)],
            'tenders' => [['paymentAccountId' => $cash, 'amount' => self::m(16_000)]], 'isDemo' => true]);

        // A contractor on terms: this puts something in receivables.
        $sale(['businessDate' => self::daysBefore($today, 55), 'customerId' => $adom, 'termsDays' => 30,
            'items' => [$item('Cement 50kg', 300), $item('Sand (tipper load)', 2), $item('Chippings 3/4in (tipper load)', 1), $item('Cartage to site', 2)],
            'tenders' => [['paymentAccountId' => $bank, 'amount' => self::m(10_000)]], 'note' => 'Adenta site, phase 1', 'isDemo' => true]);

        $sale(['businessDate' => self::daysBefore($today, 41), 'items' => [$item('Roofing sheet aluzinc 3m', 24), $item('Roofing nails 3in', 8)],
            'tenders' => [['paymentAccountId' => $momo, 'amount' => self::m(5_288)]], 'isDemo' => true]);

        // Split tender: part cash, part mobile money.
        $sale(['businessDate' => self::daysBefore($today, 28), 'customerId' => $mensah,
            'items' => [$item('PVC pipe 4in', 18), $item('PVC pipe 2in', 24), $item('PVC elbow 4in', 30)],
            'tenders' => [['paymentAccountId' => $cash, 'amount' => self::m(2_000)], ['paymentAccountId' => $momo, 'amount' => self::m(2_908)]], 'isDemo' => true]);

        // Part paid, so this one sits in the ageing.
        $sale(['businessDate' => self::daysBefore($today, 21), 'customerId' => $mensah, 'termsDays' => 30,
            'items' => [$item('Iron rod 16mm', 60), $item('Binding wire', 6)],
            'tenders' => [['paymentAccountId' => $bank, 'amount' => self::m(4_000)]], 'note' => 'Kasoa site', 'isDemo' => true]);

        // Nails are weighed: 2.5 kg, not a round number.
        $sale(['businessDate' => self::daysBefore($today, 12), 'customerId' => $walkIn,
            'items' => [$item('Cement 50kg', 15), $item('Roofing nails 3in', 2.5)],
            'tenders' => [['paymentAccountId' => $cash, 'amount' => self::m(1_487.5)]], 'isDemo' => true]);

        // Yesterday, at a negotiated price.
        $sale(['businessDate' => self::daysBefore($today, 1), 'customerId' => $adom, 'termsDays' => 30,
            'items' => [$item('Cement 50kg', 200, 93), $item('Cartage to site', 1)],
            'tenders' => [], 'allowPriceOverride' => true, 'note' => 'Adenta site, phase 2', 'isDemo' => true]);

        // Something taken off the account.
        $payment = ['customerId' => $adom, 'businessDate' => self::daysBefore($today, 30), 'paymentAccountId' => $bank,
            'amount' => self::m(20_000), 'reference' => 'Cheque 004412', 'isDemo' => true];
        $this->at($payment['businessDate'], fn () => CustomerPaymentService::recordCustomerPayment($payment, $actor));

        $this->at($today, fn () => DB::table('sales')->where('is_demo', false)->update(['is_demo' => true]));
    }

    /** One quotation in each state: live, quietly expired, won (converted), lost (cancelled). */
    private function seedQuotations(Actor $actor, string $today): void
    {
        if (DB::table('quotations')->exists()) {
            return;
        }
        $ids = self::productIds();
        $customers = DB::table('customers')->pluck('id', 'name')->map(fn ($id): int => (int) $id)->all();
        $tills = self::tills();
        if ($tills === null) {
            return;
        }
        $line = fn (string $name, float $qty, ?float $unitPrice = null): array => [
            'productId' => self::need($ids, $name),
            'qty' => self::u($qty),
            ...($unitPrice !== null ? ['unitPrice' => self::m($unitPrice)] : []),
        ];

        QuotationService::createQuotation([
            'businessDate' => self::daysBefore($today, 4),
            'validUntil' => BusinessDate::addDays($today, 26),
            'customerName' => 'Adom Construction Ltd',
            'customerId' => $customers['Adom Construction Ltd'] ?? null,
            'customerPhone' => '024 111 2233',
            'reference' => 'Adenta site, phase 3',
            'lines' => [$line('Cement 50kg', 250, 93), $line('Iron rod 12mm', 80, 108), $line('Binding wire', 10), $line('Cartage to site', 2)],
            'quoteDiscount' => self::m(500),
            'notes' => 'Price held for 30 days. Delivery within 48 hours of order.',
        ], $actor);

        // What the "Ran out" filter finds, and the only way to see the expiry override without waiting.
        QuotationService::createQuotation([
            'businessDate' => self::daysBefore($today, 52),
            'validUntil' => self::daysBefore($today, 22),
            'customerName' => 'Kwame Boateng',
            'customerPhone' => '020 333 4455',
            'reference' => 'Tema Community 25',
            'lines' => [$line('Roofing sheet aluzinc 3m', 60), $line('Roofing nails 3in', 15)],
            'notes' => 'Awaiting confirmation of roof measurements.',
        ], $actor);

        $won = QuotationService::createQuotation([
            'businessDate' => self::daysBefore($today, 34),
            'validUntil' => self::daysBefore($today, 4),
            'customerName' => 'Mensah & Sons Builders',
            'customerId' => $customers['Mensah & Sons Builders'] ?? null,
            'customerPhone' => '020 444 5566',
            'reference' => 'Kasoa site, plumbing',
            'lines' => [$line('PVC pipe 4in', 24, 138), $line('PVC pipe 2in', 30), $line('PVC elbow 4in', 40)],
        ], $actor);

        $conversion = [
            'businessDate' => self::daysBefore($today, 30),
            'customerId' => $customers['Mensah & Sons Builders'] ?? null,
            'tenders' => [['paymentAccountId' => (int) $tills['BANK']->id, 'amount' => $won['total']]],
            'isDemo' => true,
        ];
        $this->at($conversion['businessDate'], fn () => QuotationService::convertQuotation($won['quotationId'], $conversion, $actor));

        $lost = QuotationService::createQuotation([
            'businessDate' => self::daysBefore($today, 26),
            'validUntil' => BusinessDate::addDays($today, 4),
            'customerName' => 'Grace Ofori',
            'customerPhone' => '027 888 9900',
            'reference' => 'Oyarifa boundary wall',
            'lines' => [$line('Cement 50kg', 80), $line('Sand (tipper load)', 1)],
        ], $actor);
        QuotationService::cancelQuotation($lost['quotationId'], 'Customer bought from another yard', $actor);

        // The sale behind the converted quote is marked at its source (isDemo carried through).
        DB::table('quotations')->update(['is_demo' => true]);
    }

    /** Running costs, side income, the owner's starting float, and two till counts. */
    private function seedCashbook(Actor $actor, string $today): void
    {
        if (DB::table('expenses')->exists()) {
            return;
        }
        $tills = self::tills();
        if ($tills === null) {
            return;
        }
        [$cash, $momo, $bank] = [(int) $tills['CASH']->id, (int) $tills['MOBILE_MONEY']->id, (int) $tills['BANK']->id];
        $expenseCategories = array_column(PaymentAccountService::listExpenseCategories(), 'id', 'name');
        $incomeCategories = array_column(PaymentAccountService::listIncomeCategories(), 'id', 'name');

        $spend = fn (int $days, string $category, string $description, float $cedis, int $accountId, ?string $reference = null) => CashbookService::recordExpense([
            'businessDate' => self::daysBefore($today, $days),
            'categoryAccountId' => self::need($expenseCategories, $category, 'expense category'),
            'description' => $description,
            'amount' => self::m($cedis),
            'paymentAccountId' => $accountId,
            ...($reference !== null ? ['reference' => $reference] : []),
            'isDemo' => true,
        ], $actor);

        // The owner's starting float, dated before the first delivery (74 days
        // back, paid from the bank), or every account starts life overdrawn.
        $float = fn (int $accountId, float $cedis, string $what) => CashbookService::recordOwnerCapital([
            'businessDate' => self::daysBefore($today, 75),
            'paymentAccountId' => $accountId,
            'amount' => self::m($cedis),
            'description' => $what,
            'isDemo' => true,
        ], $actor);

        $float($cash, 8_000, 'Owner opening cash float');
        $float($momo, 2_000, 'Owner opening MoMo balance');
        $float($bank, 80_000, 'Owner opening bank balance');

        $spend(68, 'Rent', 'Yard rent for the month', 2_400, $bank);
        $spend(60, 'Transport', 'Tipper hire, Adenta delivery', 900, $cash);
        $spend(52, 'Staff Wages', 'Yard hands — monthly wages', 3_200, $cash);
        $spend(38, 'Rent', 'Yard rent for the month', 2_400, $bank);
        $spend(31, 'Electricity', 'ECG prepaid top-up', 380, $momo, 'MM-8811023');
        $spend(24, 'Transport', 'Forklift diesel', 640, $cash);
        $spend(19, 'Staff Wages', 'Yard hands — monthly wages', 3_200, $cash);
        $spend(11, 'MoMo Charges', 'Withdrawal charges', 42, $momo);
        $spend(7, 'Rent', 'Yard rent for the month', 2_400, $bank);
        $spend(4, 'Internet & Airtime', 'Airtime for the yard line', 60, $momo, 'MM-8839912');
        $spend(1, 'Transport', 'Tipper hire, Kasoa delivery', 750, $cash);

        CashbookService::recordIncome([
            'businessDate' => self::daysBefore($today, 33),
            'categoryAccountId' => self::need($incomeCategories, 'Commission', 'income category'),
            'description' => 'Weighbridge fee recovered',
            'amount' => self::m(150),
            'paymentAccountId' => $momo,
            'reference' => 'MM-8820447',
            'isDemo' => true,
        ], $actor);
        CashbookService::recordIncome([
            'businessDate' => self::daysBefore($today, 1),
            'categoryAccountId' => self::need($incomeCategories, 'Service Income', 'income category'),
            'description' => 'Offloading charged to a customer',
            'amount' => self::m(300),
            'paymentAccountId' => $cash,
            'isDemo' => true,
        ], $actor);

        DB::table('expenses')->where('is_demo', false)->update(['is_demo' => true]);
        DB::table('incomes')->where('is_demo', false)->update(['is_demo' => true]);

        // An end-of-day cash count a little short: recorded, explained and adjusted.
        $countDate = self::daysBefore($today, 1);
        $cashExpected = ReconciliationService::getReconciliationContext($cash, $countDate)['expected'];
        ReconciliationService::createReconciliation([
            'paymentAccountId' => $cash,
            'businessDate' => $countDate,
            'actual' => Money::subtract($cashExpected, 350),
            'explanation' => 'Short by GHS 3.50 — likely wrong change during the evening rush',
            'adjust' => true,
            'isDemo' => true,
        ], $actor);

        // A MoMo count that agreed exactly.
        ReconciliationService::createReconciliation([
            'paymentAccountId' => $momo,
            'businessDate' => $countDate,
            'actual' => ReconciliationService::getReconciliationContext($momo, $countDate)['expected'],
            'adjust' => true,
            'isDemo' => true,
        ], $actor);
    }
}
