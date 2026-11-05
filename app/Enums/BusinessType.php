<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Decides which features a shop is offered. Never changes a figure: it only
 * hides menu items. Labels are the old lib/business-type.ts.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum BusinessType: string
{
    use HasValues;

    case GeneralRetail = 'general_retail';
    case BuildingMaterials = 'building_materials';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::GeneralRetail => 'General retail',
            self::BuildingMaterials => 'Building materials',
            self::Other => 'Something else',
        };
    }

    public function blurb(): string
    {
        return match ($this) {
            self::GeneralRetail => 'A provisions shop, a mini-mart, a chemical shop. Everything switched on.',
            self::BuildingMaterials => 'Cement, pipes, roofing, hardware. Nothing carries a date, so dates and batches are put away.',
            self::Other => 'Shows every feature. Choose this if neither of the others quite fits.',
        };
    }
}
