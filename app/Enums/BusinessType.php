<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Decides which features a shop is offered. Never changes a figure.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum BusinessType: string
{
    use HasValues;

    case GeneralRetail = 'general_retail';
    case BuildingMaterials = 'building_materials';
    case Other = 'other';
}
