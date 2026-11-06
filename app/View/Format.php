<?php

declare(strict_types=1);

namespace App\View;

use App\Domain\Money;
use App\Domain\Quantity;
use App\Services\InventoryService;
use Illuminate\Support\Carbon;

/**
 * Presentation of values the domain has ALREADY computed: a port of the old
 * lib/format.ts. Nothing here calculates money; a total that first appears in
 * a formatter is a total nobody has tested. Blade reaches it through the
 * money, qty and date directives (AppServiceProvider) or Format::money().
 */
final class Format
{
    /** "GHS 1,250.00", or "1,250.00" when bare (a column already headed GHS). */
    public static function money(int $value, bool $bare = false, string $currencyCode = 'GHS'): string
    {
        return $bare ? Money::toDecimalString($value) : Money::format($value, $currencyCode);
    }

    /** Negatives in accounting parentheses: "GHS (1,250.00)". */
    public static function moneyAccounting(int $value, bool $bare = false, string $currencyCode = 'GHS'): string
    {
        if ($value >= 0) {
            return self::money($value, $bare, $currencyCode);
        }

        return ($bare ? '' : $currencyCode.' ').'('.Money::toDecimalString(Money::absolute($value)).')';
    }

    /** "1.5 kg", or "1.5" without a unit. */
    public static function qty(int $value, ?string $unit = null): string
    {
        return $unit === null || $unit === '' ? Quantity::format($value) : Quantity::formatWithUnit($value, $unit);
    }

    /** A trading date "05 Aug 2026", as the old en-GB Intl format. */
    public static function date(string $businessDate): string
    {
        return InventoryService::formatDate($businessDate);
    }

    /** A UTC timestamp shown in the shop's day: "05 Aug 2026, 02:30 pm", as the old formatDateTime. */
    public static function dateTime(Carbon|string $at): string
    {
        $local = ($at instanceof Carbon ? $at->copy() : Carbon::parse($at, 'UTC'))->setTimezone((string) config('nuna.business_timezone'));

        return self::date($local->format('Y-m-d')).', '.$local->format('h:i a');
    }

    public static function plural(int $count, string $singular, ?string $plural = null): string
    {
        return $count === 1 ? $singular : ($plural ?? $singular.'s');
    }
}
