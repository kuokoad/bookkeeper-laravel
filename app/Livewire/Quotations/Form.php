<?php

declare(strict_types=1);

namespace App\Livewire\Quotations;

use App\Auth\Principal;
use App\Domain\BusinessDate;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\QuotationService;
use App\Services\SettingsService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Writing a quote, new or changed: the old quotations/new, [id]/edit and
 * quote-editor.tsx, with the old quotation.actions parsing. The figures on
 * screen are a preview; QuotationService prices the quote with the same
 * function the till uses, and lines are submitted as typed.
 */
final class Form extends Component
{
    #[Locked]
    public ?int $quotationId = null;

    #[Locked]
    public string $quoteNo = '';

    public string $businessDate = '';

    public string $validUntil = '';

    public string $customerName = '';

    public string $customerId = '';

    public string $customerPhone = '';

    public string $reference = '';

    public string $notes = '';

    public string $quoteDiscount = '';

    /** @var array<int, array{productId: string, qty: string, alt: bool, unitPrice: string, discount: string, quoted: ?array}> */
    public array $lines = [];

    #[Locked]
    public int $nextKey = 1;

    public function mount(?int $id = null): void
    {
        if ($id === null) {
            $this->businessDate = Clock::today();
            // A month is the usual promise in a trade where prices move.
            $this->validUntil = BusinessDate::addDays($this->businessDate, 30);
            $this->addLine();

            return;
        }

        try {
            $quote = QuotationService::getQuotation($id);
        } catch (NotFoundError) {
            abort(404);
        }
        // A converted or cancelled quote is no longer an offer: nothing to fill in.
        if ($quote['status'] !== 'OPEN') {
            $this->redirect("/quotations/{$id}");

            return;
        }
        $this->quotationId = $id;
        $this->quoteNo = $quote['quoteNo'];
        $this->businessDate = $quote['businessDate'];
        $this->validUntil = $quote['validUntil'];
        $this->customerName = $quote['customerName'];
        $this->customerId = $quote['customerId'] === null ? '' : (string) $quote['customerId'];
        $this->customerPhone = $quote['customerPhone'] ?? '';
        $this->reference = $quote['reference'] ?? '';
        $this->notes = $quote['notes'] ?? '';
        // The discount AS TYPED, not the figure stored net of tax beside it.
        $this->quoteDiscount = $quote['quoteDiscountMinor'] > 0 ? Money::toInputString((int) $quote['quoteDiscountMinor']) : '';
        foreach ($quote['items'] as $item) {
            $this->lines[$this->nextKey++] = [
                'productId' => (string) $item['productId'],
                // As quoted: a line in Cartons comes back in Cartons.
                'qty' => Quantity::toInputString((int) ($item['entryQtyMilli'] ?? $item['qtyMilli'])),
                'alt' => $item['entryUnit'] !== null,
                'unitPrice' => Money::toInputString((int) $item['unitPriceMinor']),
                'discount' => $item['discountMinor'] > 0 ? Money::toInputString((int) $item['discountMinor']) : '',
                'quoted' => $item['entryUnit'] !== null && $item['entryFactorMilli'] !== null
                    ? ['unit' => $item['unit'], 'altUnit' => $item['entryUnit'], 'factorMilli' => (int) $item['entryFactorMilli']] : null,
            ];
        }
        if ($this->lines === []) {
            $this->addLine();
        }
    }

    /** @return array<string, array> active products by id */
    private function products(): array
    {
        return collect(CatalogService::listProducts(['limit' => 500]))->keyBy(fn (array $p): string => (string) $p['id'])->all();
    }

    public function addLine(): void
    {
        // Past every line on screen, so lines put back after a sign-in ended (keep-form.js) are never overwritten.
        $this->nextKey = max($this->nextKey, 1 + max([0, ...array_keys($this->lines)]));
        $this->lines[$this->nextKey++] = ['productId' => '', 'qty' => '', 'alt' => false, 'unitPrice' => '', 'discount' => '', 'quoted' => null];
    }

    public function removeLine(int $key): void
    {
        if (count($this->lines) > 1) {
            unset($this->lines[$key]);
        }
    }

    /** Typing a fresh name unlinks the picked customer, so the two cannot name different people. */
    public function updatedCustomerName(): void
    {
        $this->customerId = '';
    }

    public function updatedLines(mixed $value, ?string $path = null): void
    {
        if ($path === null) {
            return; // the whole form put back (keep-form.js), not one field typed
        }
        [$key, $field] = array_pad(explode('.', $path), 2, null);
        if ($field !== 'productId' || ! isset($this->lines[(int) $key])) {
            return;
        }
        // Pre-filled with the shelf price, and freely editable: quoting is negotiation.
        $product = $this->products()[(string) $value] ?? null;
        $this->lines[(int) $key] = [...$this->lines[(int) $key], 'alt' => false, 'quoted' => null,
            'unitPrice' => $product === null ? '' : Money::toInputString($product['sellingPrice'])];
    }

