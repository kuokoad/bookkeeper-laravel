<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Auth\Principal;
use App\Domain\BusinessDate;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\ExpiredStockError;
use App\Domain\Errors\StaleUnitError;
use App\Domain\JsCompat;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Domain\Sales\SaleDate;
use App\Domain\Tax\TaxComponents;
use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\PaymentAccountService;
use App\Services\SaleService;
use App\Services\SettingsService;
use App\Services\TaxService;
use App\Support\Clock;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The till: the old (app)/sales/new/page.tsx and pos.tsx, with the parsing of
 * the old createSaleAction. The cart lives here; the figures shown while it
 * is built come from the same domain arithmetic the server uses, and
 * SaleService::createSale recomputes every one of them from its own prices.
 *
 * The sale is named by clientRef = "<cartSeed>-<salesCompleted>": the seed is
 * random, made once per page load, and the counter moves on only when a sale
 * is saved, so a double submit or a retry of the same cart replays the first
 * sale instead of ringing up a second.
 */
#[Title('New sale')]
final class Pos extends Component
{
    #[Locked]
    public string $cartSeed = '';

    #[Locked]
    public int $salesCompleted = 0;

    #[Locked]
    public int $nextKey = 1;

    public string $search = '';

    /** @var array<int, array<string, mixed>> cart lines by key */
    public array $lines = [];

    public string $customerId = '';

    public string $invoiceDiscount = '';

    public string $note = '';

    /** The day somebody picked, or null for "today", which the server decides. */
    public ?string $pickedDate = null;

    public string $tenderAccountId = '';

    public string $tenderAmount = '';

    public string $secondAccountId = '';

    public string $secondAmount = '';

    public string $reference = '';

    public string $overrideReason = '';

    /** @var array{receiptNo: string, saleId: int, change: int}|null */
    #[Locked]
    public ?array $saved = null;

    /** @var array{productName: string, qtyExpired: string, batchRefs: list<string>, mayOverride: bool}|null */
    #[Locked]
    public ?array $expired = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $staleUnit = null;

    public function mount(): void
    {
        $this->cartSeed = (string) Str::uuid();
        $accounts = PaymentAccountService::listPaymentAccounts();
        $default = collect($accounts)->firstWhere('isDefault', true) ?? $accounts[0] ?? null;
        $this->tenderAccountId = $default === null ? '' : (string) $default['id'];
    }

    private function principal(): Principal
    {
        return Principal::load((int) auth()->id()) ?? abort(403);
    }

    // --- the cart ------------------------------------------------------

    public function add(int $productId): void
    {
        $this->authorize('sales.create');
        $product = CatalogService::listProducts(['id' => $productId, 'limit' => 1])[0] ?? null;
        if ($product === null) {
            return;
        }

        // A scan is one piece: it bumps the line taken in the base unit; a carton line is left alone.
        foreach ($this->lines as $key => $line) {
            if ($line['productId'] === $product['id'] && ! $line['alt']) {
                try {
                    $this->lines[$key]['qty'] = Quantity::toInputString(Quantity::add(Quantity::parse($line['qty']), 1000));
                } catch (DomainError) {
                    $this->lines[$key]['qty'] = '1';
                }
                $this->lines[$key]['cartonWas'] = null;
                $this->afterAdd();

                return;
            }
        }

        $dates = InventoryService::getExpiryOutlook(Clock::today())[$product['id']] ?? null;
        $this->lines[$this->nextKey++] = [
            'productId' => $product['id'],
            'name' => $product['name'],
            'unit' => $product['unit'],
            'altUnit' => $product['altUnit'],
            'altFactorMilli' => $product['altFactor'],
            'alt' => false,
            'sellingPrice' => $product['sellingPrice'],
            'altSellingPrice' => $product['altSellingPrice'],
            'qty' => '1',
            'unitPrice' => Money::toInputString($product['sellingPrice']),
            'discount' => '',
            'qtyOnHandMilli' => $product['qtyOnHand'],
            'trackInventory' => $product['trackInventory'],
            'goodQtyMilli' => $dates['goodQtyMilli'] ?? 0,
            'soonestExpiry' => $dates['soonestExpiry'] ?? null,
            'warnDays' => $dates['warnDays'] ?? (int) (SettingsService::get()->expiry_warning_days ?? 30),
            'cartonWas' => null,
        ];
        $this->afterAdd();
    }

