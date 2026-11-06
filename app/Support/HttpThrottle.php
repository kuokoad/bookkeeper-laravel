<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * A ceiling on how often one signed-in person can ask for something
 * expensive (an export, a backup, search-as-you-type), keyed on their user
 * id. Not the sign-in throttle. A port of the old lib/http-throttle.ts; the
 * routes that use it arrive in Phase 9, after their permission check.
 */
final class HttpThrottle
{
    /** Exports: somebody comparing months downloads a run of them. */
    public const EXPORT = ['limit' => 30, 'windowMs' => 60_000];

    /** Backups: each one dumps and verifies the whole database. */
    public const BACKUP = ['limit' => 6, 'windowMs' => 5 * 60_000];

    /** Search-as-you-type: a keystroke, not a report. */
    public const SEARCH = ['limit' => 240, 'windowMs' => 60_000];

    /** @param  array{limit: int, windowMs: int}  $throttle */
    public static function check(string $key, array $throttle, ?int $nowMs = null): ?Response
    {
        $result = RateLimit::hit($key, $throttle['limit'], $throttle['windowMs'], $nowMs);
        if ($result['allowed']) {
            return null;
        }

        // Rounded UP: rounding down would name a moment still inside the window.
        $seconds = (int) ceil($result['retryAfterMs'] / 1000);

        return new Response(
            'That is more requests than this can serve at once. Try again in '.$seconds.' second'.($seconds === 1 ? '' : 's').'.',
            429,
            ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => (string) $seconds, 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
