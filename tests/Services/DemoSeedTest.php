<?php

declare(strict_types=1);

/*
| Ported from the old tests/db/demo-seed.test.ts: the demo seed runs, leaves
| books that balance, never overdraws an account, sells by weight, keeps each
| product's history in date order and in batches, and does not overwrite a
| shop name somebody chose. Plus a check the old suite could not make: the
| demo's figures against tests/Fixtures/old-demo.db, made by the OLD app's own
| migrate and demo seed at 2026-11-05T16:47:10.491Z.
|
| The fixture predates the old app's ISSUE-001 fix (1343655), which moved
| opening stock 90 days back and ran the dated steps in date order. Run the
| old way (DemoSeeder::seedDemo(..., inDateOrder: false)) the new demo must
| equal the fixture exactly: every account code and every product's (Q, V).
| Run the current way, the weighted average sees deliveries and sales in a
| different order, so Inventory (1200) and COGS (5000) and the value per
| product move between each other; everything else, their sum, and every
| quantity must still match.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\InventoryService;
use App\Services\Migration\OldDatabase;
use App\Services\PaymentAccountService;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Shop;

/** The instant the fixture was seeded: its SEED_DEMO audit row and both users carry it. */
const DEMO_FIXTURE_NOW = 1_793_897_230_491;

function demoSeed(?Carbon $now = null, bool $inDateOrder = true): void
{
    $now ??= Carbon::parse('2026-08-25T09:00:00Z');
    Carbon::setTestNow($now);
    try {
        (new DemoSeeder)->seedDemo($now, $inDateOrder);
    } finally {
        Carbon::setTestNow();
    }
}

function demoSettings(): object
{
    return DB::table('business_settings')->where('id', 1)->first();
}

/** @return array<string, array{int, int}> debits and credits per account code */
function demoTrialBalance(iterable $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $row = (array) $row;
        $out[$row['code']] = [(int) $row['d'], (int) $row['c']];
    }
    ksort($out);

    return $out;
}

/** @return array<string, array{int, int}> (Q, V) per product name */
function demoStock(iterable $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $row = (array) $row;
        $out[$row['name']] = [(int) $row['q'], (int) $row['v']];
    }
    ksort($out);

    return $out;
}

const DEMO_TB_SQL = 'SELECT a.code AS code, SUM(l.debit_minor) AS d, SUM(l.credit_minor) AS c
    FROM journal_lines l JOIN accounts a ON a.id = l.account_id GROUP BY a.code';
const DEMO_STOCK_SQL = 'SELECT name, qty_on_hand_milli AS q, stock_value_minor AS v FROM products';

function demoFixture(): PDO
{
    return OldDatabase::open(base_path('tests/Fixtures/old-demo.db'));
}

describe('the demo seed', function () {
    it('runs to completion', function () {
        demoSeed();

        // The flag is written last, so it only stands if everything before it did.
        expect((bool) demoSettings()->has_demo_data)->toBeTrue();
    });

    it('leaves a shop whose books balance', function () {
        demoSeed();
        expect(Shop::trialBalanced())->toBeTrue();
        Shop::assertBooksHealthy('demo');
    });

    // The demo once dated the owner's float a week back, after two months of
    // deliveries paid from the bank: a fresh install opened at GHS -7,288.
    it('never shows a money account below zero, on any day of its history', function () {
        demoSeed();

        $negative = [];
        foreach (PaymentAccountService::listPaymentAccounts() as $account) {
            for ($day = Carbon::parse('2026-05-01'); $day->lte(Carbon::parse('2026-08-25')); $day->addDay()) {
                $balance = PaymentAccountService::getPaymentAccountBalance($account['id'], $day->format('Y-m-d'));
                if ($balance < 0) {
                    $negative[] = "{$account['name']} {$day->format('Y-m-d')}: {$balance}";
                }
            }
        }
        expect($negative)->toBe([]);
    });

    it('sells something by weight, so a fractional quantity reaches the screens', function () {
        demoSeed();
        expect((int) DB::table('products')->where('name', 'Roofing nails 3in')->value('qty_on_hand_milli') % 1000)->not->toBe(0);
    });

    it('leaves every product agreeing with its own movement history', function () {
        demoSeed();
        foreach (DB::table('products')->pluck('id') as $id) {
            expect(InventoryService::verifyProductStock((int) $id)['ok'])->toBeTrue("product {$id}");
        }
    });

    // Regression: ISSUE-001, demo stock ledger read out of date order (old app, 2026-11-05).
    it('writes each product history in date order, opening stock first', function () {
        demoSeed();

        foreach (DB::table('products')->where('track_inventory', true)->pluck('id') as $id) {
            $oldestFirst = array_reverse(InventoryService::getStockLedger(['productId' => (int) $id]));
            $dates = array_column($oldestFirst, 'businessDate');
            $sorted = $dates;
            sort($sorted);
            expect($oldestFirst[0]['movementType'])->toBe('OPENING_STOCK', "product {$id}")
                ->and($dates)->toBe($sorted, "product {$id}");
        }
    });

    it('leaves every unit of stock owned by a batch', function () {
        demoSeed();

        $coverage = InventoryService::verifyBatchCoverage();
        expect($coverage)->not->toBeEmpty()
            ->and(array_filter($coverage, fn (array $row): bool => ! $row['ok']))->toBe([]);

        // Undated, like the migration's: no date here was entered by anybody.
        $batches = DB::table('product_batches')->get();
        expect($batches)->not->toBeEmpty()
            ->and($batches->every(fn (object $b): bool => $b->expiry_date === null))->toBeTrue()
            ->and($batches->every(fn (object $b): bool => (bool) $b->is_demo))->toBeTrue();
    });

    it('gives the demo users Laravel hashes of the published credentials, and the staff member the old staff rights', function () {
        demoSeed();

        $owner = DB::table('users')->where('username', 'owner')->first();
        $staff = DB::table('users')->where('username', 'ama')->first();
        expect(Hash::check('demo-owner-2026', $owner->password_hash))->toBeTrue()
            ->and($owner->role)->toBe('OWNER')
            ->and($owner->pin_hash)->toBeNull()
            ->and(Hash::check('demo-staff-2026', $staff->password_hash))->toBeTrue()
            ->and(Hash::check('8351', $staff->pin_hash))->toBeTrue()
            ->and(DB::table('user_permissions')->where('user_id', $staff->id)->orderBy('id')->pluck('module')->all())->toBe(['sales', 'products', 'inventory', 'customers']);
    });
});

