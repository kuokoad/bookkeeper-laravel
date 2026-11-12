<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Quantity;
use App\Domain\Rate;
use App\Enums\BusinessType;
use App\Enums\Feature;
use App\Enums\Look;
use App\Enums\TaxBasis;
use App\Services\Actor;
use App\Services\CatalogService;
use App\Services\SettingsService;
use App\Services\TaxService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The shop's settings: the old (app)/settings page, settings-form.tsx and
 * tax-components.tsx, with the logo form posting to LogoController. Shape
 * checks are the old zod schema's, with its messages; every rule about
 * money and the books stays in SettingsService and TaxService.
 */
#[Title('Settings')]
final class Index extends Component
{
    /** @var array<string, mixed> the settings form, as typed */
    public array $form = [];

    /** @var array<string, mixed>|null the tax row being edited (with its id), or null */
    public ?array $tax = null;

    /** @var array<string, mixed>|null the "Add a tax" form while open */
    public ?array $newTax = null;

    public ?string $status = null;

    public ?string $taxStatus = null;

    public function mount(): void
    {
        $s = SettingsService::get();
        $this->form = [
            'businessName' => $s->business_name,
            'tagline' => $s->tagline ?? '',
            'address' => $s->address ?? '',
            'phone' => $s->phone ?? '',
            'email' => $s->email ?? '',
            'currencyCode' => $s->currency_code,
            'currencySymbol' => $s->currency_symbol,
            'look' => $s->look,
            'businessType' => $s->business_type,
            'features' => SettingsService::features($s),
            'taxEnabled' => (bool) $s->tax_enabled,
            'taxInclusive' => (bool) $s->tax_inclusive,
            'lowStock' => Quantity::toInputString((int) $s->low_stock_threshold_milli),
            'expiryWarningDays' => (string) $s->expiry_warning_days,
            'expiryBlocksSales' => (bool) $s->expiry_blocks_sales,
            'allowNegativeStock' => (bool) $s->allow_negative_stock,
            'allowOverpayment' => (bool) $s->allow_overpayment,
            'defaultTermsDays' => (string) $s->default_terms_days,
            'financialYearStartMonth' => (string) $s->financial_year_start_month,
        ];
    }

    /** A new type stamps its own feature defaults, as the server will. */
    public function updatedForm(mixed $value, ?string $key = null): void
    {
        if ($key === null) {
            return; // the whole form put back (keep-form.js), not one field typed
        }
        if ($key === 'businessType' && ($type = BusinessType::tryFrom((string) $value)) !== null) {
            $this->form['features'] = Feature::defaults($type);
        }
        if ($key === 'look') {
            $this->authorize('settings.edit');
            SettingsService::setLook(Look::tryFrom((string) $value) ?? Look::Default, $this->actor());
        }
    }

