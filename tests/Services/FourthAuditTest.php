<?php

declare(strict_types=1);

/*
| Fourth audit: each test is named for the behaviour that broke, and asserts
| what a shop owner would check, in minor units.
*/

use App\Domain\Errors\ConflictError;
use App\Domain\Errors\ForbiddenError;
use App\Domain\Errors\ValidationError;
use App\Enums\UserRole;
use App\Services\Actor;
use App\Services\CashbookService;
use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\PaymentAccountService;
use App\Services\PurchaseService;
use App\Services\QuotationService;
use App\Services\ReconciliationService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use App\Services\SettingsService;
use App\Services\StockAdjustmentService;
use App\Services\SupplierService;
use App\Services\TransferService;
use App\Services\UserService;
use App\Services\YearEndCloseService;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const FA_DAY = '2026-08-16';

beforeEach(function () {
    Shop::setNoTax();
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->momo = Shop::paymentAccount('MOBILE_MONEY');
    $this->product = Shop::product($this->actor, 'Milo 400g', 500, 800);
    Shop::openingStock($this->actor, $this->product, 10_000, 5_000, FA_DAY);
    // GHS 100.00 of commission in 2026, so the year has something to close.
    $this->trade = fn () => Shop::income($this->actor, '2026-08-20', Shop::incomeCategory('Commission'), 10_000);
});

afterEach(fn () => Carbon::setTestNow());

describe('the financial year start month', function () {
    it('cannot change while a year is closed, so the closed year keeps its range', function () {
        ($this->trade)();
        Carbon::setTestNow('2027-06-15 10:00:00');
        YearEndCloseService::closeFinancialYear(2026, $this->actor);

        expect(fn () => SettingsService::update(Shop::settingsInput(['financialYearStartMonth' => 7]), $this->actor))
            ->toThrow(ValidationError::class, 'cannot change while a year is closed')
            ->and((int) DB::table('business_settings')->value('financial_year_start_month'))->toBe(1);
    });

    it('can change again once the closed year is reopened', function () {
        ($this->trade)();
        Carbon::setTestNow('2027-06-15 10:00:00');
        YearEndCloseService::closeFinancialYear(2026, $this->actor);
        YearEndCloseService::reopenFinancialYear(2026, $this->actor);

        SettingsService::update(Shop::settingsInput(['financialYearStartMonth' => 7]), $this->actor);
        expect((int) DB::table('business_settings')->value('financial_year_start_month'))->toBe(7);
    });

    it('reopens on the stored year end and moves the lock back, even if the start month moved since', function () {
        ($this->trade)();
        Carbon::setTestNow('2027-06-15 10:00:00');
        YearEndCloseService::closeFinancialYear(2026, $this->actor);
        // Changed behind the guard (an older build, or by hand).
        DB::table('business_settings')->update(['financial_year_start_month' => 7]);

        YearEndCloseService::reopenFinancialYear(2026, $this->actor);
        $reversal = DB::table('year_end_closings')->value('reversal_entry_id');
        expect((string) DB::table('journal_entries')->where('id', $reversal)->value('entry_date'))->toBe('2026-12-31')
            ->and(DB::table('business_settings')->value('books_locked_before'))->toBeNull();
    });
});

describe('a note-only sale edit', function () {
    it('is refused after the taxes were reordered, rather than re-pricing the VAT', function () {
        Shop::setGhanaTaxes();
        DB::table('tax_components')->where('code', 'VAT')->update(['basis' => 'NET_PLUS_LEVIES']);
        $input = fn (array $extra = []): array => ['businessDate' => FA_DAY, 'items' => [['productId' => $this->product, 'qty' => 1_000]],
            'tenders' => [], 'customerId' => Shop::customer($this->actor, 'Kofi '.count($extra)), ...$extra];
        $sale = SaleService::createSale($input(), $this->actor);
        $total = (int) DB::table('sales')->where('id', $sale['saleId'])->value('total_minor');
        // VAT moved ahead of the levies: on the same goods it would now charge less.
        DB::table('tax_components')->where('code', 'VAT')->update(['sort_order' => 1]);

        expect(fn () => SaleService::editSale($sale['saleId'], $input(['note' => 'A note']), 'Add a note', $this->actor))
            ->toThrow(ConflictError::class, 'tax settings have changed')
            ->and((int) DB::table('sales')->where('id', $sale['saleId'])->value('total_minor'))->toBe($total);
    });
});

describe('editing an expense', function () {
    it('is refused when its reversal would put money back into an archived account', function () {
        Shop::capital($this->actor, $this->momo, 5_000);
        $expense = Shop::expense($this->actor, FA_DAY, Shop::expenseCategory('Transport'), 5_000, 'Taxi', ['paymentAccountId' => $this->momo]);
        PaymentAccountService::setPaymentAccountActive($this->momo, false, $this->actor);

        expect(fn () => CashbookService::editExpense($expense['id'], ['businessDate' => FA_DAY, 'categoryAccountId' => Shop::expenseCategory('Transport'),
            'description' => 'Taxi', 'amount' => 5_000, 'paymentAccountId' => $this->cash], 'Paid in cash really', $this->actor))
            ->toThrow(ValidationError::class, 'archived')
            ->and(PaymentAccountService::getPaymentAccountBalance($this->momo))->toBe(0);
    });
});

