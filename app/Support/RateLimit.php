<?php

declare(strict_types=1);

namespace App\Support;

use App\Database\Tx;
use Illuminate\Support\Facades\DB;

/**
 * Fixed-window rate limiter kept in the rate_limits table, so a restart, a
 * redeploy or a second PHP process cannot hand back a fresh allowance. A
 * port of the old lib/rate-limit.ts. Times are milliseconds since the epoch.
 *
 * Being refused does not extend the window, so somebody who keeps trying is
 * not chasing a deadline that never arrives.
 */
final class RateLimit
{
    /** @return array{allowed: bool, remaining: int, retryAfterMs: int} */
    public static function hit(string $key, int $limit, int $windowMs, ?int $nowMs = null): array
    {
        $nowMs ??= Clock::now()->getTimestampMs();
        $now = self::stamp($nowMs);
        $resetAt = self::stamp($nowMs + $windowMs);

        $row = Tx::run(function () use ($key, $now, $resetAt) {
            // The tidy-up rides along: sign-in is not a hot path.
            DB::table('rate_limits')->where('rate_limits.reset_at', '<=', $now)->delete();
            // One statement decides; its row lock holds until the read below commits with it.
            DB::statement(
                'INSERT INTO rate_limits (`key`, attempts, reset_at) VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE
                   attempts = IF(reset_at <= ?, 1, attempts + 1),
                   reset_at = IF(reset_at <= ?, ?, reset_at)',
                [$key, $resetAt, $now, $now, $resetAt],
            );

            return DB::table('rate_limits')->where('rate_limits.key', $key)->first();
        });

        $attempts = (int) $row->attempts;
        $allowed = $attempts <= $limit;
        $resetMs = Clock::fromStamp($row->reset_at)->getTimestampMs();

        return [
            'allowed' => $allowed,
            'remaining' => max(0, $limit - $attempts),
            'retryAfterMs' => $allowed ? 0 : max(0, $resetMs - $nowMs),
        ];
    }

    /** After a successful sign-in: a later typo must not start from a near-full counter. */
    public static function reset(string $key): void
    {
        Tx::run(fn () => DB::table('rate_limits')->where('rate_limits.key', $key)->delete());
    }

    public static function purgeExpired(?int $nowMs = null): int
    {
        $now = self::stamp($nowMs ?? Clock::now()->getTimestampMs());

        return Tx::run(fn (): int => DB::table('rate_limits')->where('rate_limits.reset_at', '<=', $now)->delete());
    }

    private static function stamp(int $ms): string
    {
        return gmdate('Y-m-d H:i:s', intdiv($ms, 1000)).'.'.str_pad((string) ($ms % 1000), 3, '0', STR_PAD_LEFT);
    }
}