    public function setUnit(int $key, string $unit): void
    {
        $product = $this->products()[$this->lines[$key]['productId'] ?? ''] ?? null;
        if ($product === null || $product['altUnit'] === null) {
            return;
        }
        $alt = $unit === 'alt';
        $this->lines[$key]['alt'] = $alt;
        $this->lines[$key]['unitPrice'] = Money::toInputString($alt ? (int) ($product['altSellingPrice'] ?? 0) : $product['sellingPrice']);
    }

    public function save(): void
    {
        $this->authorize($this->quotationId === null ? 'quotations.create' : 'quotations.edit');

        $errors = [];
        if (! Filters::isValidBusinessDate($this->businessDate)) {
            $errors['businessDate'] = 'Enter a valid date.';
        }
        if (! Filters::isValidBusinessDate($this->validUntil)) {
            $errors['validUntil'] = 'Enter a date the price is good until.';
        }
        $name = trim($this->customerName);
        if ($name === '') {
            $errors['customerName'] = 'Say who the quote is for.';
        } elseif (mb_strlen($name) > 120) {
            $errors['customerName'] = 'Keep the name to 120 characters.';
        }
        $typed = array_values(array_filter($this->lines, fn (array $l): bool => $l['productId'] !== '' && trim($l['qty']) !== ''));
        if ($typed === []) {
            $errors['lines'] = 'Add at least one item to the quote.';
        }
        // A blank price is not GHS 0.00: the quote would convert to a sale that gives the goods away.
        foreach ($typed as $index => $line) {
            if (trim($line['unitPrice']) === '') {
                $errors['lines'] = 'Enter a price on line '.($index + 1).'.';
                break;
            }
        }
        if ($errors !== []) {
            foreach ($errors as $field => $message) {
                $this->addError($field, $message);
            }

            return;
        }
        $this->validate(['customerPhone' => ['max:40'], 'reference' => ['max:120'], 'notes' => ['max:500']]);

        $products = $this->products();
        try {
            $lines = [];
            foreach ($typed as $index => $line) {
                $lines[] = [
                    'productId' => (int) $line['productId'],
                    'qty' => Quantity::parse($line['qty']),
                    'unitPrice' => Money::parse($line['unitPrice']),
                    ...Quantity::parseAltEntry($line['alt'] ? true : null, $line['alt'] ? ($products[$line['productId']]['altFactor'] ?? null) : null, 'Line '.($index + 1)),
                    ...(trim($line['discount']) !== '' ? ['discount' => Money::parse($line['discount'])] : []),
                ];
            }
            $input = [
                'businessDate' => $this->businessDate,
                'validUntil' => $this->validUntil,
                'customerName' => $name,
                'customerId' => $this->customerId === '' ? null : (int) $this->customerId,
                'customerPhone' => trim($this->customerPhone) === '' ? null : trim($this->customerPhone),
                'reference' => trim($this->reference) === '' ? null : trim($this->reference),
                'notes' => trim($this->notes) === '' ? null : trim($this->notes),
                'lines' => $lines,
                ...(trim($this->quoteDiscount) !== '' ? ['quoteDiscount' => Money::parse($this->quoteDiscount)] : []),
            ];
            $actor = (Principal::load((int) auth()->id()) ?? abort(403))->actor();
            if ($this->quotationId === null) {
                $id = QuotationService::createQuotation($input, $actor)['quotationId'];
            } else {
                QuotationService::updateQuotation($this->quotationId, $input, $actor);
                $id = $this->quotationId;
                session()->flash('status', 'Quote saved.');
            }
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }
        $this->redirect("/quotations/{$id}");
    }

    private static function money(string $text): ?int
    {
        try {
            return trim($text) === '' ? 0 : Money::parse($text);
        } catch (DomainError) {
            return null;
        }
    }

    public function render(): View
    {
        $products = $this->products();
        // The preview: items less the quote discount, before tax. Tax is the server's.
        $lineTotals = [];
        $bad = false;
        foreach ($this->lines as $key => $line) {
            if ($line['productId'] === '') {
                continue;
            }
            $price = self::money($line['unitPrice']);
            $discount = self::money($line['discount']);
            try {
                $qty = Quantity::parse($line['qty']);
            } catch (DomainError) {
                $qty = null;
            }
            if ($qty === null || $price === null || $discount === null) {
                $bad = true;
                $lineTotals[$key] = null;

                continue;
            }
            $lineTotals[$key] = Money::subtract(Quantity::extendPrice($price, $qty), $discount);
        }
        $subtotal = Money::sum(array_filter($lineTotals, fn (?int $v): bool => $v !== null));
        $off = self::money($this->quoteDiscount) ?? 0;

        return view('livewire.quotations.form', [
            'products' => $products,
            'customers' => CustomerService::listCustomerOptions(),
            'currency' => SettingsService::get()->currency_code ?? 'GHS',
            'lineTotals' => $lineTotals,
            'subtotal' => $subtotal,
            'off' => $off,
            'beforeTax' => Money::atLeastZero(Money::subtract($subtotal, $off)),
            'bad' => $bad,
        ])->title($this->quotationId === null ? 'New quote' : 'Change quote');
    }
}
