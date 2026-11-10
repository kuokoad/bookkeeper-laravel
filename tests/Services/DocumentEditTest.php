<?php

declare(strict_types=1);

/*
| Owner decision D34: an owner edits a saved document instead of voiding and
| re-entering it. The edit keeps the document and its number, reverses what
| it posted on the day it was recorded, and posts the new figures; the books
| must come out exactly as if the corrected document had been entered first.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\ForbiddenError;
use App\Domain\Errors\PeriodLockedError;
use App\Domain\Errors\ValidationError;
use App\Enums\AdjustmentReason;
use App\Livewire\Inventory\Batch;
use App\Services\Actor;
use App\Services\AuditService;
use App\Services\CashbookService;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const EDIT_DAY = '2026-08-16';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->product = Shop::product($this->actor, 'Milo 400g');
    // 10 tins at GHS 5.00 each.
    Shop::openingStock($this->actor, $this->product, 10_000, 5_000, EDIT_DAY);
    $this->damage = fn (int $qty): array => StockAdjustmentService::createStockAdjustment(
        ['businessDate' => EDIT_DAY, 'reason' => AdjustmentReason::Damaged, 'items' => [['productId' => $this->product, 'direction' => 'OUT', 'qty' => $qty]]],
        $this->actor,
    );
    $this->edit = fn (int $id, int $qty, string $why = 'Counted wrong', ?Actor $actor = null, string $date = EDIT_DAY, ?Carbon $now = null): array => StockAdjustmentService::editStockAdjustment(
        $id,
        ['businessDate' => $date, 'reason' => AdjustmentReason::Damaged, 'items' => [['productId' => $this->product, 'direction' => 'OUT', 'qty' => $qty]]],
        $why,
        $actor ?? $this->actor,
        $now,
    );
});

describe('editing a stock adjustment', function () {
    it('leaves stock and the books as if the corrected adjustment had been entered', function () {
        $damage = ($this->damage)(4_000);
        ($this->edit)($damage['adjustmentId'], 2_000);

        $product = CatalogService::getProduct($this->product);
        expect($product['qtyOnHand'])->toBe(8_000)
            ->and($product['stockValue'])->toBe(4_000)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY_SHRINKAGE))->toBe(1_000)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(4_000);
        Shop::assertBooksHealthy('after edit');
    });

    it('keeps the same document and number, marked edited, listing the new lines', function () {
        $damage = ($this->damage)(4_000);
        ($this->edit)($damage['adjustmentId'], 2_000);

        $data = StockAdjustmentService::getStockAdjustment($damage['adjustmentId']);
        expect($data['adjustment']->adjustment_no)->toBe($damage['adjustmentNo'])
            ->and($data['adjustment']->status)->toBe('POSTED')
            ->and($data['adjustment']->edited_at)->not->toBeNull()
            ->and($data['items'])->toHaveCount(1)
            ->and((int) $data['items'][0]->qty_milli)->toBe(2_000)
            ->and(DB::table('stock_adjustments')->count())->toBe(2);
    });

    it('reverses the old entry on the day it was recorded, never editing it', function () {
        $damage = ($this->damage)(4_000);
        ($this->edit)($damage['adjustmentId'], 2_000, now: Carbon::parse('2026-09-03 10:00', 'Africa/Accra'));

        $entries = DB::table('journal_entries')->where('source_type', 'STOCK_ADJUSTMENT')->where('source_id', $damage['adjustmentId'])->orderBy('id')->get();
        expect($entries)->toHaveCount(3)
            ->and((int) $entries[0]->reversed_by_entry_id)->toBe((int) $entries[1]->id)
            ->and((string) $entries[1]->entry_date)->toBe(EDIT_DAY)
            ->and((string) $entries[2]->entry_date)->toBe(EDIT_DAY)
            ->and((int) DB::table('stock_adjustments')->where('id', $damage['adjustmentId'])->value('journal_entry_id'))->toBe((int) $entries[2]->id);
    });

    it('moves the adjustment to a new date when the date is corrected', function () {
        $damage = ($this->damage)(4_000);
        ($this->edit)($damage['adjustmentId'], 4_000, date: '2026-08-18');

        expect((string) DB::table('stock_adjustments')->where('id', $damage['adjustmentId'])->value('business_date'))->toBe('2026-08-18')
            ->and(Shop::balance(ChartOfAccounts::INVENTORY_SHRINKAGE, ['from' => EDIT_DAY, 'to' => EDIT_DAY]))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY_SHRINKAGE, ['from' => '2026-08-18', 'to' => '2026-08-18']))->toBe(2_000);
    });

    it('refuses once stock it brought in has gone out since, but not stock it took out', function () {
        // Opening stock 10 at GHS 5.00; 4 written off since, costed with it.
        $opening = (int) DB::table('stock_adjustments')->orderBy('id')->value('id');
        Shop::stockOut($this->actor, $this->product, 4_000, EDIT_DAY);
        $reprice = fn (): array => StockAdjustmentService::editStockAdjustment(
            $opening,
            ['businessDate' => EDIT_DAY, 'reason' => AdjustmentReason::OpeningStock, 'items' => [['productId' => $this->product, 'direction' => 'IN', 'qty' => 10_000, 'totalCost' => 6_000]]],
            'Cost was 6.00',
            $this->actor,
        );

        expect($reprice)->toThrow(ConflictError::class, 'gone out since');
        expect(CatalogService::getProduct($this->product)['stockValue'])->toBe(3_000);

        // A later write-off still edits: its goods go back at what they left at.
        $damage = ($this->damage)(2_000);
        ($this->edit)($damage['adjustmentId'], 1_000);
        expect(CatalogService::getProduct($this->product)['qtyOnHand'])->toBe(5_000);
        Shop::assertBooksHealthy('after the refused edit and the allowed one');
    });

    it('marks the costing difference of voiding a demo adjustment as demo', function () {
        // 10 more at GHS 7.00 as demo stock (20 at 12.00); 10 out at the 6.00 average;
        // voiding the 10 at 7.00 empties the shelf 1.00 off zero, which goes to COGS.
        $found = StockAdjustmentService::createStockAdjustment(
            ['businessDate' => EDIT_DAY, 'reason' => AdjustmentReason::Found, 'isDemo' => true, 'items' => [['productId' => $this->product, 'direction' => 'IN', 'qty' => 10_000, 'totalCost' => 7_000]]],
            $this->actor,
        );
        ($this->damage)(10_000);
        StockAdjustmentService::voidStockAdjustment($found['adjustmentId'], 'Demo data', $this->actor);

        $residual = DB::table('journal_entries')->where('memo', 'like', 'Stock costing difference on voiding%')->get(['is_demo']);
        expect($residual)->toHaveCount(1)
            ->and((bool) $residual[0]->is_demo)->toBeTrue();
        Shop::assertBooksHealthy('after voiding demo stock');
    });

    it('keeps what it said before, who changed it and why', function () {
        $damage = ($this->damage)(4_000);
        ($this->edit)($damage['adjustmentId'], 2_000, 'Only two tins were dented');

        $history = AuditService::editHistory('stock_adjustment', $damage['adjustmentId']);
        expect($history)->toHaveCount(1)
            ->and($history[0]['username'])->toBe('kwame')
            ->and($history[0]['reason'])->toBe('Only two tins were dented')
            ->and($history[0]['before']['lines'][0]['amount'])->toBe(2_000)
            ->and($history[0]['after']['lines'][0]['amount'])->toBe(1_000);
    });

    it('can be edited twice and then voided, ending where it started', function () {
        $damage = ($this->damage)(4_000);
        ($this->edit)($damage['adjustmentId'], 2_000);
        ($this->edit)($damage['adjustmentId'], 3_000);
        expect(CatalogService::getProduct($this->product)['qtyOnHand'])->toBe(7_000);

        StockAdjustmentService::voidStockAdjustment($damage['adjustmentId'], 'Not damaged at all', $this->actor);
        expect(CatalogService::getProduct($this->product)['qtyOnHand'])->toBe(10_000)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY_SHRINKAGE))->toBe(0);
        Shop::assertBooksHealthy('after edits and void');
    });

    it('is for the owner only', function () {
        $damage = ($this->damage)(4_000);
        $staff = new Actor(staff(), 'ama');

        expect(fn () => ($this->edit)($damage['adjustmentId'], 2_000, actor: $staff))->toThrow(ForbiddenError::class);
    });

    it('needs a reason', function () {
        $damage = ($this->damage)(4_000);

        expect(fn () => ($this->edit)($damage['adjustmentId'], 2_000, 'x'))->toThrow(ValidationError::class);
    });

    it('refuses a voided adjustment', function () {
        $damage = ($this->damage)(4_000);
        StockAdjustmentService::voidStockAdjustment($damage['adjustmentId'], 'Mistake', $this->actor);

        expect(fn () => ($this->edit)($damage['adjustmentId'], 2_000))->toThrow(ConflictError::class, 'voided');
    });

    it('refuses an adjustment in a locked period, changing nothing', function () {
        $damage = ($this->damage)(4_000);
        Shop::setting('books_locked_before', '2026-08-20');

        expect(fn () => ($this->edit)($damage['adjustmentId'], 2_000, date: '2026-08-21'))->toThrow(PeriodLockedError::class)
            ->and(CatalogService::getProduct($this->product)['qtyOnHand'])->toBe(6_000);
    });

    it('refuses to move an adjustment into a locked period', function () {
        Shop::setting('books_locked_before', '2026-08-10');
        $damage = ($this->damage)(4_000);

        expect(fn () => ($this->edit)($damage['adjustmentId'], 2_000, date: '2026-08-05'))->toThrow(PeriodLockedError::class);
    });

    it('puts each line back in the batch it came from, not the undated one', function () {
        $milk = Shop::product($this->actor, 'Fresh milk 1L');
        // Batch B1: 10 cartons with a date; batch B2: 10 undated.
        $in = fn (?string $expiry): int => Shop::move([
            'productId' => $milk, 'direction' => 'IN', 'qty' => 10_000, 'totalCost' => 10_000,
            'movementType' => 'ADJUSTMENT_IN', 'sourceType' => 'TEST', 'businessDate' => EDIT_DAY,
            'occurredAt' => Clock::fromStamp(EDIT_DAY.' 09:00:00'), 'batch' => ['kind' => 'NEW', 'expiryDate' => $expiry],
        ])['batchAllocations'][0]['batchId'];
        $dated = $in('2026-12-31');
        $undated = $in(null);
        $held = fn (): array => DB::table('product_batches')->where('product_id', $milk)->pluck('qty_milli', 'id')->map(fn ($q): int => (int) $q)->all();
        $writeOff = ['businessDate' => EDIT_DAY, 'reason' => AdjustmentReason::Expired, 'items' => [['productId' => $milk, 'direction' => 'OUT', 'qty' => 4_000, 'batchId' => $dated]]];
        $adjustment = StockAdjustmentService::createStockAdjustment($writeOff, $this->actor);

        // Only the note changes: every batch must hold what it held before.
        StockAdjustmentService::editStockAdjustment($adjustment['adjustmentId'], [...$writeOff, 'note' => 'Fridge failed'], 'Add the note', $this->actor);
        expect($held())->toBe([$dated => 6_000, $undated => 10_000]);

        // An IN line comes back out of the batch it went into, not the soonest-expiring one.
        $found = ['businessDate' => EDIT_DAY, 'reason' => AdjustmentReason::Found, 'items' => [['productId' => $milk, 'direction' => 'IN', 'qty' => 2_000, 'totalCost' => 2_000]]];
        $count = StockAdjustmentService::createStockAdjustment($found, $this->actor);
        StockAdjustmentService::editStockAdjustment($count['adjustmentId'], [...$found, 'note' => 'Back of the shelf'], 'Add the note', $this->actor);
        expect($held())->toBe([$dated => 6_000, $undated => 12_000]);

        // Voiding puts each line back the same way: the write-off into the dated batch, the count out of the undated one.
        StockAdjustmentService::voidStockAdjustment($adjustment['adjustmentId'], 'Fridge was fine', $this->actor);
        StockAdjustmentService::voidStockAdjustment($count['adjustmentId'], 'Counted twice', $this->actor);
        expect($held())->toBe([$dated => 10_000, $undated => 10_000]);
    });
});

describe('editing an expense or income', function () {
    beforeEach(function () {
        $this->cash = Shop::paymentAccount('CASH');
        $this->momo = Shop::paymentAccount('MOBILE_MONEY');
        $this->expenseInput = fn (array $changes = []): array => [
            'businessDate' => EDIT_DAY,
            'categoryAccountId' => Shop::expenseCategory('Transport'),
            'description' => 'Taxi to Madina market',
            'amount' => 5_000,
            'paymentAccountId' => $this->cash,
            ...$changes,
        ];
    });

    it('moves the amount and the account as if it had been entered right', function () {
        $expense = CashbookService::recordExpense(($this->expenseInput)(), $this->actor);
        CashbookService::editExpense($expense['id'], ($this->expenseInput)(['amount' => 1_500, 'paymentAccountId' => $this->momo]), 'Typed 50 instead of 15', $this->actor);

        expect(Shop::balance('1001'))->toBe(0)
            ->and(Shop::balance('1011'))->toBe(-1_500)
            ->and(Shop::balance('6050'))->toBe(1_500);
        $row = DB::table('expenses')->where('id', $expense['id'])->first();
        expect($row->expense_no)->toBe($expense['documentNo'])
            ->and((int) $row->amount_minor)->toBe(1_500)
            ->and($row->edited_at)->not->toBeNull()
            ->and(DB::table('expenses')->count())->toBe(1);
        Shop::assertBooksHealthy('after expense edit');
    });

    it('reverses on the day it was recorded and keeps what it said', function () {
        $expense = CashbookService::recordExpense(($this->expenseInput)(), $this->actor);
        CashbookService::editExpense($expense['id'], ($this->expenseInput)(['amount' => 1_500]), 'Typed 50 instead of 15', $this->actor, Carbon::parse('2026-09-03 10:00', 'Africa/Accra'));

        $reversal = DB::table('journal_entries')->whereNotNull('reverses_entry_id')->first();
        expect((string) $reversal->entry_date)->toBe(EDIT_DAY)
            ->and((int) $reversal->reverses_entry_id)->toBe($expense['journalEntryId']);
        $history = AuditService::editHistory('expense', $expense['id']);
        expect($history[0]['before']['total'])->toBe(5_000)
            ->and($history[0]['after']['total'])->toBe(1_500)
            ->and($history[0]['before']['fields']['Paid from'])->not->toBeNull();
    });

    it('edits income the same way', function () {
        $income = CashbookService::recordIncome([
            'businessDate' => EDIT_DAY, 'categoryAccountId' => Shop::incomeCategory('Commission'), 'description' => 'Airtime',
            'amount' => 2_000, 'paymentAccountId' => $this->cash,
        ], $this->actor);
        CashbookService::editIncome($income['id'], [
            'businessDate' => EDIT_DAY, 'categoryAccountId' => Shop::incomeCategory('Commission'), 'description' => 'Airtime',
            'amount' => 2_500, 'paymentAccountId' => $this->cash,
        ], 'Miscounted', $this->actor);

        expect(Shop::balance('1001'))->toBe(2_500);
        Shop::assertBooksHealthy('after income edit');
    });

    it('is for the owner only, needs a reason, and refuses a voided record', function () {
        $expense = CashbookService::recordExpense(($this->expenseInput)(), $this->actor);
        $staff = new Actor(staff(), 'ama');

        expect(fn () => CashbookService::editExpense($expense['id'], ($this->expenseInput)(), 'Fix', $staff))->toThrow(ForbiddenError::class)
            ->and(fn () => CashbookService::editExpense($expense['id'], ($this->expenseInput)(), 'x', $this->actor))->toThrow(ValidationError::class);
        CashbookService::voidExpense($expense['id'], 'Entered twice', $this->actor);
        expect(fn () => CashbookService::editExpense($expense['id'], ($this->expenseInput)(), 'Fix it', $this->actor))->toThrow(ConflictError::class, 'voided');
    });

    it('refuses a record in a locked period', function () {
        $expense = CashbookService::recordExpense(($this->expenseInput)(), $this->actor);
        Shop::setting('books_locked_before', '2026-08-20');

        expect(fn () => CashbookService::editExpense($expense['id'], ($this->expenseInput)(['businessDate' => '2026-08-21']), 'Wrong day', $this->actor))
            ->toThrow(PeriodLockedError::class)
            ->and(Shop::balance('1001'))->toBe(-5_000);
    });
});

describe('editing a purchase', function () {
    beforeEach(function () {
        Shop::setNoTax();
        $this->supplier = Shop::supplier($this->actor);
        $this->purchaseInput = fn (int $qty, int $unitCost, array $extra = []): array => [
            'supplierId' => $this->supplier,
            'businessDate' => EDIT_DAY,
            'items' => [['productId' => $this->product, 'qty' => $qty, 'unitCost' => $unitCost, ...($extra['line'] ?? [])]],
            'tenders' => $extra['tenders'] ?? [],
        ];
        $this->buy = fn (int $qty, int $unitCost, array $extra = []): array => PurchaseService::createPurchase(($this->purchaseInput)($qty, $unitCost, $extra), $this->actor);
    });

    it('refuses to re-price a purchase after the tax settings changed', function () {
        Shop::setSingleTax(1_500);
        $purchase = ($this->buy)(20_000, 600);
        $edit = fn (): array => PurchaseService::editPurchase($purchase['purchaseId'], ($this->purchaseInput)(20_000, 550), 'Invoice said 5.50', $this->actor);

        Shop::setSingleTax(1_250);
        expect($edit)->toThrow(ConflictError::class, 'tax settings have changed');
        Shop::setSingleTax(1_500);
        $edit();
        expect((int) DB::table('purchases')->where('id', $purchase['purchaseId'])->value('tax_minor'))->toBe(1_650);
    });

    it('leaves stock, cost and the payable as if the corrected delivery had been entered', function () {
        // 10 on the shelf at GHS 5.00; 20 more bought at GHS 6.00, but the invoice said GHS 5.50.
        $purchase = ($this->buy)(20_000, 600);
        PurchaseService::editPurchase($purchase['purchaseId'], ($this->purchaseInput)(20_000, 550), 'Invoice said 5.50', $this->actor);

        $product = CatalogService::getProduct($this->product);
        expect($product['qtyOnHand'])->toBe(30_000)
            ->and($product['stockValue'])->toBe(16_000)
            ->and(Shop::balance(ChartOfAccounts::ACCOUNTS_PAYABLE))->toBe(11_000)
            ->and(PurchaseService::getPurchaseOutstanding($purchase['purchaseId']))->toBe(11_000);
        $row = DB::table('purchases')->where('id', $purchase['purchaseId'])->first();
        expect($row->purchase_no)->toBe($purchase['purchaseNo'])
            ->and((int) $row->total_minor)->toBe(11_000)
            ->and($row->edited_at)->not->toBeNull()
            ->and(DB::table('purchases')->count())->toBe(1)
            ->and(DB::table('purchase_items')->where('purchase_id', $purchase['purchaseId'])->count())->toBe(1);
        Shop::assertBooksHealthy('after purchase edit');
    });

    it('can turn a credit purchase into a cash one', function () {
        $purchase = ($this->buy)(20_000, 600);
        PurchaseService::editPurchase($purchase['purchaseId'], ($this->purchaseInput)(20_000, 600, ['tenders' => [['paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => 12_000]]]), 'Paid cash on delivery', $this->actor);

        expect(Shop::balance(ChartOfAccounts::ACCOUNTS_PAYABLE))->toBe(0)
            ->and(Shop::balance('1001'))->toBe(-12_000);
        Shop::assertBooksHealthy('after credit to cash');
    });

    it('empties the old crate and fills a new one, and a later void takes the new one back out', function () {
        $purchase = ($this->buy)(20_000, 600, ['line' => ['expiryDate' => '2027-01-31']]);
        PurchaseService::editPurchase($purchase['purchaseId'], ($this->purchaseInput)(15_000, 600, ['line' => ['expiryDate' => '2027-02-28']]), 'Only 15 came', $this->actor);

        $crates = DB::table('product_batches')->where('product_id', $this->product)->whereNotNull('expiry_date')->orderBy('id')->get();
        expect((int) $crates[0]->qty_milli)->toBe(0)
            ->and((int) $crates[1]->qty_milli)->toBe(15_000)
            ->and((string) $crates[1]->expiry_date)->toBe('2027-02-28');

        PurchaseService::voidPurchase($purchase['purchaseId'], 'Wrong shop', $this->actor);
        expect(CatalogService::getProduct($this->product)['qtyOnHand'])->toBe(10_000)
            ->and((int) DB::table('product_batches')->where('id', $crates[1]->id)->value('qty_milli'))->toBe(0);
        Shop::assertBooksHealthy('after edit then void');
    });

    it('shows an edit in the crate history as an edit, and a real void as cancelled', function () {
        $purchase = ($this->buy)(20_000, 600, ['line' => ['expiryDate' => '2027-01-31']]);
        PurchaseService::editPurchase($purchase['purchaseId'], ($this->purchaseInput)(15_000, 600, ['line' => ['expiryDate' => '2027-02-28']]), 'Only 15 came', $this->actor);
        PurchaseService::voidPurchase($purchase['purchaseId'], 'Wrong shop', $this->actor);
        $crates = DB::table('product_batches')->where('product_id', $this->product)->whereNotNull('expiry_date')->orderBy('id')->pluck('id');

        $taken = fn (int $crate): array => collect(InventoryService::getBatchHistory($crate)['entries'])->firstWhere('sourceType', 'PURCHASE_VOID');
        $edited = $taken($crates[0]);
        $voided = $taken($crates[1]);
        expect($edited['isEdit'])->toBeTrue()
            ->and(Batch::label($edited))->toBe('Delivery edited')
            ->and(Batch::documentHref($edited))->toBe("/purchases/{$purchase['purchaseId']}")
            ->and($voided['isEdit'])->toBeFalse()
            ->and(Batch::label($voided))->toBe('Delivery cancelled');
    });

    it('refuses once any of its stock has gone out since', function () {
        $purchase = ($this->buy)(20_000, 600);
        Shop::stockOut($this->actor, $this->product, 1_000, EDIT_DAY);

        expect(fn () => PurchaseService::editPurchase($purchase['purchaseId'], ($this->purchaseInput)(20_000, 550), 'Invoice said 5.50', $this->actor))
            ->toThrow(ConflictError::class, 'gone out since');
    });

    it('is for the owner only, and refuses a voided purchase', function () {
        $purchase = ($this->buy)(20_000, 600);
        $staff = new Actor(staff(), 'ama');
        expect(fn () => PurchaseService::editPurchase($purchase['purchaseId'], ($this->purchaseInput)(20_000, 550), 'Fix it', $staff))->toThrow(ForbiddenError::class);

        PurchaseService::voidPurchase($purchase['purchaseId'], 'Wrong shop', $this->actor);
        expect(fn () => PurchaseService::editPurchase($purchase['purchaseId'], ($this->purchaseInput)(20_000, 550), 'Fix it', $this->actor))->toThrow(ConflictError::class, 'voided');
    });

    it('keeps what it said before', function () {
        $purchase = ($this->buy)(20_000, 600);
        PurchaseService::editPurchase($purchase['purchaseId'], ($this->purchaseInput)(20_000, 550), 'Invoice said 5.50', $this->actor);

        $history = AuditService::editHistory('purchase', $purchase['purchaseId']);
        expect($history[0]['before']['total'])->toBe(12_000)
            ->and($history[0]['after']['total'])->toBe(11_000)
            ->and($history[0]['before']['lines'])->toHaveCount(1);
    });
});

describe('editing a sale', function () {
    beforeEach(function () {
        Shop::setNoTax();
        $this->cash = Shop::paymentAccount('CASH');
        // Milo sells at GHS 8.00 and cost GHS 5.00.
        $this->saleInput = fn (int $qty, array $extra = []): array => [
            'businessDate' => EDIT_DAY,
            'items' => [['productId' => $this->product, 'qty' => $qty]],
            'tenders' => $extra['tenders'] ?? [['paymentAccountId' => $this->cash, 'amount' => intdiv($qty, 1000) * 800]],
            ...array_diff_key($extra, ['tenders' => true]),
        ];
        $this->sell = fn (int $qty, array $extra = []): array => SaleService::createSale(($this->saleInput)($qty, $extra), $this->actor);
    });

    it('refuses to re-price a sale after the tax settings changed', function () {
        Shop::setSingleTax(1_500, inclusive: true);
        $sale = ($this->sell)(1_000);
        $note = fn (): array => SaleService::editSale($sale['saleId'], ($this->saleInput)(1_000, ['note' => 'Paid by Ama']), 'Add the note', $this->actor);

        Shop::setSingleTax(1_500, inclusive: false);
        expect($note)->toThrow(ConflictError::class, 'tax settings have changed');
        Shop::setSingleTax(1_250, inclusive: true);
        expect($note)->toThrow(ConflictError::class, 'tax settings have changed');
        Shop::setNoTax();
        expect($note)->toThrow(ConflictError::class, 'tax settings have changed');

        // Put back as they were, the edit goes through and the total stays GHS 8.00.
        Shop::setSingleTax(1_500, inclusive: true);
        $note();
        expect((int) DB::table('sales')->where('id', $sale['saleId'])->value('total_minor'))->toBe(800);
    });

    it('keeps the replaced lines, taxes and payments whole in the audit row, cost and rate included', function () {
        Shop::setSingleTax(1_500, inclusive: true);
        $sale = ($this->sell)(3_000, ['tenders' => [['paymentAccountId' => $this->cash, 'amount' => 2_400, 'reference' => 'TILL-7']]]);
        $old = DB::table('sale_items')->where('sale_id', $sale['saleId'])->first();
        SaleService::editSale($sale['saleId'], ($this->saleInput)(2_000), 'Rang up 3, sold 2', $this->actor);

        $replaced = json_decode((string) DB::table('audit_logs')->where('entity_type', 'sale')->where('action', 'UPDATE')->value('metadata'), true)['replaced'];
        expect($replaced['sale_items'])->toHaveCount(1)
            ->and($replaced['sale_items'][0]['qty_milli'])->toBe(3_000)
            ->and($replaced['sale_items'][0]['total_cost_minor'])->toBe((int) $old->total_cost_minor)
            ->and($replaced['sale_items'][0]['total_cost_minor'])->toBe(1_500)
            ->and($replaced['sale_taxes'][0]['rate_bp'])->toBe(1_500)
            ->and($replaced['sale_payments'][0]['reference'])->toBe('TILL-7')
            ->and(DB::table('sale_items')->where('sale_id', $sale['saleId'])->value('qty_milli'))->toBe(2_000);
    });

    it('leaves stock, cash, revenue and cost as if the corrected sale had been rung up', function () {
        $sale = ($this->sell)(3_000);
        SaleService::editSale($sale['saleId'], ($this->saleInput)(2_000), 'Rang up 3, sold 2', $this->actor);

        expect(CatalogService::getProduct($this->product)['qtyOnHand'])->toBe(8_000)
            ->and(Shop::balance('1001'))->toBe(1_600)
            ->and(Shop::balance(ChartOfAccounts::SALES_REVENUE))->toBe(1_600)
            ->and(Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD))->toBe(1_000);
        $row = DB::table('sales')->where('id', $sale['saleId'])->first();
        expect($row->receipt_no)->toBe($sale['receiptNo'])
            ->and((int) $row->total_minor)->toBe(1_600)
            ->and((int) $row->cogs_minor)->toBe(1_000)
            ->and($row->edited_at)->not->toBeNull()
            ->and(DB::table('sales')->count())->toBe(1);
        Shop::assertBooksHealthy('after sale edit');
    });

    it('can put a cash sale on a customer account, giving it an invoice number', function () {
        $sale = ($this->sell)(3_000);
        $customer = Shop::customer($this->actor);
        SaleService::editSale($sale['saleId'], ($this->saleInput)(3_000, ['tenders' => [], 'customerId' => $customer]), 'Ama took it on credit', $this->actor);

        $row = DB::table('sales')->where('id', $sale['saleId'])->first();
        expect($row->invoice_no)->not->toBeNull()
            ->and((int) $row->customer_id)->toBe($customer)
            ->and(SaleService::getSaleOutstanding($sale['saleId']))->toBe(2_400)
            ->and(Shop::balance('1001'))->toBe(0);
        Shop::assertBooksHealthy('after cash to credit');
    });

    it('can be edited and then voided, ending where it started', function () {
        $sale = ($this->sell)(3_000);
        SaleService::editSale($sale['saleId'], ($this->saleInput)(2_000), 'Rang up 3, sold 2', $this->actor);
        SaleService::voidSale($sale['saleId'], 'Customer changed their mind', $this->actor);

        expect(CatalogService::getProduct($this->product)['qtyOnHand'])->toBe(10_000)
            ->and(CatalogService::getProduct($this->product)['stockValue'])->toBe(5_000)
            ->and(Shop::balance('1001'))->toBe(0);
        Shop::assertBooksHealthy('after sale edit then void');
    });

    it('keeps what the goods cost on the day of the sale, after the average has moved', function () {
        $sale = ($this->sell)(3_000);
        // Later, 10 more arrive at GHS 7.00: the average rises above GHS 5.00.
        PurchaseService::createPurchase(['supplierId' => Shop::supplier($this->actor), 'businessDate' => EDIT_DAY, 'items' => [['productId' => $this->product, 'qty' => 10_000, 'unitCost' => 700]], 'tenders' => []], $this->actor);

        SaleService::editSale($sale['saleId'], ($this->saleInput)(2_000), 'Sold 2, not 3', $this->actor);
        expect((int) DB::table('sales')->where('id', $sale['saleId'])->value('cogs_minor'))->toBe(1_000);
        Shop::assertBooksHealthy('after edit down');

        // Up to 4: the 2 on the sale keep GHS 5.00; the 2 added are new goods, at the average of
        // the rest of the shelf (18 units worth 11,000), as a new sale would take them.
        SaleService::editSale($sale['saleId'], ($this->saleInput)(4_000), 'Sold 4 after all', $this->actor);
        expect((int) DB::table('sales')->where('id', $sale['saleId'])->value('cogs_minor'))->toBe(1_000 + 1_222);
        Shop::assertBooksHealthy('after edit up');
    });

    it('refuses a sale with goods returned against it', function () {
        $sale = ($this->sell)(3_000);
        $itemId = (int) DB::table('sale_items')->where('sale_id', $sale['saleId'])->value('id');
        ReturnsService::createCustomerReturn($sale['saleId'], ['businessDate' => EDIT_DAY, 'items' => [['itemId' => $itemId, 'qty' => 1_000]], 'refunds' => [['paymentAccountId' => $this->cash, 'amount' => 800]], 'reason' => 'Dented'], $this->actor);

        expect(fn () => SaleService::editSale($sale['saleId'], ($this->saleInput)(2_000), 'Fix it', $this->actor))->toThrow(ConflictError::class, 'returned');
    });

    it('is for the owner only, and keeps what it said before', function () {
        $sale = ($this->sell)(3_000);
        $staff = new Actor(staff(), 'ama');
        expect(fn () => SaleService::editSale($sale['saleId'], ($this->saleInput)(2_000), 'Fix it', $staff))->toThrow(ForbiddenError::class);

        SaleService::editSale($sale['saleId'], ($this->saleInput)(2_000), 'Rang up 3, sold 2', $this->actor);
        $history = AuditService::editHistory('sale', $sale['saleId']);
        expect($history[0]['before']['total'])->toBe(2_400)
            ->and($history[0]['after']['total'])->toBe(1_600);
    });
});
