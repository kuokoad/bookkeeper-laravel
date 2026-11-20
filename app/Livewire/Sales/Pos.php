<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Auth\Principal;
use App\Auth\RoleGuard;
use App\Domain\BusinessDate;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\ExpiredStockError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\StaleUnitError;
use App\Domain\JsCompat;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Domain\Sales\SaleDate;
use App\Domain\Tax\TaxComponents;
use App\Enums\PaymentAccountKind;
use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\PaymentAccountService;
use App\Services\SaleService;
use App\Services\SettingsService;
use App\Services\TaxService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
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

    /** Opened as "New invoice": customer first, nothing taken, so it saves on credit. */
    #[Locked]
    public bool $invoiceMode = false;

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

    /** @var array{receiptNo: string, saleId: int, change: int, owing: int, customerName: ?string}|null */
    #[Locked]
    public ?array $saved = null;

    /** @var array{productName: string, qtyExpired: string, batchRefs: list<string>, mayOverride: bool}|null */
    #[Locked]
    public ?array $expired = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $staleUnit = null;

    /** D34: the sale the owner is editing in place, under its own receipt number. */
    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public string $editingNo = '';

    public string $editReason = '';

    public function mount(?string $id = null): void
    {
        $this->cartSeed = (string) Str::uuid();
        $this->invoiceMode = request()->boolean('invoice');
        $accounts = PaymentAccountService::listPaymentAccounts();
        $default = collect($accounts)->firstWhere('isDefault', true) ?? $accounts[0] ?? null;
        $this->tenderAccountId = $default === null ? '' : (string) $default['id'];
        if ($id !== null) {
            $this->loadForEdit((int) $id);
        }
    }

    /** The saved sale, in the till's own terms, so the owner edits what is there. */
    private function loadForEdit(int $saleId): void
    {
        abort_unless(RoleGuard::actorIsOwner((int) auth()->id()), 403);
        try {
            $sale = SaleService::getSale($saleId);
        } catch (NotFoundError) {
            abort(404);
        }
        abort_unless($sale['kind'] === 'SALE' && $sale['status'] === 'POSTED' && $sale['voidsSaleId'] === null, 404);

        $this->editingId = $saleId;
        $this->editingNo = $sale['receiptNo'];
        $this->customerId = $sale['customerId'] === null ? '' : (string) $sale['customerId'];
        $this->note = (string) ($sale['note'] ?? '');
        $this->pickedDate = $sale['businessDate'] === Clock::today() ? null : (string) $sale['businessDate'];
        // The discount as typed: what the lines came to, less what the sale was net of tax (D34).
        $netBeforeTax = $sale['taxInclusive'] ? (int) $sale['totalMinor'] : Money::subtract((int) $sale['totalMinor'], (int) $sale['taxMinor']);
        $typedDiscount = Money::subtract(Money::sum(array_map(fn (array $i): int => (int) $i['lineTotalMinor'], $sale['items'])), $netBeforeTax);
        $this->invoiceDiscount = $typedDiscount === 0 ? '' : Money::toInputString($typedDiscount);

        $this->lines = [];
        $this->nextKey = 1;
        $archived = [];
        // The whole shop's outlook, once: each line reads its own product from it.
        $outlook = InventoryService::getExpiryOutlook(Clock::today());
        foreach ($sale['items'] as $item) {
            // Archived products too: dropping the line would quietly change the sale's total on save.
            $product = CatalogService::getProduct((int) $item['productId']);
            if (! $product['isActive']) {
                $archived[] = $product['name'];
            }
            $alt = $item['entryUnit'] !== null && $item['entryQtyMilli'] !== null;
            $dates = $outlook[$product['id']] ?? null;
            $this->lines[$this->nextKey++] = [
                'productId' => $product['id'],
                'name' => $product['name'],
                'unit' => $product['unit'],
                'altUnit' => $product['altUnit'],
                'altFactorMilli' => $alt ? (int) $item['entryFactorMilli'] : $product['altFactor'],
                'alt' => $alt,
                'sellingPrice' => $product['sellingPrice'],
                'altSellingPrice' => $product['altSellingPrice'],
                'qty' => Quantity::toInputString((int) ($alt ? $item['entryQtyMilli'] : $item['qtyMilli'])),
                'unitPrice' => Money::toInputString((int) $item['unitPriceMinor']),
                'discount' => (int) $item['discountMinor'] === 0 ? '' : Money::toInputString((int) $item['discountMinor']),
                // This sale's own goods go back on the shelf before the edit takes them again.
                'qtyOnHandMilli' => Quantity::add($product['qtyOnHand'], $product['trackInventory'] ? (int) $item['qtyMilli'] : 0),
                'trackInventory' => $product['trackInventory'],
                'goodQtyMilli' => Quantity::add($dates['goodQtyMilli'] ?? 0, $product['trackInventory'] ? (int) $item['qtyMilli'] : 0),
                'soonestExpiry' => $dates['soonestExpiry'] ?? null,
                'warnDays' => $dates['warnDays'] ?? (int) (SettingsService::get()->expiry_warning_days ?? 30),
                'cartonWas' => null,
            ];
        }

        $paid = DB::table('sale_payments')->join('payment_accounts', 'payment_accounts.id', '=', 'sale_payments.payment_account_id')
            ->where('sale_payments.sale_id', $saleId)->orderBy('sale_payments.id')
            ->get(['sale_payments.payment_account_id', 'sale_payments.amount_minor', 'sale_payments.reference', 'payment_accounts.kind']);
        // What was handed over, not what was applied, or saving the edit would wipe the change (the receipt's rule).
        $change = (int) DB::table('sales')->where('sales.id', $saleId)->value('change_minor');
        $lastCash = $paid->keys()->filter(fn (int $i): bool => $paid[$i]->kind === PaymentAccountKind::Cash->value)->last();
        if ($change > 0 && $lastCash !== null) {
            $paid[$lastCash]->amount_minor = (int) $paid[$lastCash]->amount_minor + $change;
        }
        $this->tenderAmount = $this->secondAmount = $this->secondAccountId = $this->reference = '';
        if (isset($paid[0])) {
            $this->tenderAccountId = (string) $paid[0]->payment_account_id;
            $this->tenderAmount = Money::toInputString((int) $paid[0]->amount_minor);
            $this->reference = (string) ($paid[0]->reference ?? '');
        }
        if (isset($paid[1])) {
            $this->secondAccountId = (string) $paid[1]->payment_account_id;
            $this->secondAmount = Money::toInputString((int) $paid[1]->amount_minor);
        }
        if ($archived !== []) {
            $this->addError('form', '"'.implode('", "', $archived).'" has been archived since this sale, so it cannot be saved with it. Restore the stock item first, or void the sale and ring it up again.');
        }
        // The till takes two payments; a third would be lost on save.
        if (count($paid) > 2) {
            $this->addError('form', "{$this->editingNo} was paid from more than two accounts, which this screen cannot show, so it cannot be edited here. Void it and ring it up again.");
        }
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
        // Past every line on screen, so a cart put back after a sign-in ended (keep-form.js) is never overwritten.
        $this->nextKey = max($this->nextKey, 1 + max([0, ...array_keys($this->lines)]));
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

    /**
     * A barcode scanner types fast and presses Enter: an exact barcode or SKU is a scan, else the first match.
     * Enter passes the box's own value, because the debounced search may not have reached the server yet.
     */
    public function scan(?string $term = null): void
    {
        if ($term !== null) {
            $this->search = mb_substr($term, 0, 200);
        }
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
    public function updatedLines(mixed $value, ?string $path = null): void
    {
        if ($path === null) {
            return; // the whole form put back (keep-form.js), not one field typed
        }
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

            if ($this->editingId !== null) {
                $this->saveEdit($businessDate, $items, $tenders, $discount, $principal);

                return;
            }

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
                'productName' => $e->details['productName'] ?? 'this stock item',
                'qtyExpired' => $e->details['qtyExpired'] ?? '',
                'batchRefs' => $e->details['batchRefs'] ?? [],
                'mayOverride' => $maySellExpired,
            ];

            return;
        } catch (StaleUnitError $e) {
            $this->correctStaleUnit($e->current);

            return;
        } catch (DomainError $e) {
            // The cart on screen holds a sale already saved plus what was added since. It is cleared,
            // or one more press would charge the saved items a second time; what is left is a new sale.
            if (isset($e->details['voidedAs'])) {
                // The saved sale no longer stands, so the same cart is a new sale under a new name.
                $this->salesCompleted++;
            }
            if (isset($e->details['alreadySavedAs'])) {
                $this->clearSale();
                $this->overrideReason = '';
                $this->salesCompleted++;
                $this->addError('form', "This cart was already saved as {$e->details['alreadySavedAs']} before it was changed, so it was not charged again and has been cleared. Check that receipt, then ring up only the items it leaves out.");

                return;
            }
            $this->addError('form', $e->userMessage);

            return;
        }

        // D20: what the sale left owing, from the service, and on whose account (read before the cart clears).
        $owing = Money::atLeastZero($result['outstanding']);
        $customerName = $owing > 0 && $this->customerId !== '' ? CustomerService::getCustomer((int) $this->customerId)['name'] : null;
        $this->clearSale();
        $this->overrideReason = '';
        $this->saved = ['receiptNo' => $result['receiptNo'], 'saleId' => $result['saleId'], 'change' => $result['change'], 'owing' => $owing, 'customerName' => $customerName];
        // A new cart needs a new name, or the next sale would replay this one.
        $this->salesCompleted++;
        // The sale may have changed what a customer owes.
        unset($this->customers);
    }

    /** D34: the owner's corrected sale, posted over the old one under the same receipt number. */
    private function saveEdit(string $businessDate, array $items, array $tenders, int $discount, Principal $principal): void
    {
        abort_unless(RoleGuard::actorIsOwner($principal->id), 403);
        if (DB::table('sale_payments')->where('sale_id', $this->editingId)->count() > 2) {
            $this->addError('form', "{$this->editingNo} was paid from more than two accounts, which this screen cannot show, so it cannot be edited here. Void it and ring it up again.");

            return;
        }
        if (mb_strlen(trim($this->editReason)) < 3) {
            $this->addError('editReason', 'Say why this sale is being edited.');

            return;
        }
        SaleService::editSale($this->editingId, [
            'businessDate' => $businessDate,
            'customerId' => $this->customerId === '' ? null : (int) $this->customerId,
            'items' => $items,
            'invoiceDiscount' => $discount,
            'tenders' => $tenders,
            ...(trim($this->note) !== '' ? ['note' => mb_substr(trim($this->note), 0, 300)] : []),
        ], trim($this->editReason), $principal->actor());

        session()->flash('status', "{$this->editingNo} edited. The old sale was reversed and the corrected one posted under the same receipt number.");
        $this->redirect("/sales/{$this->editingId}");
    }

    /**
     * The customer list, as the old till's (200, the service default). Kept between
     * the requests of one till (so typing in the search does not reload it) and
     * read again after each sale.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed(persist: true)]
    public function customers(): array
    {
        $customers = CustomerService::listCustomers(['limit' => 200]);
        // An edited sale keeps its own customer, archived since or past the first 200: a list without them
        // would show "Walk-in" and drop them on save.
        if ($this->editingId !== null && $this->customerId !== '' && ! in_array((int) $this->customerId, array_column($customers, 'id'), true)) {
            $customers[] = CustomerService::getCustomer((int) $this->customerId);
        }

        return $customers;
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
            'customers' => $this->customers,
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