    public function save(): void
    {
        $this->authorize('settings.edit');
        $this->resetErrorBag();
        $this->status = null;
        $f = $this->form;
        $text = fn (string $key): string => trim((string) ($f[$key] ?? ''));
        $errors = [];

        $businessName = $text('businessName');
        if (mb_strlen($businessName) < 2) {
            $errors['businessName'] = 'Enter your shop name.';
        } elseif (mb_strlen($businessName) > 120) {
            $errors['businessName'] = 'Keep the shop name to 120 characters.';
        }
        foreach (['tagline' => 60, 'address' => 240, 'phone' => 40, 'email' => 160] as $key => $max) {
            if (mb_strlen($text($key)) > $max) {
                $errors[$key] = "Keep this to {$max} characters.";
            }
        }
        if ($text('email') !== '' && ! isset($errors['email']) && filter_var($text('email'), FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address, or leave it blank.';
        }
        $currencyCode = strtoupper($text('currencyCode'));
        if (preg_match('/^[A-Z]{3}$/D', $currencyCode) !== 1) {
            $errors['currencyCode'] = 'Use the three-letter code, like GHS or NGN.';
        }
        $currencySymbol = $text('currencySymbol');
        if ($currencySymbol === '') {
            $errors['currencySymbol'] = 'Enter the symbol shown on receipts.';
        } elseif (mb_strlen($currencySymbol) > 8) {
            $errors['currencySymbol'] = 'Keep the symbol to 8 characters.';
        }
        $businessType = BusinessType::tryFrom((string) ($f['businessType'] ?? ''));
        if ($businessType === null) {
            $errors['businessType'] = 'Choose the kind of business you run.';
        }

        // Parsed by the domain, so "what does 2.5 mean" has one answer.
        $lowStock = 0;
        try {
            $lowStock = Quantity::parse((string) ($f['lowStock'] ?? '0'));
            if ($lowStock < 0) {
                $errors['lowStock'] = 'A low stock level cannot be negative.';
            } elseif ($lowStock > 1_000_000_000) {
                $errors['lowStock'] = 'That low stock level is too large.';
            }
        } catch (DomainError $e) {
            $errors['lowStock'] = $e->userMessage;
        }
        $expiryWarningDays = $this->wholeNumber($f['expiryWarningDays'] ?? '30', 'expiryWarningDays', $errors, 'A warning period cannot be negative.', 'A warning further than a year ahead is not useful.');
        $defaultTermsDays = $this->wholeNumber($f['defaultTermsDays'] ?? '30', 'defaultTermsDays', $errors, 'Payment terms cannot be negative.', 'Payment terms longer than a year are not supported.');
        $month = $this->wholeNumber($f['financialYearStartMonth'] ?? '1', 'financialYearStartMonth', $errors, 'Choose the month your financial year starts.', 'Choose the month your financial year starts.', 1, 12);

        if ($errors !== []) {
            foreach ($errors as $key => $message) {
                $this->addError("form.{$key}", $message);
            }

            return;
        }

        $nullable = fn (string $key): ?string => $text($key) === '' ? null : $text($key);
        try {
            SettingsService::update([
                'businessName' => $businessName,
                'tagline' => $nullable('tagline'),
                'address' => $nullable('address'),
                'phone' => $nullable('phone'),
                'email' => $nullable('email'),
                'currencyCode' => $currencyCode,
                'currencySymbol' => $currencySymbol,
                // Paint: an unknown look falls back to the one the shop always had.
                'look' => Look::tryFrom((string) ($f['look'] ?? '')) ?? Look::Default,
                'businessType' => $businessType,
                'features' => array_map(fn ($on): bool => (bool) $on, (array) ($f['features'] ?? [])),
                'taxEnabled' => (bool) ($f['taxEnabled'] ?? false),
                'taxInclusive' => (bool) ($f['taxInclusive'] ?? false),
                'lowStockThresholdMilli' => $lowStock,
                'allowNegativeStock' => (bool) ($f['allowNegativeStock'] ?? false),
                'expiryWarningDays' => $expiryWarningDays,
                'expiryBlocksSales' => (bool) ($f['expiryBlocksSales'] ?? false),
                'allowOverpayment' => (bool) ($f['allowOverpayment'] ?? false),
                'defaultTermsDays' => $defaultTermsDays,
                'financialYearStartMonth' => $month,
            ], $this->actor());
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }

        // The shop name, look and currency are on every screen: reload it.
        session()->flash('status', 'Settings saved.');
        $this->redirect('/settings');
    }

    public function editTax(int $id): void
    {
        $row = TaxService::list()->firstWhere('id', $id);
        $this->taxStatus = null;
        $this->resetErrorBag();
        $this->tax = $row === null || ($this->tax['id'] ?? null) === $id ? null : [
            'id' => (int) $row->id,
            'name' => $row->name,
            'code' => $row->code,
            'rate' => Rate::format((int) $row->rate_bp),
            'basis' => $row->basis,
            'glAccountId' => (string) $row->gl_account_id,
            'sortOrder' => (string) $row->sort_order,
            'isRecoverable' => (bool) $row->is_recoverable,
            'isActive' => (bool) $row->is_active,
        ];
    }

    public function saveTax(): void
    {
        $this->authorize('settings.edit');
        $this->resetErrorBag();
        if ($this->tax === null) {
            return;
        }
        $input = $this->readTax($this->tax, 'tax');
        if ($input === null) {
            return;
        }
        try {
            TaxService::update((int) $this->tax['id'], [...$input, 'isActive' => (bool) $this->tax['isActive']], $this->actor());
        } catch (DomainError $e) {
            $this->addError('tax.form', $e->userMessage);

            return;
        }
        $this->taxStatus = trim($input['name']).' saved.';
        $this->tax = null;
    }

    public function openNewTax(): void
    {
        $this->newTax = ['name' => '', 'code' => '', 'rate' => '', 'basis' => TaxBasis::Net->value, 'glAccountId' => '', 'sortOrder' => '50', 'isRecoverable' => false];
    }

    public function closeNewTax(): void
    {
        $this->newTax = null;
        $this->resetErrorBag();
    }

    public function addTax(): void
    {
        $this->authorize('settings.edit');
        $this->resetErrorBag();
        if ($this->newTax === null) {
            return;
        }
        $input = $this->readTax($this->newTax, 'newTax');
        if ($input === null) {
            return;
        }
        try {
            TaxService::create($input, $this->actor());
        } catch (DomainError $e) {
            $this->addError('newTax.form', $e->userMessage);

            return;
        }
        $this->taxStatus = trim($input['name']).' added.';
        $this->newTax = null;
    }

    public function toggleTax(int $id): void
    {
        $this->authorize('settings.edit');
        $this->resetErrorBag();
        $row = TaxService::list()->firstWhere('id', $id);
        if ($row === null) {
            $this->addError("toggle.{$id}", 'That tax could not be found.');

            return;
        }
        try {
            TaxService::setActive($id, ! $row->is_active, $this->actor());
        } catch (DomainError $e) {
            $this->addError("toggle.{$id}", $e->userMessage);
        }
    }

    public function render(): View
    {
        $settings = SettingsService::get();
        $features = SettingsService::features($settings);
        $allIn = Rate::format(TaxService::allInRateBp());

        return view('livewire.settings.index', [
            'canEdit' => Gate::allows('settings.edit'),
            'currencyLocked' => SettingsService::hasPostedTransactions(),
            // Shown while dates are offered OR stock carries one: expiryBlocksSales can still refuse a sale.
            'showExpiry' => $features[Feature::ExpiryBatches->value] || CatalogService::hasDatedStock(),
            'savedFeatures' => $features,
            'logo' => SettingsService::logoSummary(),
            'taxRows' => TaxService::list()->map(fn (object $r): array => [
                'id' => (int) $r->id, 'name' => $r->name, 'code' => $r->code, 'rate' => Rate::format((int) $r->rate_bp),
                'basis' => $r->basis, 'isRecoverable' => (bool) $r->is_recoverable, 'isActive' => (bool) $r->is_active,
                'usage' => TaxService::usage((int) $r->id),
            ])->all(),
            'accounts' => TaxService::holdingAccounts(),
            'allIn' => $allIn,
            'months' => SettingsService::MONTH_NAMES,
        ]);
    }

    /** The old readForm: report against the field that is wrong. */
    private function readTax(array $values, string $prefix): ?array
    {
        $basis = TaxBasis::tryFrom((string) ($values['basis'] ?? ''));
        if ($basis === null) {
            $this->addError("{$prefix}.basis", 'Choose what the tax is charged on.');

            return null;
        }
        try {
            $rateBp = Rate::parsePercent((string) ($values['rate'] ?? ''));
        } catch (DomainError $e) {
            $this->addError("{$prefix}.rate", $e->userMessage);

            return null;
        }
        $account = trim((string) ($values['glAccountId'] ?? ''));
        if (preg_match('/^[1-9]\d{0,17}$/D', $account) !== 1) {
            $this->addError("{$prefix}.glAccountId", 'Choose the account this tax is held in.');

            return null;
        }
        $order = trim((string) ($values['sortOrder'] ?? ''));

        return [
            'code' => (string) ($values['code'] ?? ''),
            'name' => (string) ($values['name'] ?? ''),
            'rateBp' => $rateBp,
            'basis' => $basis,
            'isRecoverable' => (bool) ($values['isRecoverable'] ?? false),
            'glAccountId' => (int) $account,
            // shortcut: anything but a whole number is 0, as the old Number() fallback mostly was; a fraction too.
            'sortOrder' => preg_match('/^-?\d{1,9}$/D', $order) === 1 ? (int) $order : 0,
        ];
    }

    /** Number() of the old form: blank is 0; anything but a whole number is refused. */
    private function wholeNumber(mixed $raw, string $key, array &$errors, string $tooLow, string $tooHigh, int $min = 0, int $max = 365): int
    {
        $value = trim((string) $raw);
        if ($value === '') {
            $value = '0';
        }
        if (preg_match('/^-?\d{1,9}$/D', $value) !== 1) {
            $errors[$key] = 'Enter a whole number.';

            return 0;
        }
        $n = (int) $value;
        if ($n < $min) {
            $errors[$key] = $tooLow;
        } elseif ($n > $max) {
            $errors[$key] = $tooHigh;
        }

        return $n;
    }

    private function actor(): Actor
    {
        return Principal::load((int) auth()->id())->actor();
    }
}
