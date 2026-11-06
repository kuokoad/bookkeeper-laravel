<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Enums\AuditAction;
use App\Enums\BusinessType;
use App\Enums\Feature;
use App\Enums\Look;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The shop's settings: one row. A port of the old settings.service.ts.
 *
 * Currency cannot change once the books have entries: no amount stores its
 * own currency, so changing it would relabel history without touching a row.
 * The check runs inside the transaction, so a refused currency change rolls
 * back the whole save. A save that changes nothing writes no audit entry.
 */
final class SettingsService
{
    public const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    public static function get(): object
    {
        $settings = DB::table('business_settings')->where('id', 1)->first();
        if ($settings === null) {
            throw new NotFoundError('Business settings', 1);
        }

        return $settings;
    }

    public static function hasPostedTransactions(): bool
    {
        return DB::table('journal_entries')->exists();
    }

    /** @return array<string, bool> */
    public static function features(object $settings): array
    {
        $type = BusinessType::tryFrom($settings->business_type) ?? BusinessType::GeneralRetail;

        return Feature::resolve($type, [
            Feature::ExpiryBatches->value => (bool) $settings->feature_expiry_batches,
            Feature::Quotations->value => (bool) $settings->feature_quotations,
        ]);
    }

    /**
     * @param  array{businessName: string, tagline: ?string, address: ?string, phone: ?string, email: ?string, currencyCode: string, currencySymbol: string, look: Look, businessType: BusinessType, features: array<string, bool>, taxEnabled: bool, taxInclusive: bool, lowStockThresholdMilli: int, allowNegativeStock: bool, expiryWarningDays: int, expiryBlocksSales: bool, allowOverpayment: bool, defaultTermsDays: int, financialYearStartMonth: int}  $input
     */
    public static function update(array $input, Actor $actor): void
    {
        Tx::run(function () use ($input, $actor): void {
            $before = DB::table('business_settings')->where('id', 1)->lockForUpdate()->first();
            if ($before === null) {
                throw new NotFoundError('Business settings', 1);
            }

            if ($input['currencyCode'] !== $before->currency_code && self::hasPostedTransactions()) {
                throw new ValidationError(
                    "The currency cannot be changed from {$before->currency_code} once there are transactions — "
                    .'every amount already recorded is in that currency, and relabelling them would misstate '
                    .'your history. Start a new set of books to trade in a different currency.',
                    ['from' => $before->currency_code, 'to' => $input['currencyCode']],
                );
            }
            if ($input['expiryWarningDays'] < 0) {
                throw new ValidationError('The expiry warning period must be a whole number of days.', ['expiryWarningDays' => $input['expiryWarningDays']]);
            }

            // A new type stamps its own defaults over whatever the form sent.
            $features = $input['businessType']->value === $before->business_type
                ? Feature::resolve($input['businessType'], $input['features'])
                : Feature::defaults($input['businessType']);
            $beforeFeatures = self::features($before);
            $now = Carbon::now();

            DB::table('business_settings')->where('id', 1)->update([
                'business_name' => $input['businessName'],
                'tagline' => $input['tagline'],
                'address' => $input['address'],
                'phone' => $input['phone'],
                'email' => $input['email'],
                'currency_code' => $input['currencyCode'],
                'currency_symbol' => $input['currencySymbol'],
                'look' => $input['look']->value,
                'business_type' => $input['businessType']->value,
                'feature_expiry_batches' => $features[Feature::ExpiryBatches->value],
                'feature_quotations' => $features[Feature::Quotations->value],
                'tax_enabled' => $input['taxEnabled'],
                'tax_inclusive' => $input['taxInclusive'],
                'low_stock_threshold_milli' => $input['lowStockThresholdMilli'],
                'allow_negative_stock' => $input['allowNegativeStock'],
                'allow_overpayment' => $input['allowOverpayment'],
                'expiry_warning_days' => $input['expiryWarningDays'],
                'expiry_blocks_sales' => $input['expiryBlocksSales'],
                'default_terms_days' => $input['defaultTermsDays'],
                'financial_year_start_month' => $input['financialYearStartMonth'],
                'updated_at' => $now->format('Y-m-d H:i:s.v'),
            ]);

            $changes = array_filter([
                self::describeChange('Shop name', $before->business_name, $input['businessName']),
                self::describeChange('Tagline', $before->tagline, $input['tagline']),
                self::describeChange('Address', $before->address, $input['address']),
                self::describeChange('Phone', $before->phone, $input['phone']),
                self::describeChange('Email', $before->email, $input['email']),
                self::describeChange('Currency', $before->currency_code, $input['currencyCode']),
                self::describeChange('Currency symbol', $before->currency_symbol, $input['currencySymbol']),
                self::describeChange('Look', Look::from($before->look)->label(), $input['look']->label()),
                self::describeChange('Business type', BusinessType::from($before->business_type)->label(), $input['businessType']->label()),
                ...array_map(
                    fn (Feature $f): ?string => self::describeChange($f->label(), $beforeFeatures[$f->value], $features[$f->value]),
                    Feature::cases(),
                ),
                self::describeChange('Tax', (bool) $before->tax_enabled, $input['taxEnabled']),
                self::describeChange('Prices include tax', (bool) $before->tax_inclusive, $input['taxInclusive']),
                self::describeChange('Low stock level', self::units((int) $before->low_stock_threshold_milli), self::units($input['lowStockThresholdMilli'])),
                self::describeChange('Allow negative stock', (bool) $before->allow_negative_stock, $input['allowNegativeStock']),
                self::describeChange('Allow paying more than is owed', (bool) $before->allow_overpayment, $input['allowOverpayment']),
                self::describeChange('Payment terms', "{$before->default_terms_days} days", "{$input['defaultTermsDays']} days"),
                self::describeChange(
                    'Financial year starts',
                    self::MONTH_NAMES[$before->financial_year_start_month - 1],
                    self::MONTH_NAMES[$input['financialYearStartMonth'] - 1],
                ),
            ]);
            if ($changes === []) {
                return;
            }

            AuditService::write(AuditAction::SettingsChange, 'business_settings', implode('; ', $changes), entityId: 1, actor: $actor, at: $now);
        });
    }