describe('a purchase edit', function () {
    it('cannot leave money owing to an archived supplier', function () {
        $supplier = Shop::supplier($this->actor, 'Kofi Ltd');
        Shop::capital($this->actor, $this->cash, 10_000);
        $input = fn (int $paid, array $extra = []): array => ['supplierId' => $supplier, 'businessDate' => FA_DAY,
            'items' => [['productId' => $this->product, 'qty' => 10_000, 'unitCost' => 500]],
            'tenders' => $paid === 0 ? [] : [['paymentAccountId' => $this->cash, 'amount' => $paid]], ...$extra];
        $purchase = PurchaseService::createPurchase($input(5_000), $this->actor);
        SupplierService::setSupplierActive($supplier, false, $this->actor);

        expect(fn () => PurchaseService::editPurchase($purchase['purchaseId'], $input(0), 'Not paid after all', $this->actor))
            ->toThrow(ValidationError::class, 'archived')
            ->and(SupplierService::getTotalPayables())->toBe(0);
        // Still fine while it stays paid in full.
        PurchaseService::editPurchase($purchase['purchaseId'], $input(5_000, ['note' => 'Invoice 12']), 'Add the invoice', $this->actor);
        expect(SupplierService::getTotalPayables())->toBe(0);
    });
});

describe('voiding', function () {
    it('refuses to void a stock adjustment’s own reversal, which would take stock from the wrong batch', function () {
        $damage = Shop::stockOut($this->actor, $this->product, 5_000);
        $void = StockAdjustmentService::voidStockAdjustment($damage['adjustmentId'], 'Counted wrong', $this->actor);

        expect(fn () => StockAdjustmentService::voidStockAdjustment($void['adjustmentId'], 'Changed my mind', $this->actor))
            ->toThrow(ConflictError::class, 'cannot itself be voided')
            ->and(CatalogService::getProduct($this->product)['qtyOnHand'])->toBe(10_000);
    });

    it('refuses to void a return as if it were a sale, so the sale can still take returns', function () {
        $sale = Shop::sell($this->actor, [[$this->product, 3_000]], [['paymentAccountId' => $this->cash, 'amount' => 2_400]], ['businessDate' => FA_DAY]);
        $item = SaleService::getSale($sale['saleId'])['items'][0];
        $return = ReturnsService::createCustomerReturn($sale['saleId'], ['businessDate' => FA_DAY, 'items' => [['itemId' => $item['id'], 'qty' => 1_000]],
            'refunds' => [['paymentAccountId' => $this->cash, 'amount' => 800]]], $this->actor);

        expect(fn () => SaleService::voidSale($return['returnSaleId'], 'Undo the return', $this->actor))
            ->toThrow(ConflictError::class, 'not a sale')
            ->and((int) DB::table('sale_items')->where('id', $item['id'])->value('returned_qty_milli'))->toBe(1_000);
    });
});

describe('dates', function () {
    it('refuses a return dated before the sale it returns', function () {
        $sale = Shop::sell($this->actor, [[$this->product, 3_000]], [['paymentAccountId' => $this->cash, 'amount' => 2_400]], ['businessDate' => FA_DAY]);
        $item = SaleService::getSale($sale['saleId'])['items'][0];

        expect(fn () => ReturnsService::createCustomerReturn($sale['saleId'], ['businessDate' => '2026-08-10', 'items' => [['itemId' => $item['id'], 'qty' => 1_000]]], $this->actor))
            ->toThrow(ValidationError::class, 'A return cannot be dated before the sale it returns (16 Aug 2026).')
            ->and(CatalogService::getProduct($this->product)['qtyOnHand'])->toBe(7_000);
    });

    it('refuses a cash count, an expense and a transfer dated in the future', function () {
        $tomorrow = Carbon::parse(Clock::today())->addDay()->format('Y-m-d');
        Shop::capital($this->actor, $this->cash, 10_000);

        expect(fn () => ReconciliationService::createReconciliation(['paymentAccountId' => $this->cash, 'businessDate' => $tomorrow, 'actual' => 10_000, 'adjust' => false], $this->actor))
            ->toThrow(ValidationError::class, 'future')
            ->and(fn () => Shop::expense($this->actor, $tomorrow, Shop::expenseCategory('Transport'), 1_000))->toThrow(ValidationError::class, 'future')
            ->and(fn () => TransferService::recordTransfer(['fromAccountId' => $this->cash, 'toAccountId' => $this->momo, 'businessDate' => $tomorrow, 'amount' => 1_000], $this->actor))
            ->toThrow(ValidationError::class, 'future')
            ->and(PaymentAccountService::getPaymentAccountBalance($this->cash))->toBe(10_000);
    });
});

describe('small refusals', function () {
    it('refuses a negative credit limit on a customer edit with a message, not an error page', function () {
        $customer = Shop::customer($this->actor);

        expect(fn () => CustomerService::updateCustomer($customer, ['name' => 'Ama Serwaa', 'creditLimit' => -100], $this->actor))
            ->toThrow(ValidationError::class, 'cannot be negative');
    });

    it('lists a quote valid until today as open, not expired', function () {
        QuotationService::createQuotation(['businessDate' => '2026-09-01', 'validUntil' => '2026-09-01', 'customerName' => 'Kofi Mensah',
            'lines' => [['productId' => $this->product, 'qty' => 1_000]]], $this->actor);

        expect(QuotationService::listQuotations(['expired' => true], '2026-09-01'))->toHaveCount(0)
            ->and(QuotationService::listQuotations(['expired' => true], '2026-09-02'))->toHaveCount(1);
    });

    it('refuses a supervisor renaming the owner', function () {
        $managerId = staff(['users' => ['canView' => true, 'canCreate' => false, 'canEdit' => true, 'canVoid' => false]], 'bob');
        $manager = new Actor($managerId, 'bob');
        $ownerName = UserService::getUser($this->actor->id)['displayName'];

        expect(fn () => UserService::updateUser($this->actor->id, ['displayName' => 'Somebody Else', 'role' => UserRole::Owner], $manager))
            ->toThrow(ForbiddenError::class)
            ->and(UserService::getUser($this->actor->id)['displayName'])->toBe($ownerName);
    });
});