    private function afterAdd(): void
    {
        $this->search = '';
        $this->dispatch('pos-focus-search');
    }

    /** A barcode scanner types fast and presses Enter: an exact barcode or SKU is a scan, else the first match. */
    public function scan(): void
    {
        if (JsCompat::trim($this->search) === '') {
            return;
        }
        $product = CatalogService::findProductByCode($this->search) ?? ($this->matches()[0] ?? null);
        if ($product !== null) {
            $this->add($product['id']);
        }
    }

    public function removeLine(int $key): void
    {
        unset($this->lines[$key]);
    }

    /** Switching unit re-prices the line at that unit's own price. */
    public function setUnit(int $key, string $unit): void
    {
        if (! isset($this->lines[$key]) || $this->lines[$key]['altUnit'] === null) {
            return;
        }
        $alt = $unit === 'alt';
        $line = $this->lines[$key];
        $this->lines[$key]['alt'] = $alt;
        $this->lines[$key]['unitPrice'] = Money::toInputString($alt ? (int) ($line['altSellingPrice'] ?? 0) : (int) $line['sellingPrice']);
        $this->lines[$key]['cartonWas'] = null;
    }

    /** Touching a corrected line is the cashier checking it, so its note goes. */
    public function updatedLines(mixed $value, string $path): void
    {
        $key = (int) explode('.', $path)[0];
        if (isset($this->lines[$key])) {
            $this->lines[$key]['cartonWas'] = null;
        }
    }

    public function clearSale(): void
    {
        $this->lines = [];
        $this->invoiceDiscount = '';
        $this->note = '';
        $this->tenderAmount = '';
        $this->secondAmount = '';
        $this->secondAccountId = '';
        $this->reference = '';
        $this->customerId = '';
        $this->search = '';
        $this->expired = null;
        $this->dispatch('pos-focus-search');
    }

    public function exact(): void
    {
        $this->tenderAmount = Money::toInputString($this->preview()['total']);
    }

    public function splitSecond(): void
    {
        $other = collect(PaymentAccountService::listPaymentAccounts())->first(fn (array $a): bool => (string) $a['id'] !== $this->tenderAccountId);
        $this->secondAccountId = $other === null ? '' : (string) $other['id'];
    }

    public function removeSecond(): void
    {
        $this->secondAccountId = '';
        $this->secondAmount = '';
    }

    public function useToday(): void
    {
        $this->pickedDate = null;
    }

    public function updatedPickedDate(?string $value): void
    {
        // Choosing today is "today", so a page left open overnight does not keep yesterday.
        if ($value === null || $value === '' || $value === Clock::today()) {
            $this->pickedDate = null;
        }
    }

    // --- charging ------------------------------------------------------

