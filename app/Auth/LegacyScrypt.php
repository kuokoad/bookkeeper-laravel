<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The old app's password and PIN hashes, `scrypt$N$r$p$<salt>$<hash>`
 * (base64url, no padding), checked exactly as its lib/auth/password.ts did:
 * the same parameter bounds, the same refusal of a salt or hash too short to
 * be real, the candidate NFKC-normalised, a timing-safe compare.
 *
 * Verify only. Nothing new is ever hashed this way: a legacy hash is replaced
 * with a Laravel one on that person's first successful sign-in (AuthService).
 *
 * PHP's sodium cannot take N, r and p, and wants a 32-byte salt, so this is
 * RFC 7914 scrypt in plain PHP: PBKDF2-HMAC-SHA256 (hash_pbkdf2) around
 * ROMix over Salsa20/8. Slow (a few seconds for N = 2^15), and that is paid
 * once per person.
 */
final class LegacyScrypt
{
    private const MASK = 0xFFFFFFFF;

    public static function isLegacy(?string $encoded): bool
    {
        return $encoded !== null && str_starts_with($encoded, 'scrypt$');
    }

    /**
     * @return array{N: int, r: int, p: int, salt: string, hash: string}|null null for anything that is not a usable hash
     */
    public static function decode(string $encoded): ?array
    {
        $parts = explode('$', $encoded);
        if (count($parts) !== 6 || $parts[0] !== 'scrypt') {
            return null;
        }
        [, $n, $r, $p, $salt, $hash] = $parts;
        foreach ([$n, $r, $p] as $number) {
            // Number() of the old code: whole numbers only.
            if (preg_match('/^\d+\z/', $number) !== 1) {
                return null;
            }
        }
        [$n, $r, $p] = [(int) $n, (int) $r, (int) $p];
        // Bounds from a tampered row are refused, not attempted.
        if ($n < 1024 || $n > 1_048_576 || $r < 1 || $r > 32 || $p < 1 || $p > 16) {
            return null;
        }
        // Node's scrypt throws for an N that is not a power of two; refused here instead.
        if (($n & ($n - 1)) !== 0) {
            return null;
        }
        // Work and memory, not each bound alone: the old app's own 2^15, 8, 1 is the most allowed.
        // Since p >= 1 this also caps ROMix's table, 128 * r * N bytes, at 32 MiB.
        if ($n * $r * $p > 32768 * 8) {
            return null;
        }

        $saltBytes = self::base64UrlDecode($salt);
        $hashBytes = self::base64UrlDecode($hash);
        // A blank or corrupt half once decoded to nothing and matched any password.
        if (strlen($saltBytes) < 8 || strlen($hashBytes) < 16 || strlen($hashBytes) > 256) {
            return null;
        }

        return ['N' => $n, 'r' => $r, 'p' => $p, 'salt' => $saltBytes, 'hash' => $hashBytes];
    }

    /** Whether the candidate matches; false (never an exception) for a malformed hash. */
    public static function verify(string $candidate, string $encoded): bool
    {
        $decoded = self::decode($encoded);
        if ($decoded === null) {
            return false;
        }
        $derived = self::derive(self::normalize($candidate), $decoded['salt'], $decoded['N'], $decoded['r'], $decoded['p'], strlen($decoded['hash']));

        return strlen($derived) === strlen($decoded['hash']) && hash_equals($decoded['hash'], $derived);
    }

    /** String.prototype.normalize('NFKC'), as the old code applied to every candidate. */
    public static function normalize(string $value): string
    {
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);