    /**
     * First-run setup's half of the old setupOwnerAction, after the owner
     * account exists: the shop's name and type, the type's feature switches
     * written explicitly (the row already carries the column defaults, so
     * choosing Building materials and saying nothing more would leave every
     * feature on), and the moment setup finished. Not audited, as before.
     */
    public static function completeSetup(string $businessName, BusinessType $type): void
    {
        $features = Feature::defaults($type);
        $now = Carbon::now()->format('Y-m-d H:i:s.v');
        Tx::run(fn () => DB::table('business_settings')->where('id', 1)->update([
            'business_name' => $businessName,
            'business_type' => $type->value,
            'feature_expiry_batches' => $features[Feature::ExpiryBatches->value],
            'feature_quotations' => $features[Feature::Quotations->value],
            'setup_completed_at' => $now,
            'updated_at' => $now,
        ]));
    }

    /** @return array{hasLogo: bool, mime: ?string, width: ?int, height: ?int, bytes: int, updatedAt: ?string} */
    public static function logoSummary(): array
    {
        $s = self::get();

        return [
            'hasLogo' => $s->logo_data !== null,
            'mime' => $s->logo_mime,
            'width' => $s->logo_width,
            'height' => $s->logo_height,
            'bytes' => $s->logo_data === null ? 0 : strlen($s->logo_data),
            'updatedAt' => $s->logo_updated_at,
        ];
    }

    /** @return array{data: string, mime: string, updatedAt: ?string}|null */
    public static function logo(): ?array
    {
        $s = self::get();
        if ($s->logo_data === null || $s->logo_mime === null) {
            return null;
        }

        return ['data' => $s->logo_data, 'mime' => $s->logo_mime, 'updatedAt' => $s->logo_updated_at];
    }

    /**
     * Stored in the database, not on disk, so every backup carries it. The
     * bytes must already have passed the image check (type from magic bytes,
     * size and dimensions capped); that boundary arrives with the settings screen.
     */
    public static function setLogo(string $data, string $mime, int $width, int $height, Actor $actor): void
    {
        Tx::run(function () use ($data, $mime, $width, $height, $actor): void {
            $now = Carbon::now();
            DB::table('business_settings')->where('id', 1)->update([
                'logo_data' => $data,
                'logo_mime' => $mime,
                'logo_width' => $width,
                'logo_height' => $height,
                'logo_updated_at' => $now->format('Y-m-d H:i:s.v'),
                'updated_at' => $now->format('Y-m-d H:i:s.v'),
            ]);
            AuditService::write(AuditAction::SettingsChange, 'business_settings', "Logo updated ({$mime}, {$width}x{$height}, ".strlen($data).' bytes)', entityId: 1, actor: $actor, at: $now);
        });
    }

    public static function clearLogo(Actor $actor): void
    {
        Tx::run(function () use ($actor): void {
            $existing = DB::table('business_settings')->where('id', 1)->lockForUpdate()->value('logo_data');
            if ($existing === null) {
                return;
            }
            $now = Carbon::now();
            DB::table('business_settings')->where('id', 1)->update([
                'logo_data' => null,
                'logo_mime' => null,
                'logo_width' => null,
                'logo_height' => null,
                'logo_updated_at' => null,
                'updated_at' => $now->format('Y-m-d H:i:s.v'),
            ]);
            AuditService::write(AuditAction::SettingsChange, 'business_settings', 'Logo removed', entityId: 1, actor: $actor, at: $now);
        });
    }

    /** "Tax: off → on". Null when nothing changed. */
    private static function describeChange(string $label, mixed $before, mixed $after): ?string
    {
        if ($before === $after) {
            return null;
        }
        $show = fn (mixed $v): string => match (true) {
            $v === null, $v === '' => 'not set',
            is_bool($v) => $v ? 'on' : 'off',
            default => (string) $v,
        };

        return "{$label}: {$show($before)} → {$show($after)}";
    }

    /** Milli-units as the old app wrote them in the log: 5000 → "5", 2500 → "2.5". */
    private static function units(int $milli): string
    {
        return rtrim(rtrim(number_format($milli / 1000, 3, '.', ''), '0'), '.');
    }
}
