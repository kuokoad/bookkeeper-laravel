<?php

declare(strict_types=1);

/*
| Port of the old tests/lib/rate-limit.test.ts and http-throttle.test.ts,
| plus the client address rule. The old "survives a restart / a second
| process" cases rest on the counter living in the rate_limits table, which
| is asserted directly here; the routes that use the throttles (and the
| "permission checked first" structural case) arrive with them in Phase 9.
*/

use App\Auth\ClientAddress;
use App\Support\HttpThrottle;
use App\Support\RateLimit;
use Illuminate\Support\Facades\DB;

const RL_KEY = 'login:shared';
const RL_LIMIT = 5;
const RL_WINDOW = 15 * 60 * 1000;
const T0 = 1_770_000_000_000;

describe('counting attempts in a window', function () {
    it('allows exactly the limit, then refuses', function () {
        for ($attempt = 1; $attempt <= RL_LIMIT; $attempt++) {
            $result = RateLimit::hit(RL_KEY, RL_LIMIT, RL_WINDOW, T0);
            expect($result['allowed'])->toBeTrue()->and($result['remaining'])->toBe(RL_LIMIT - $attempt);
        }
        expect(RateLimit::hit(RL_KEY, RL_LIMIT, RL_WINDOW, T0))->toBe(['allowed' => false, 'remaining' => 0, 'retryAfterMs' => RL_WINDOW]);
    });

    it('does not extend the window when it refuses', function () {
        for ($i = 0; $i < RL_LIMIT; $i++) {
            RateLimit::hit(RL_KEY, RL_LIMIT, RL_WINDOW, T0);
        }
        $first = RateLimit::hit(RL_KEY, RL_LIMIT, RL_WINDOW, T0 + 1_000);
        $later = RateLimit::hit(RL_KEY, RL_LIMIT, RL_WINDOW, T0 + 60_000);
        expect($first['allowed'])->toBeFalse()->and($later['allowed'])->toBeFalse()
            ->and(T0 + 1_000 + $first['retryAfterMs'])->toBe(T0 + 60_000 + $later['retryAfterMs']);
    });

    it('starts again once the window has passed', function () {
        for ($i = 0; $i <= RL_LIMIT; $i++) {
            RateLimit::hit(RL_KEY, RL_LIMIT, RL_WINDOW, T0);
        }
        $after = RateLimit::hit(RL_KEY, RL_LIMIT, RL_WINDOW, T0 + RL_WINDOW + 1);
        expect($after['allowed'])->toBeTrue()->and($after['remaining'])->toBe(RL_LIMIT - 1);
    });

    it('counts each key separately', function () {
        for ($i = 0; $i <= RL_LIMIT; $i++) {
            RateLimit::hit(RL_KEY, RL_LIMIT, RL_WINDOW, T0);
        }
        expect(RateLimit::hit('login:1.2.3.4', RL_LIMIT, RL_WINDOW, T0)['allowed'])->toBeTrue();
    });
});

describe('signing in successfully', function () {
    it('clears the counter, so a later typo does not lock the till', function () {
        for ($i = 0; $i < RL_LIMIT - 1; $i++) {
            RateLimit::hit(RL_KEY, RL_LIMIT, RL_WINDOW, T0);
        }
        RateLimit::reset(RL_KEY);
        expect(RateLimit::hit(RL_KEY, RL_LIMIT, RL_WINDOW, T0)['remaining'])->toBe(RL_LIMIT - 1);
    });
});

describe('kept in the database', function () {
    it('lives in rate_limits, one row per key however many attempts', function () {
        for ($i = 0; $i < 20; $i++) {
            RateLimit::hit(RL_KEY, RL_LIMIT, RL_WINDOW, T0);
        }
        $row = DB::table('rate_limits')->first();
        expect(DB::table('rate_limits')->count())->toBe(1)
            ->and((int) $row->attempts)->toBe(20)
            ->and($row->reset_at)->toBe('2026-02-02 02:55:00.000');
    });
});

describe('throttling an expensive request', function () {
    it('lets an ordinary run of exports straight through, then stops it with a 429', function () {
        for ($i = 0; $i < HttpThrottle::EXPORT['limit']; $i++) {
            expect(HttpThrottle::check('export:1', HttpThrottle::EXPORT))->toBeNull();
        }
        expect(HttpThrottle::check('export:1', HttpThrottle::EXPORT)?->getStatusCode())->toBe(429);
    });

    it('says how long to wait, rounded UP', function () {
        $now = 1_000_000;
        for ($i = 0; $i < HttpThrottle::EXPORT['limit']; $i++) {
            HttpThrottle::check('export:1', HttpThrottle::EXPORT, $now);
        }
        $blocked = HttpThrottle::check('export:1', HttpThrottle::EXPORT, $now + HttpThrottle::EXPORT['windowMs'] - 1_500);
        expect($blocked->headers->get('Retry-After'))->toBe('2')
            ->and($blocked->getContent())->toContain('2 seconds');
    });

    it('gives every person their own allowance, and keeps exports and backups apart', function () {
        for ($i = 0; $i <= HttpThrottle::BACKUP['limit']; $i++) {
            HttpThrottle::check('backup:1', HttpThrottle::BACKUP);
        }
        expect(HttpThrottle::check('backup:1', HttpThrottle::BACKUP))->not->toBeNull()
            ->and(HttpThrottle::check('backup:2', HttpThrottle::BACKUP))->toBeNull()
            ->and(HttpThrottle::check('export:1', HttpThrottle::EXPORT))->toBeNull();
    });

    it('opens again once the window has passed', function () {
        $now = 2_000_000;
        for ($i = 0; $i <= HttpThrottle::EXPORT['limit']; $i++) {
            HttpThrottle::check('export:1', HttpThrottle::EXPORT, $now);
        }
        expect(HttpThrottle::check('export:1', HttpThrottle::EXPORT, $now + HttpThrottle::EXPORT['windowMs'] + 1))->toBeNull();
    });

    it('allows a shop to take its backups without meeting the limit', function () {
        for ($i = 0; $i < 3; $i++) {
            expect(HttpThrottle::check('backup:1', HttpThrottle::BACKUP))->toBeNull();
        }
    });
});

describe('the client address', function () {
    it('is unknown, and the throttle shared, unless proxy headers are trusted', function () {
        config(['nuna.trust_proxy_headers' => false]);
        expect(ClientAddress::fromHeaders('203.0.113.9', null))->toBeNull()
            ->and(ClientAddress::throttleKey('login', '203.0.113.9'))->toBe('login:shared');
    });

    it('behind a trusted proxy, takes the first forwarded address, else X-Real-IP', function () {
        config(['nuna.trust_proxy_headers' => true]);
        expect(ClientAddress::fromHeaders('203.0.113.9, 10.0.0.1', '10.0.0.2'))->toBe('203.0.113.9')
            ->and(ClientAddress::fromHeaders(null, ' 2001:db8::1 '))->toBe('2001:db8::1')
            ->and(ClientAddress::throttleKey('login', '203.0.113.9'))->toBe('login:203.0.113.9');
    });

    it('refuses anything that is not an IP address before it reaches a key or the audit log', function () {
        config(['nuna.trust_proxy_headers' => true]);
        foreach (["evil'; DROP TABLE users;--", str_repeat('9', 5000), '999.1.1.1', 'localhost'] as $forged) {
            expect(ClientAddress::fromHeaders($forged, null))->toBeNull();
        }
        expect(ClientAddress::throttleKey('login', null))->toBe('login:unknown');
    });
});