        return $normalized === false ? $value : $normalized;
    }

    /** RFC 7914 scrypt. */
    public static function derive(string $password, string $salt, int $n, int $r, int $p, int $length): string
    {
        $blockBytes = 128 * $r;
        $b = hash_pbkdf2('sha256', $password, $salt, 1, $p * $blockBytes, true);
        $mixed = '';
        for ($i = 0; $i < $p; $i++) {
            $mixed .= self::roMix(substr($b, $i * $blockBytes, $blockBytes), $n, $r);
        }

        return hash_pbkdf2('sha256', $password, $mixed, 1, $length, true);
    }

    private static function roMix(string $block, int $n, int $r): string
    {
        $words = 32 * $r;
        $last = $words - 16 + 1; // 1-based index of the last 64-byte block's first word
        $x = array_values(unpack('V*', $block));
        $v = [];
        for ($i = 0; $i < $n; $i++) {
            $v[$i] = pack('V*', ...$x);
            $x = self::blockMix($x, $r);
        }
        $mask = $n - 1;
        for ($i = 0; $i < $n; $i++) {
            // Integerify: the low word of the last block, mod N (a power of two).
            $j = $x[$last - 1] & $mask;
            $x = self::blockMix(array_values(unpack('V*', pack('V*', ...$x) ^ $v[$j])), $r);
        }

        return pack('V*', ...$x);
    }

    /**
     * @param  list<int>  $b  32r words
     * @return list<int>
     */
    private static function blockMix(array $b, int $r): array
    {
        $blocks = 2 * $r;
        $out = [];
        $start = ($blocks - 1) * 16;
        [$x0, $x1, $x2, $x3, $x4, $x5, $x6, $x7, $x8, $x9, $x10, $x11, $x12, $x13, $x14, $x15] = array_slice($b, $start, 16);

        for ($k = 0; $k < $blocks; $k++) {
            $o = $k * 16;
            $j0 = $x0 ^= $b[$o];
            $j1 = $x1 ^= $b[$o + 1];
            $j2 = $x2 ^= $b[$o + 2];
            $j3 = $x3 ^= $b[$o + 3];
            $j4 = $x4 ^= $b[$o + 4];
            $j5 = $x5 ^= $b[$o + 5];
            $j6 = $x6 ^= $b[$o + 6];
            $j7 = $x7 ^= $b[$o + 7];
            $j8 = $x8 ^= $b[$o + 8];
            $j9 = $x9 ^= $b[$o + 9];
            $j10 = $x10 ^= $b[$o + 10];
            $j11 = $x11 ^= $b[$o + 11];
            $j12 = $x12 ^= $b[$o + 12];
            $j13 = $x13 ^= $b[$o + 13];
            $j14 = $x14 ^= $b[$o + 14];
            $j15 = $x15 ^= $b[$o + 15];

            // Salsa20/8: four double rounds, each a column round then a row round.
            for ($round = 0; $round < 4; $round++) {
                $t = ($x0 + $x12) & self::MASK;
                $x4 ^= (($t << 7) | ($t >> 25)) & self::MASK;
                $t = ($x4 + $x0) & self::MASK;
                $x8 ^= (($t << 9) | ($t >> 23)) & self::MASK;
                $t = ($x8 + $x4) & self::MASK;
                $x12 ^= (($t << 13) | ($t >> 19)) & self::MASK;
                $t = ($x12 + $x8) & self::MASK;
                $x0 ^= (($t << 18) | ($t >> 14)) & self::MASK;

                $t = ($x5 + $x1) & self::MASK;
                $x9 ^= (($t << 7) | ($t >> 25)) & self::MASK;
                $t = ($x9 + $x5) & self::MASK;
                $x13 ^= (($t << 9) | ($t >> 23)) & self::MASK;
                $t = ($x13 + $x9) & self::MASK;
                $x1 ^= (($t << 13) | ($t >> 19)) & self::MASK;
                $t = ($x1 + $x13) & self::MASK;
                $x5 ^= (($t << 18) | ($t >> 14)) & self::MASK;

                $t = ($x10 + $x6) & self::MASK;
                $x14 ^= (($t << 7) | ($t >> 25)) & self::MASK;
                $t = ($x14 + $x10) & self::MASK;
                $x2 ^= (($t << 9) | ($t >> 23)) & self::MASK;
                $t = ($x2 + $x14) & self::MASK;
                $x6 ^= (($t << 13) | ($t >> 19)) & self::MASK;
                $t = ($x6 + $x2) & self::MASK;
                $x10 ^= (($t << 18) | ($t >> 14)) & self::MASK;

                $t = ($x15 + $x11) & self::MASK;
                $x3 ^= (($t << 7) | ($t >> 25)) & self::MASK;
                $t = ($x3 + $x15) & self::MASK;
                $x7 ^= (($t << 9) | ($t >> 23)) & self::MASK;
                $t = ($x7 + $x3) & self::MASK;
                $x11 ^= (($t << 13) | ($t >> 19)) & self::MASK;
                $t = ($x11 + $x7) & self::MASK;
                $x15 ^= (($t << 18) | ($t >> 14)) & self::MASK;

                $t = ($x0 + $x3) & self::MASK;
                $x1 ^= (($t << 7) | ($t >> 25)) & self::MASK;
                $t = ($x1 + $x0) & self::MASK;
                $x2 ^= (($t << 9) | ($t >> 23)) & self::MASK;
                $t = ($x2 + $x1) & self::MASK;
                $x3 ^= (($t << 13) | ($t >> 19)) & self::MASK;
                $t = ($x3 + $x2) & self::MASK;
                $x0 ^= (($t << 18) | ($t >> 14)) & self::MASK;

                $t = ($x5 + $x4) & self::MASK;
                $x6 ^= (($t << 7) | ($t >> 25)) & self::MASK;
                $t = ($x6 + $x5) & self::MASK;
                $x7 ^= (($t << 9) | ($t >> 23)) & self::MASK;
                $t = ($x7 + $x6) & self::MASK;
                $x4 ^= (($t << 13) | ($t >> 19)) & self::MASK;
                $t = ($x4 + $x7) & self::MASK;
                $x5 ^= (($t << 18) | ($t >> 14)) & self::MASK;

                $t = ($x10 + $x9) & self::MASK;
                $x11 ^= (($t << 7) | ($t >> 25)) & self::MASK;
                $t = ($x11 + $x10) & self::MASK;
                $x8 ^= (($t << 9) | ($t >> 23)) & self::MASK;
                $t = ($x8 + $x11) & self::MASK;
                $x9 ^= (($t << 13) | ($t >> 19)) & self::MASK;
                $t = ($x9 + $x8) & self::MASK;
                $x10 ^= (($t << 18) | ($t >> 14)) & self::MASK;

                $t = ($x15 + $x14) & self::MASK;
                $x12 ^= (($t << 7) | ($t >> 25)) & self::MASK;
                $t = ($x12 + $x15) & self::MASK;
                $x13 ^= (($t << 9) | ($t >> 23)) & self::MASK;
                $t = ($x13 + $x12) & self::MASK;
                $x14 ^= (($t << 13) | ($t >> 19)) & self::MASK;
                $t = ($x14 + $x13) & self::MASK;
                $x15 ^= (($t << 18) | ($t >> 14)) & self::MASK;
            }

            $x0 = ($x0 + $j0) & self::MASK;
            $x1 = ($x1 + $j1) & self::MASK;
            $x2 = ($x2 + $j2) & self::MASK;
            $x3 = ($x3 + $j3) & self::MASK;
            $x4 = ($x4 + $j4) & self::MASK;
            $x5 = ($x5 + $j5) & self::MASK;
            $x6 = ($x6 + $j6) & self::MASK;
            $x7 = ($x7 + $j7) & self::MASK;
            $x8 = ($x8 + $j8) & self::MASK;
            $x9 = ($x9 + $j9) & self::MASK;
            $x10 = ($x10 + $j10) & self::MASK;
            $x11 = ($x11 + $j11) & self::MASK;
            $x12 = ($x12 + $j12) & self::MASK;
            $x13 = ($x13 + $j13) & self::MASK;
            $x14 = ($x14 + $j14) & self::MASK;
            $x15 = ($x15 + $j15) & self::MASK;

            // Even blocks go to the first half of the output, odd ones to the second.
            $out[(($k & 1) * $r + ($k >> 1))] = [$x0, $x1, $x2, $x3, $x4, $x5, $x6, $x7, $x8, $x9, $x10, $x11, $x12, $x13, $x14, $x15];
        }
        ksort($out);

        return array_merge(...$out);
    }

    /** Buffer.from(s, 'base64url'): padding optional, stops quietly at the first character it cannot read. */
    private static function base64UrlDecode(string $value): string
    {
        $prefix = preg_match('/^[A-Za-z0-9_-]*/', $value, $m) === 1 ? $m[0] : '';
        // A lone trailing character carries no whole byte; Node drops it.
        if (strlen($prefix) % 4 === 1) {
            $prefix = substr($prefix, 0, -1);
        }
        $decoded = base64_decode(strtr($prefix, '-_', '+/'), false);

        return $decoded === false ? '' : $decoded;
    }
}
