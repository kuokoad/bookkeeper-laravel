<?php

declare(strict_types=1);

namespace App\Livewire\Purchases;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Money;
use App\Domain\Purchases\ReadPurchase;
use App\Domain\Quantity;
use App\Services\CatalogService;
use App\Services\PaymentAccountService;
use App\Services\PurchaseService;
use App\Services\SettingsService;
use App\Services\SupplierService;
use App\Services\TaxService;
use App\Services\UnitsService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The old purchases/new page with purchase-entry.tsx: record a delivery. The
 * figures on screen come from ReadPurchase (the server's own parsers and
 * domain arithmetic); Save goes through PurchaseService::createPurchase,
 * which re-checks every one of them.
 */
#[Title('New purchase')]
final class Create extends Component
{
    public string $supplierId = '';

    public string $businessDate = '';

    /** When the supplier wants paying (D8): optional, empty is none. */
    public string $dueDate = '';

    public string $invoiceNo = '';

    public string $note = '';

    public string $invoiceDiscount = '';

    /** @var list<array{key: int, productId: string, qty: string, alt: bool, unitCost: string, costFilled: bool, costFor: string, costAlt: bool, discount: string, expiryDate: string, showExpiry: bool}> */
    public array $rows = [];

    public string $payAccountId = '';

    public string $payAmount = '';

    public string $reference = '';

    public ?string $savedNo = null;

    public ?int $savedId = null;

    public function mount(): void
    {
        $this->businessDate = Clock::today();
        $this->rows = [self::blankRow(1)];
        $accounts = PaymentAccountService::listPaymentAccounts();
        $default = collect($accounts)->firstWhere('isDefault', true) ?? ($accounts[0] ?? null);
        $this->payAccountId = $default === null ? '' : (string) $default['id'];
    }

    private static function blankRow(int $key): array
    {
        return ['key' => $key, 'productId' => '', 'qty' => '', 'alt' => false, 'unitCost' => '', 'costFilled' => false, 'costFor' => '', 'costAlt' => false, 'discount' => '', 'expiryDate' => '', 'showExpiry' => false];
    }

    /**
     * The products the form offers, with what it needs to check them: the old
     * entry-products.ts. Each unit's decimal places, so 1.5 pcs is refused
     * before Save rather than after.
     *
     * @return array<string, array>
     */
    #[Computed]
    public function products(): array
    {
        $decimals = [];
        foreach (UnitsService::listUnits() as $unit) {
            $decimals[$unit->symbol] = $unit->decimal_places;
        }
        $of = fn (string $symbol): int => $decimals[$symbol] ?? Quantity::DECIMALS;
        $products = [];
        // shortcut: the first 500 active products, as the old form; a shop with more needs a search box.
        foreach (CatalogService::listProducts(['limit' => 500]) as $p) {
            $products[(string) $p['id']] = [
                'id' => $p['id'], 'name' => $p['name'], 'unit' => $p['unit'], 'altUnit' => $p['altUnit'], 'altFactorMilli' => $p['altFactor'],
                'costPrice' => $p['costPrice'], 'altCostPrice' => $p['altCostPrice'], 'unitDecimals' => $of($p['unit']),
                'altUnitDecimals' => $p['altUnit'] === null ? null : $of($p['altUnit']), 'trackInventory' => $p['trackInventory'],
            ];
        }

        return $products;
    }

    /** The catalog cost to pre-fill, or nothing; never a 0.00 that looks typed and saves stock at no cost. */
    private function catalogCost(?array $product, bool $alt): string
    {
        $cost = $product === null ? null : ($alt ? $product['altCostPrice'] : $product['costPrice']);

        return $cost !== null && $cost > 0 ? Money::toInputString($cost) : '';
    }

    /** The old onChange handlers: a chosen product or unit pre-fills the cost; a typed cost stays and is flagged. */
    public function updatedRows(mixed $value, string $key): void
    {
        [$index, $field] = array_pad(explode('.', $key, 2), 2, null);
        if (! isset($this->rows[$index])) {
            return;
        }
        $row = &$this->rows[$index];
        $untouched = $row['costFilled'] || trim($row['unitCost']) === '';
        if ($field === 'productId') {
            $row['alt'] = false;
            if ($untouched) {
                $row['unitCost'] = $this->catalogCost($this->products[$row['productId']] ?? null, false);
                $row['costFilled'] = true;
                $row['costFor'] = $row['productId'];
                $row['costAlt'] = false;
            } elseif ($row['costFor'] === '') {
                // A cost typed before any product was chosen belongs to the first one chosen.
                $row['costFor'] = $row['productId'];
            }
        } elseif ($field === 'alt') {
            $row['alt'] = $value === true || $value === '1' || $value === 'alt';
            if ($untouched) {
                $row['unitCost'] = $this->catalogCost($this->products[$row['productId']] ?? null, $row['alt']);
                $row['costFilled'] = true;
                $row['costFor'] = $row['productId'];
                $row['costAlt'] = $row['alt'];
            }
        } elseif ($field === 'unitCost') {
            $row['costFilled'] = false;
            $row['costFor'] = $row['productId'];
            $row['costAlt'] = $row['alt'];
        }
    }

    public function addRow(): void
    {
        $this->rows[] = self::blankRow(max(0, ...array_column($this->rows, 'key')) + 1);
    }

    public function removeRow(int $index): void
    {
        if (count($this->rows) > 1) {
            unset($this->rows[$index]);
            $this->rows = array_values($this->rows);
        }
    }