    public function complete(bool $sellExpired = false): void
    {
        $this->authorize('sales.create');
        $principal = $this->principal();
        // Each right re-read now, from the database; the service refuses regardless.
        $mayOverridePrice = Gate::allows('sales.edit');
        $maySellExpired = Gate::allows('inventory.void');
        $mayBackdate = Gate::allows('sales.edit');
        if ($this->lines === []) {
            // A second press queued behind a sale that just went through: nothing left to charge.
            if ($this->saved === null) {
                $this->addError('form', 'Add at least one item to the sale.');
            }

            return;
        }
        $this->saved = null;
        $this->staleUnit = null;

        // "Today" is the server's, never the browser's.
        $today = Clock::today();
        $businessDate = $mayBackdate && $this->pickedDate !== null ? $this->pickedDate : $today;
        $items = [];
        $tenders = [];
        try {
            SaleDate::assertAllowed($businessDate, $today, SettingsService::get()->books_locked_before, $mayBackdate);

            $index = 0;
            foreach ($this->lines as $line) {
                $index++;
                $items[] = [
                    'productId' => (int) $line['productId'],
                    'qty' => Quantity::parsePositive((string) $line['qty'], "Item {$index} quantity"),
                    'unitPrice' => Money::parse((string) $line['unitPrice']),
                    // The carton size this cart showed; a size changed since is refused, not re-converted.
                    ...Quantity::parseAltEntry($line['alt'] ? true : null, $line['altFactorMilli'] === null ? null : (int) $line['altFactorMilli'], "Item {$index}"),
                    ...(trim((string) $line['discount']) !== '' ? ['discount' => Money::parse((string) $line['discount'])] : []),
                ];
            }

            foreach ([[$this->tenderAccountId, $this->tenderAmount, $this->reference], [$this->secondAccountId, $this->secondAmount, '']] as [$account, $amount, $reference]) {
                if (trim($amount) === '' || $account === '') {
                    continue;
                }
                $minor = Money::parse($amount);
                if ($minor <= 0) {
                    continue;
                }
                $tenders[] = ['paymentAccountId' => (int) $account, 'amount' => $minor, ...(trim($reference) !== '' ? ['reference' => trim($reference)] : [])];
            }

            $discount = trim($this->invoiceDiscount) === '' ? 0 : Money::parse($this->invoiceDiscount);
            $overrideReason = mb_substr(trim($this->overrideReason), 0, 200);

            $result = SaleService::createSale([
                'businessDate' => $businessDate,
                'customerId' => $this->customerId === '' ? null : (int) $this->customerId,
                'items' => $items,
                'invoiceDiscount' => $discount,
                'tenders' => $tenders,
                ...(trim($this->note) !== '' ? ['note' => mb_substr(trim($this->note), 0, 300)] : []),
                'clientRef' => "{$this->cartSeed}-{$this->salesCompleted}",
                'allowPriceOverride' => $mayOverridePrice,
                // Tied to pressing "Sell it anyway", and to holding the right.
                'allowExpiredStock' => $sellExpired && $maySellExpired,
                // A backdated sale has its stock judged as it stands now, and takes only stock that had arrived by its own day.
                ...($businessDate !== $today ? ['expiryAsAt' => $today, 'arrivedBy' => $businessDate] : []),
                ...($overrideReason !== '' ? ['overrideReason' => $overrideReason] : []),
            ], $principal->actor());
        } catch (ExpiredStockError $e) {
            $this->expired = [
                'productName' => $e->details['productName'] ?? 'this product',
                'qtyExpired' => $e->details['qtyExpired'] ?? '',
                'batchRefs' => $e->details['batchRefs'] ?? [],
                'mayOverride' => $maySellExpired,
            ];

            return;
        } catch (StaleUnitError $e) {
            $this->correctStaleUnit($e->current);

            return;
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }

        $this->clearSale();
        $this->overrideReason = '';
        $this->saved = ['receiptNo' => $result['receiptNo'], 'saleId' => $result['saleId'], 'change' => $result['change']];
        // A new cart needs a new name, or the next sale would replay this one.
        $this->salesCompleted++;
    }

    /**
     * A carton size changed while the till was open: nothing was sold. The
     * product's lines take the new size and carton price, flagged for checking,
     * rather than emptying the cart with a customer waiting.
     */
    private function correctStaleUnit(array $stale): void
    {
        $this->staleUnit = $stale;
        foreach ($this->lines as $key => $line) {
            if ((int) $line['productId'] !== (int) $stale['productId']) {
                continue;
            }
            $this->lines[$key]['altUnit'] = $stale['altUnit'];
            $this->lines[$key]['altFactorMilli'] = $stale['altFactorMilli'];
            $this->lines[$key]['altSellingPrice'] = $stale['altSellingPriceMinor'];
            if ($line['alt']) {
                $this->lines[$key]['unitPrice'] = Money::toInputString((int) $stale['altSellingPriceMinor']);
                $this->lines[$key]['cartonWas'] = $stale['shownFactorMilli'];
            }
        }
    }

    // --- what the screen shows ------------------------------------------

    /** @return list<array> up to eight products for the search box */
    private function matches(): array
    {
        return JsCompat::trim($this->search) === '' ? [] : CatalogService::listProducts(['search' => $this->search, 'limit' => 8]);
    }

    private static function money(string $text): ?int
    {
        try {
            return trim($text) === '' ? 0 : Money::parse($text);
        } catch (DomainError) {
            return null;
        }
    }

