<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The one clock services read. Built on Carbon::now(), so a test can stop
 * time with Carbon::setTestNow().
 *
 * "Today" is the shop's day in config('nuna.business_timezone'), never the
 * server's; timestamps are stored in UTC.
 */
final class Clock
{
    public static function now(): Carbon
    {
        return Carbon::now();
    }

    /** The shop's business day, 'YYYY-MM-DD'. */
    public static function today(): string
    {
        return self::dateOf(Carbon::now());
    }

    /** The shop's business day that a moment falls on. */
    public static function dateOf(Carbon $at): string
    {
        return $at->copy()->setTimezone(config('nuna.business_timezone'))->format('Y-m-d');
    }

    /** A moment as a DATETIME(3) UTC column value. */
    public static function stamp(Carbon $at): string
    {
        return $at->copy()->setTimezone('UTC')->format('Y-m-d H:i:s.v');
    }

    /** A DATETIME(3) UTC column value read back as a moment. */
    public static function fromStamp(string $stamp): Carbon
    {
        return Carbon::parse($stamp, 'UTC');
    }
}