    public function showExpiry(int $index): void
    {
        $this->rows[$index]['showExpiry'] = true;
    }

    public function clearExpiry(int $index): void
    {
        $this->rows[$index]['expiryDate'] = '';
        $this->rows[$index]['showExpiry'] = false;
    }

    public function payAll(): void
    {
        $total = $this->reading()['total'];
        if ($total !== null && $total !== 0) {
            $this->payAmount = Money::toInputString($total);
        }
    }

    private function reading(): array
    {
        return ReadPurchase::read($this->rows, $this->products, $this->invoiceDiscount, $this->payAmount, $this->payAccountId !== '',
            TaxService::profile()['components'], $this->businessDate, SettingsService::get()->books_locked_before, $this->invoiceNo, $this->reference);
    }

    /** The old createPurchaseAction: parse the basket as the server does, then save it. */
    public function save(): void
    {
        $this->authorize('purchases.create');
        $this->resetErrorBag();
        $this->savedNo = null;

        $reading = $this->reading();
        $fail = function (string $message): void {
            $this->addError('form', $message);
        };
        if (! ctype_digit($this->supplierId) || (int) $this->supplierId <= 0) {
            $fail('Choose a supplier.');

            return;
        }
        if (! Filters::isValidBusinessDate($this->businessDate) || ($this->dueDate !== '' && ! Filters::isValidBusinessDate($this->dueDate))) {
            $fail('Enter a valid date.');

            return;
        }
        if (mb_strlen(trim($this->invoiceNo)) > ReadPurchase::INVOICE_NO_MAX || mb_strlen(trim($this->note)) > ReadPurchase::PURCHASE_NOTE_MAX) {
            $fail('The purchase is incomplete.');

            return;
        }

        $items = [];
        $number = 0;
        try {
            foreach ($this->rows as $index => $row) {
                if ($reading['lines'][$index]['blank']) {
                    continue;
                }
                $number++;
                $product = $this->products[$row['productId']] ?? null;
                if ($product === null) {
                    $fail('Choose a product.');

                    return;
                }
                if ($row['expiryDate'] !== '' && ! Filters::isValidBusinessDate($row['expiryDate'])) {
                    $fail('Enter a valid date.');

                    return;
                }
                $items[] = [
                    'productId' => $product['id'],
                    'qty' => Quantity::parsePositive($row['qty'], "Line {$number} quantity"),
                    'unitCost' => Money::parse($row['unitCost']),
                    ...Quantity::parseAltEntry($row['alt'] ? true : null, $row['alt'] ? $product['altFactorMilli'] : null, "Line {$number}"),
                    ...(trim($row['discount']) !== '' ? ['discount' => Money::parse($row['discount'])] : []),
                    ...(trim($row['expiryDate']) !== '' ? ['expiryDate' => trim($row['expiryDate'])] : []),
                ];
            }
            if ($items === []) {
                $fail('Add at least one product to the purchase.');

                return;
            }

            $tenders = [];
            if (trim($this->payAmount) !== '' && $this->payAccountId !== '') {
                $amount = Money::parse($this->payAmount);
                if ($amount > 0) {
                    $tenders[] = ['paymentAccountId' => (int) $this->payAccountId, 'amount' => $amount, ...(trim($this->reference) !== '' ? ['reference' => trim($this->reference)] : [])];
                }
            }
            $discount = trim($this->invoiceDiscount) === '' ? 0 : Money::parse($this->invoiceDiscount);

            $result = PurchaseService::createPurchase([
                'supplierId' => (int) $this->supplierId,
                'businessDate' => $this->businessDate,
                'dueDate' => $this->dueDate === '' ? null : $this->dueDate,
                'invoiceNo' => trim($this->invoiceNo) === '' ? null : trim($this->invoiceNo),
                'items' => $items,
                'invoiceDiscount' => $discount,
                'tenders' => $tenders,
                'note' => trim($this->note) === '' ? null : trim($this->note),
            ], Principal::load((int) auth()->id())->actor());
        } catch (DomainError $e) {
            $fail($e->userMessage);

            return;
        }

        // Saved: keep the supplier and date for the next delivery, clear the rest.
        $this->savedNo = $result['purchaseNo'];
        $this->savedId = $result['purchaseId'];
        $this->rows = [self::blankRow(1)];
        $this->invoiceNo = $this->dueDate = $this->note = $this->invoiceDiscount = $this->payAmount = $this->reference = '';
        unset($this->products);
    }

    public function render(): View
    {
        $settings = SettingsService::get();
        $reading = $this->reading();
        $taxComponents = TaxService::profile()['components'];

        $blocker = match (true) {
            $this->supplierId === '' => 'Choose a supplier.',
            $reading['filled'] === 0 => 'Add what arrived.',
            ! $reading['acceptable'] => (ReadPurchase::fieldRefused($reading) ? null : $reading['totalError']) ?? 'Correct the entries that have a message under them.',
            default => null,
        };

        return view('livewire.purchases.create', [
            'suppliers' => SupplierService::listSuppliers(),
            'products' => $this->products,
            'accounts' => PaymentAccountService::listPaymentAccounts(),
            'currency' => $settings->currency_code ?? 'GHS',
            'lockedBefore' => $settings->books_locked_before,
            'offerExpiry' => SettingsService::features($settings)['expiry_batches'] ?? false,
            'taxComponents' => $taxComponents,
            'reading' => $reading,
            'blocker' => $blocker,
            'today' => Clock::today(),
        ]);
    }
}