    /**
     * The live figures: display only. Priced by the same domain functions the
     * server uses. A discount bigger than the sale is refused, not capped.
     */
    private function preview(): array
    {
        $invalid = false;
        $lineTotals = [];
        foreach ($this->lines as $key => $line) {
            $price = self::money((string) $line['unitPrice']);
            $discount = self::money((string) $line['discount']);
            try {
                $qty = Quantity::parse((string) $line['qty']);
            } catch (DomainError) {
                $qty = null;
            }
            if ($qty === null || $price === null || $discount === null) {
                $invalid = true;
                $lineTotals[$key] = null;

                continue;
            }
            $lineTotals[$key] = Money::subtract(Quantity::extendPrice($price, $qty), $discount);
        }
        $subtotal = Money::sum(array_filter($lineTotals, fn (?int $v): bool => $v !== null));

        $discount = self::money($this->invoiceDiscount);
        $discountTooLarge = $discount !== null && $discount > $subtotal;
        $invalid = $invalid || $discountTooLarge;
        $applied = $discount === null || $discountTooLarge ? 0 : $discount;
        $net = Money::atLeastZero(Money::subtract($subtotal, $applied));

        $profile = TaxService::profile();
        $taxLines = [];
        $tax = 0;
        try {
            $breakdown = $profile['inclusive'] ? TaxComponents::withinGross($net, $profile['components']) : TaxComponents::onNet($net, $profile['components']);
            $taxLines = $breakdown->lines;
            $tax = $breakdown->total;
        } catch (DomainError) {
            $invalid = true;
        }
        $total = $profile['inclusive'] ? $net : Money::add($net, $tax);
        $tendered = Money::add(self::money($this->tenderAmount) ?? 0, self::money($this->secondAmount) ?? 0);

        return [
            'lineTotals' => $lineTotals,
            'subtotal' => $subtotal,
            'discount' => $applied,
            'discountTooLarge' => $discountTooLarge,
            'taxLines' => $taxLines,
            'total' => $total,
            'tendered' => $tendered,
            'change' => Money::atLeastZero(Money::subtract($tendered, $total)),
            'outstanding' => Money::atLeastZero(Money::subtract($total, $tendered)),
            'invalid' => $invalid,
        ];
    }

    public function render(): View
    {
        $settings = SettingsService::get();
        $today = Clock::today();
        $totals = $this->preview();
        $accounts = PaymentAccountService::listPaymentAccounts();
        $kinds = collect($accounts)->pluck('kind', 'id')->all();

        // Per line: the stock and date situation, in one place.
        $status = [];
        foreach ($this->lines as $key => $line) {
            try {
                $entry = Quantity::parse((string) $line['qty']);
            } catch (DomainError) {
                $entry = 0;
            }
            $base = $line['alt'] && $line['altFactorMilli'] ? Quantity::scale($entry, (int) $line['altFactorMilli'], 1000) : $entry;
            $short = $line['trackInventory'] && $base > $line['qtyOnHandMilli'];
            $needsApproval = $line['trackInventory'] && (bool) $settings->expiry_blocks_sales && ! $short && $base > $line['goodQtyMilli'];
            $status[$key] = [
                'short' => $short,
                'needsApproval' => $needsApproval,
                'expiringSoon' => $line['soonestExpiry'] !== null && ! $needsApproval
                    && BusinessDate::daysBetween($today, $line['soonestExpiry']) <= $line['warnDays'],
            ];
        }
        $tenderKinds = [$kinds[(int) $this->tenderAccountId] ?? null, $kinds[(int) $this->secondAccountId] ?? null];
        $needsCustomer = $totals['outstanding'] > 0 && $this->customerId === '';

        return view('livewire.sales.pos', [
            'hasProducts' => CatalogService::countProducts() > 0,
            'matches' => $this->matches(),
            'totals' => $totals,
            'status' => $status,
            'overselling' => collect($status)->contains('short', true),
            'needsCustomer' => $needsCustomer,
            'canSubmit' => $this->lines !== [] && ! $totals['invalid'] && ! $needsCustomer,
            'accounts' => $accounts,
            'customers' => CustomerService::listCustomers(['limit' => 500]),
            'referencePlaceholder' => in_array('MOBILE_MONEY', $tenderKinds, true) ? 'MoMo transaction id' : (in_array('BANK', $tenderKinds, true) ? 'Cheque or transfer number' : ''),
            'currency' => $settings->currency_code ?? 'GHS',
            'today' => $today,
            'saleDate' => $this->pickedDate ?? $today,
            'lockedBefore' => $settings->books_locked_before,
            'mayOverridePrice' => Gate::allows('sales.edit'),
            'maySellExpired' => Gate::allows('inventory.void'),
            'mayBackdate' => Gate::allows('sales.edit'),
        ]);
    }
}
