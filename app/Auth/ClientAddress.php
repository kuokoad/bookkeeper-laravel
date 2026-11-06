<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Http\Request;

/**
 * The caller's address for the audit log and the sign-in throttle, as the
 * old lib/auth/current-user.ts read it: from X-Forwarded-For / X-Real-IP only
 * when NUNA_TRUST_PROXY_HEADERS is on, otherwise unknown. New: the value must
 * parse as an IP address before it reaches rate_limits.key or
 * audit_logs.ip_address (the old app took it unchecked).
 */
final class ClientAddress
{
    public static function fromRequest(Request $request): ?string
    {
        return self::fromHeaders($request->headers->get('x-forwarded-for'), $request->headers->get('x-real-ip'));
    }

    public static function fromHeaders(?string $forwardedFor, ?string $realIp): ?string
    {
        if (config('nuna.trust_proxy_headers') !== true) {
            return null;
        }
        $first = trim(explode(',', (string) $forwardedFor)[0]);
        $candidate = $first !== '' ? $first : trim((string) $realIp);

        return filter_var($candidate, FILTER_VALIDATE_IP) === false ? null : $candidate;
    }

    /**
     * The sign-in ceiling's bucket (the looser one beside the per-username
     * bucket, D15). Without a trusted proxy there is no address worth
     * believing, so every attempt shares ONE bucket: not a limit anyone can
     * shrug off by sending a fresh header each time.
     */
    public static function throttleKey(string $prefix, ?string $address): string
    {
        if (config('nuna.trust_proxy_headers') !== true) {
            return "{$prefix}:shared";
        }

        return "{$prefix}:".($address ?? 'unknown');
    }
}