describe('what the seed refuses to overwrite', function () {
    // This cost a real shop its name twice in one afternoon.
    it('leaves a shop name that somebody chose', function () {
        DB::table('business_settings')->where('id', 1)->update(['business_name' => 'Nuna Trading & Co.', 'tagline' => 'Where Quality meets Affordability']);

        demoSeed();

        $settings = demoSettings();
        expect($settings->business_name)->toBe('Nuna Trading & Co.')
            ->and($settings->tagline)->toBe('Where Quality meets Affordability')
            // The demo data itself still arrived.
            ->and((bool) $settings->has_demo_data)->toBeTrue();
    });

    it('still names an unnamed shop, so a fresh demo looks like a shop', function () {
        demoSeed();

        $settings = demoSettings();
        expect($settings->business_name)->toBe('Structural Supplies')
            ->and($settings->address)->toContain('Spintex Road')
            ->and($settings->business_type)->toBe('building_materials')
            ->and((bool) $settings->feature_quotations)->toBeTrue()
            ->and((bool) $settings->feature_expiry_batches)->toBeFalse();
    });

    it('writes over its own previous demo name, which nobody chose', function () {
        DB::table('business_settings')->where('id', 1)->update(['business_name' => 'Structural Supplies']);
        demoSeed();
        expect(demoSettings()->business_name)->toBe('Structural Supplies');
    });
});

describe('counting what is real', function () {
    it('is zero for a database holding only demo data', function () {
        demoSeed();
        expect(DemoSeeder::countRealRecords())->toBe(0);
    });

    it('counts a real sale, even beside demo data', function () {
        demoSeed();

        // One sale the demo seed did not write: a shop that has started trading.
        DB::table('sales')->insert([
            'receipt_no' => 'RCP-REAL', 'kind' => 'SALE', 'business_date' => '2026-08-26', 'occurred_at' => '2026-08-26 00:00:00.000',
            'subtotal_minor' => 100, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 100, 'cogs_minor' => 0,
            'status' => 'POSTED', 'is_demo' => false,
        ]);

        expect(DemoSeeder::countRealRecords())->toBe(1);
    });
});

describe('the demo against the old app’s own demo database', function () {
    beforeEach(function () {
        $this->now = Carbon::createFromTimestampMs(DEMO_FIXTURE_NOW, 'UTC');
        $fixture = demoFixture();
        $this->oldTrialBalance = demoTrialBalance($fixture->query(DEMO_TB_SQL)->fetchAll());
        $this->oldStock = demoStock($fixture->query(DEMO_STOCK_SQL)->fetchAll());
    });

    it('was seeded at the instant this test uses', function () {
        $at = (int) demoFixture()->query("SELECT created_at FROM audit_logs WHERE action = 'SEED_DEMO'")->fetchColumn();
        expect($at)->toBe(DEMO_FIXTURE_NOW)
            ->and($this->now->toIso8601ZuluString('millisecond'))->toBe('2026-11-05T16:47:10.491Z');
    });

    it('matches it exactly, every account and every product, when run in the order the fixture was made', function () {
        demoSeed($this->now, inDateOrder: false);

        expect(demoTrialBalance(DB::select(DEMO_TB_SQL)))->toBe($this->oldTrialBalance)
            ->and(demoStock(DB::select(DEMO_STOCK_SQL)))->toBe($this->oldStock);
    });

    it('matches it in date order too, except where the average cost sees a different order', function () {
        demoSeed($this->now);

        $new = demoTrialBalance(DB::select(DEMO_TB_SQL));
        $old = $this->oldTrialBalance;
        $costed = [ChartOfAccounts::INVENTORY, ChartOfAccounts::COST_OF_GOODS_SOLD];
        $net = fn (array $tb, string $code): int => $tb[$code][0] - $tb[$code][1];

        expect(array_diff_key($new, array_flip($costed)))->toBe(array_diff_key($old, array_flip($costed)))
            // What left the shelf and what is still on it add up to what came in.
            ->and($net($new, '1200') + $net($new, '5000'))->toBe($net($old, '1200') + $net($old, '5000'))
            // Every quantity is the same; only values are re-averaged.
            ->and(array_map(fn (array $qv): int => $qv[0], demoStock(DB::select(DEMO_STOCK_SQL))))
            ->toBe(array_map(fn (array $qv): int => $qv[0], $this->oldStock))
            // And the shelf still equals the Inventory account.
            ->and(InventoryService::getInventoryValue())->toBe($net($new, '1200'));
    });
});
