<?php

declare(strict_types=1);

namespace App\Livewire\Products;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Services\Actor;
use App\Services\CatalogService;
use App\Services\SettingsService;
use App\Services\UnitsService;

/**
 * The old product-form.tsx fields and catalog.actions.ts readProductForm /
 * parseProductNumbers, shared by add, edit and replace. Text in, the
 * service's product input out; money and quantities go through the domain
 * parsers, never by hand.
 */
trait ProductFields
{
    public string $name = '';

    public string $sku = '';

    public string $barcode = '';

    public string $categoryId = '';

    public string $unit = '';

    public string $altUnit = '';

    public string $altFactor = '';

    public string $altCostPrice = '';

    public string $altSellingPrice = '';

    public string $costPrice = '0.00';

    public string $sellingPrice = '0.00';

    public string $minStock = '';

    public string $warnDays = '';

    public bool $trackInventory = true;

    /** The old productFormValues(): a saved product as the form shows it. */
    protected function fillFromProduct(array $product): void
    {
        $this->name = $product['name'];
        $this->sku = (string) $product['sku'];
        $this->barcode = (string) $product['barcode'];
        $this->categoryId = $product['categoryId'] === null ? '' : (string) $product['categoryId'];
        $this->unit = $product['unit'];
        $this->altUnit = (string) $product['altUnit'];
        $this->altFactor = $product['altFactor'] === null ? '' : Quantity::toInputString($product['altFactor']);
        $this->altCostPrice = $product['storedAltCostPrice'] === null ? '' : Money::toInputString($product['storedAltCostPrice']);
        $this->altSellingPrice = $product['storedAltSellingPrice'] === null ? '' : Money::toInputString($product['storedAltSellingPrice']);
        $this->costPrice = Money::toInputString($product['costPrice']);
        $this->sellingPrice = Money::toInputString($product['sellingPrice']);
        $this->minStock = $product['minStock'] === null ? '' : Quantity::toInputString($product['minStock']);
        $this->warnDays = $product['warnDays'] === null ? '' : (string) $product['warnDays'];
        $this->trackInventory = $product['trackInventory'];
    }

    /**
     * The service input, or null with the field errors added.
     *
     * @param  array<string, string>  $extraErrors  more field errors to report alongside these
     */
    protected function productInput(array $extraErrors = []): ?array
    {
        $this->validate([
            'name' => 'required|string|max:160',
            'sku' => 'nullable|string|max:60',
            'barcode' => 'nullable|string|max:60',
            'unit' => 'required|string|max:20',
            'altUnit' => 'nullable|string|max:20',
            'costPrice' => 'required|string',
            'sellingPrice' => 'required|string',
        ], [
            'name.required' => 'Enter a product name.',
            'unit.required' => 'Choose a unit.',
            'costPrice.required' => 'Enter a cost price.',
            'sellingPrice.required' => 'Enter a selling price.',
        ]);

        $errors = $extraErrors;
        $parse = function (string $field, callable $parser) use (&$errors): ?int {
            try {
                return $parser(trim($this->{$field}));
            } catch (DomainError $e) {
                $errors[$field] = $e->userMessage;

                return null;
            }
        };

        $cost = $parse('costPrice', Money::parse(...));
        $selling = $parse('sellingPrice', Money::parse(...));
        $minStock = trim($this->minStock) === '' ? null : $parse('minStock', Quantity::parse(...));

        $warnDays = null;
        $days = trim($this->warnDays);
        if ($days !== '') {
            // As the old Number(text): "5.0" is five days, "2.5" or "-1" is refused.
            if (! is_numeric($days) || (float) $days < 0 || floor((float) $days) !== (float) $days) {
                $errors['warnDays'] = 'Enter a whole number of days, or leave it blank.';
            } elseif ((float) $days > 3_650) {
                $errors['warnDays'] = 'A warning further than ten years ahead is not useful.';
            } else {
                $warnDays = (int) $days;
            }
        }

        // The alternate unit: "1 Carton = 24 pcs", with optional prices of its own.
        $altUnit = trim($this->altUnit);
        $altFactor = $altCost = $altSelling = null;
        if ($altUnit !== '') {
            $altFactor = $parse('altFactor', fn (string $t): int => Quantity::parsePositive($t, 'The conversion'));
            $altCost = trim($this->altCostPrice) === '' ? null : $parse('altCostPrice', Money::parse(...));
            $altSelling = trim($this->altSellingPrice) === '' ? null : $parse('altSellingPrice', Money::parse(...));
        }

        if ($errors !== []) {
            foreach ($errors as $field => $message) {
                $this->addError($field, $message);
            }

            return null;
        }

        return [
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'categoryId' => ctype_digit($this->categoryId) ? (int) $this->categoryId : null,
            'unit' => trim($this->unit),
            'onlyListedUnits' => true,
            'altUnit' => $altUnit === '' ? null : $altUnit,
            'altFactor' => $altFactor,
            'altCostPrice' => $altCost,
            'altSellingPrice' => $altSelling,
            'costPrice' => $cost,
            'sellingPrice' => $selling,
            'minStock' => $minStock,
            'warnDays' => $warnDays,
            'trackInventory' => $this->trackInventory,
        ];
    }

    protected function actor(): Actor
    {
        return Principal::load((int) auth()->id())->actor();
    }

    /** What every product form shows besides its fields. */
    protected function formOptions(bool $allCategories): array
    {
        $settings = SettingsService::get();

        return [
            'categories' => CatalogService::listCategories($allCategories),
            'units' => UnitsService::listUnits(),
            'currency' => $settings->currency_code ?? 'GHS',
            // Hidden, never removed: an absent field would clear warnDays on every save.
            'offerExpiry' => SettingsService::features($settings)['expiry_batches'],
        ];
    }
}
