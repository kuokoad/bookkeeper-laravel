<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Features a shop can switch on or off. Each business type has its own
 * defaults. Switching one off hides screens; it never moves a figure. A port
 * of the old lib/business-type.ts FEATURES.
 */
enum Feature: string
{
    use HasValues;

    case ExpiryBatches = 'expiry_batches';
    case Quotations = 'quotations';

    public function label(): string
    {
        return match ($this) {
            self::ExpiryBatches => 'Expiry dates and batches',
            self::Quotations => 'Quotations',
        };
    }

    /** What it does, for the settings switch (the old lib/business-type.ts FEATURES blurb). */
    public function blurb(): string
    {
        return match ($this) {
            self::ExpiryBatches => 'Put a date on a delivery, be warned before it goes off, and stop the till selling stock that has passed it.',
            self::Quotations => 'Write a customer a priced quote they can take away, then turn it into a sale without typing it again.',
        };
    }

    /** What switching it off puts away (the old FEATURES "hides"). */
    public function hides(): string
    {
        return match ($this) {
            self::ExpiryBatches => 'the expiry date box on a delivery, the reminder setting on a product, and the "expiring soon" filter',
            self::Quotations => 'the Quotations menu entry and everything reached from it',
        };
    }

    /** The business_settings column that stores the switch. */
    public function column(): string
    {
        return 'feature_'.$this->value;
    }

    public function defaultFor(BusinessType $type): bool
    {
        return match ($this) {
            self::ExpiryBatches => $type !== BusinessType::BuildingMaterials,
            self::Quotations => $type !== BusinessType::GeneralRetail,
        };
    }

    /** @return array<string, bool> feature value => on */
    public static function defaults(BusinessType $type): array
    {
        $switches = [];
        foreach (self::cases() as $feature) {
            $switches[$feature->value] = $feature->defaultFor($type);
        }

        return $switches;
    }

    /**
     * The type's defaults, with any boolean the owner set laid over them.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, bool>
     */
    public static function resolve(BusinessType $type, array $overrides = []): array
    {
        $switches = self::defaults($type);
        foreach (self::cases() as $feature) {
            if (is_bool($overrides[$feature->value] ?? null)) {
                $switches[$feature->value] = $overrides[$feature->value];
            }
        }

        return $switches;
    }
}
