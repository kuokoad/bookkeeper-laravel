<?php

declare(strict_types=1);

namespace App\Auth;

use App\Domain\Errors\ValidationError;
use Illuminate\Support\Facades\Hash;

/**
 * Password and PIN hashing and policy: a port of the old lib/auth/password.ts.
 *
 * New hashes are Laravel's (argon2id, config/hashing.php), made from the
 * NFKC-normalised password as the old app's were. A stored `scrypt$...`
 * string is the old app's and is checked by LegacyScrypt until that person
 * next signs in (AuthService rehashes it then, inside the sign-in write).
 *
 * Passwords and PINs are never stored, logged or put in an error.
 */
final class Passwords
{
    public const MIN_PASSWORD_LENGTH = 8;

    public const MIN_PIN_LENGTH = 4;

    public const MAX_PIN_LENGTH = 8;

    private const FORBIDDEN_PASSWORDS = ['password', 'password1', 'password123', '12345678', '123456789', 'qwerty123', 'shop1234', 'admin123', 'letmein1', 'welcome1'];

    private static ?string $dummy = null;

    public static function hashPassword(string $password): string
    {
        self::assertPasswordAcceptable($password);

        return Hash::make(LegacyScrypt::normalize($password));
    }

    public static function hashPin(string $pin): string
    {
        self::assertPinAcceptable($pin);

        return Hash::make($pin);
    }

    /**
     * Whether the candidate matches the stored hash: never an exception, and
     * a hash nobody could match (missing, corrupt, another algorithm) still
     * costs a real check, so timing does not tell a guesser which it was.
     */
    public static function verify(string $candidate, ?string $stored): bool
    {
        if (LegacyScrypt::isLegacy($stored)) {
            if (LegacyScrypt::decode((string) $stored) !== null) {
                return LegacyScrypt::verify($candidate, (string) $stored);
            }
            self::burn();

            return false;
        }
        if ($stored === null || $stored === '' || password_get_info($stored)['algo'] === null) {
            self::burn();

            return false;
        }

        try {
            return Hash::check(LegacyScrypt::normalize($candidate), $stored);
        } catch (\RuntimeException) {
            // A hash from another algorithm than the configured one.
            return false;
        }
    }

    /** A legacy hash always; a Laravel hash when its cost settings have moved on. */
    public static function needsRehash(string $stored): bool
    {
        return LegacyScrypt::isLegacy($stored) || Hash::needsRehash($stored);
    }

    /** The same work as a real check, for an account that does not exist. */
    public static function burn(): void
    {
        self::$dummy ??= Hash::make('not-a-real-password-placeholder');
        Hash::check('not-the-placeholder', self::$dummy);
    }

    public static function assertPasswordAcceptable(string $password): void
    {
        $length = self::jsLength($password);
        if ($length < self::MIN_PASSWORD_LENGTH) {
            throw new ValidationError('Password must be at least '.self::MIN_PASSWORD_LENGTH.' characters long.');
        }
        if ($length > 200) {
            throw new ValidationError('Password must be 200 characters or fewer.');
        }
        if (in_array(mb_strtolower($password), self::FORBIDDEN_PASSWORDS, true)) {
            throw new ValidationError('That password is too common. Please choose a different one.');
        }
        if (preg_match('/^(.)\1+\z/u', $password) === 1) {
            throw new ValidationError('Password cannot be the same character repeated.');
        }
    }

    public static function assertPinAcceptable(string $pin): void
    {
        if (preg_match('/^[0-9]+\z/', $pin) !== 1) {
            throw new ValidationError('PIN must contain digits only.');
        }
        if (strlen($pin) < self::MIN_PIN_LENGTH || strlen($pin) > self::MAX_PIN_LENGTH) {
            throw new ValidationError('PIN must be between '.self::MIN_PIN_LENGTH.' and '.self::MAX_PIN_LENGTH.' digits.');
        }
        if (preg_match('/^(\d)\1+\z/', $pin) === 1) {
            throw new ValidationError('PIN cannot be the same digit repeated.');
        }
        if (preg_match('/^(0123|1234|2345|3456|4567|5678|6789|9876|8765|7654|6543|5432|4321|3210)/', $pin) === 1) {
            throw new ValidationError('PIN cannot be a simple sequence.');
        }
    }

    /** String.length: UTF-16 code units, so an emoji counts two, as the old limit did. */
    private static function jsLength(string $value): int
    {
        return intdiv(strlen((string) mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }
}
