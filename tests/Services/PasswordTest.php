<?php

declare(strict_types=1);

/*
| Port of the old tests/lib/password.test.ts, plus the legacy scrypt check:
| RFC 7914's own vectors, hashes the OLD app made (tests/Fixtures/old-demo.db,
| written by its migrations and demo seed) and hashes made by running its
| hashPassword/hashPin with Node (tests/Fixtures/legacy-scrypt.json, from
| make-legacy-scrypt.mts). Each legacy check at N = 2^15 takes seconds in
| plain PHP, so there are few of them.
*/

use App\Auth\LegacyScrypt;
use App\Auth\Passwords;
use App\Domain\Errors\ValidationError;
use Tests\Support\LegacyHashes;

describe('hashPassword / verify', function () {
    it('verifies a correct password and rejects a wrong or empty one', function () {
        $hash = Passwords::hashPassword('kwame-shop-2026');
        expect(Passwords::verify('kwame-shop-2026', $hash))->toBeTrue()
            ->and(Passwords::verify('kwame-shop-2027', $hash))->toBeFalse()
            ->and(Passwords::verify('', $hash))->toBeFalse();
    });

    it('never stores the password in the hash', function () {
        $hash = Passwords::hashPassword('my-secret-passphrase');
        expect($hash)->not->toContain('my-secret-passphrase')
            ->and(strtolower($hash))->not->toContain('secret');
    });

    it('produces a different hash each time (unique salt), both verifying', function () {
        $a = Passwords::hashPassword('same-password-here');
        $b = Passwords::hashPassword('same-password-here');
        expect($a)->not->toBe($b)
            ->and(Passwords::verify('same-password-here', $a))->toBeTrue()
            ->and(Passwords::verify('same-password-here', $b))->toBeTrue();
    });

    it('makes new hashes with argon2id, never the legacy scheme', function () {
        // Differs from the old test, which expected scrypt$...: see README.
        expect(Passwords::hashPassword('kwame-shop-2026'))->toStartWith('$argon2id$');
    });

    it('handles unicode passwords consistently, normalising NFKC as the old app did', function () {
        $hash = Passwords::hashPassword('Adwoa-Ɔsɛe-2026');
        expect(Passwords::verify('Adwoa-Ɔsɛe-2026', $hash))->toBeTrue();

        $ligature = Passwords::hashPassword("of\u{FB01}ce-\u{FF12}026");
        expect(Passwords::verify('office-2026', $ligature))->toBeTrue();
    });

    it('returns false rather than throwing on a corrupt stored hash', function () {
        foreach (['', 'not-a-hash', 'scrypt$1$2$3', 'bcrypt$1$2$3$abc$def', 'scrypt$99$8$1$YWJj$ZGVm'] as $corrupt) {
            expect(Passwords::verify('anything', $corrupt))->toBeFalse();
        }
        expect(Passwords::verify('anything', null))->toBeFalse();
    });

    it('refuses tampered parameters that would exhaust memory', function () {
        expect(Passwords::verify('anything', 'scrypt$99999999$8$1$YWJjZGVmZ2hpamtsbW5vcA$ZGVm'))->toBeFalse()
            ->and(LegacyScrypt::decode('scrypt$32769$8$1$YWJjZGVmZ2hpamtsbW5vcA$'.str_repeat('A', 86)))->toBeNull();
    });

    it('refuses quickly a hash whose bounds pass one by one but whose work does not (N=2^20, r=32, p=16)', function () {
        $hash = 'scrypt$1048576$32$16$YWJjZGVmZ2hpamtsbW5vcA$'.str_repeat('A', 86);
        $started = microtime(true);
        expect(LegacyScrypt::decode($hash))->toBeNull()
            ->and(Passwords::verify('anything', $hash))->toBeFalse()
            ->and(microtime(true) - $started)->toBeLessThan(1.0)
            // The old app's own parameters still decode.
            ->and(LegacyScrypt::decode('scrypt$32768$8$1$YWJjZGVmZ2hpamtsbW5vcA$'.str_repeat('A', 86)))->not->toBeNull()
            ->and(LegacyScrypt::decode('scrypt$32768$8$2$YWJjZGVmZ2hpamtsbW5vcA$'.str_repeat('A', 86)))->toBeNull();
    });
});

describe('needsRehash', function () {
    it('is false for a fresh hash and true for every legacy hash and for junk', function () {
        expect(Passwords::needsRehash(Passwords::hashPassword('kwame-shop-2026')))->toBeFalse()
            ->and(Passwords::needsRehash('scrypt$16384$8$1$YWJj$ZGVm'))->toBeTrue()
            ->and(Passwords::needsRehash(LegacyHashes::oldDemo('owner', 'password_hash')))->toBeTrue()
            ->and(Passwords::needsRehash('garbage'))->toBeTrue();
    });
});

describe('password policy', function () {
    it('accepts a reasonable password', function () {
        Passwords::assertPasswordAcceptable('kwame-shop-2026');
        expect(true)->toBeTrue();
    });

    it('rejects short, common and degenerate passwords', function (string $password) {
        expect(fn () => Passwords::assertPasswordAcceptable($password))->toThrow(ValidationError::class);
    })->with(['short1', 'password123', 'PASSWORD123', 'aaaaaaaaaa', str_repeat('x', 201)]);

    it('counts length as the old app did, in UTF-16 units', function () {
        // Four emoji are eight UTF-16 units: long enough for the old rule.
        Passwords::assertPasswordAcceptable('😀🙂😀🙂');
        expect(fn () => Passwords::assertPasswordAcceptable('😀🙂😀'))->toThrow(ValidationError::class);
    });
});

describe('PIN handling', function () {
    it('hashes and verifies a PIN', function () {
        $hash = Passwords::hashPin('8351');
        expect(Passwords::verify('8351', $hash))->toBeTrue()
            ->and(Passwords::verify('8352', $hash))->toBeFalse();
    });

    it('rejects weak PINs', function (string $pin) {
        expect(fn () => Passwords::assertPinAcceptable($pin))->toThrow(ValidationError::class);
    })->with(['1111', '1234', '4321', '12', '123456789', '12a4', "8351\n"]);

    it('accepts a non-obvious PIN', function () {
        Passwords::assertPinAcceptable('8351');
        Passwords::assertPinAcceptable('905172');
        expect(true)->toBeTrue();
    });
});

describe('a stored legacy hash that is not a real hash', function () {
    // A blank or truncated hash part once derived nothing and matched ANY password.
    $salt = rtrim(strtr(base64_encode('0123456789abcdef'), '+/', '-_'), '=');

    it('refuses an EMPTY hash portion rather than accepting anything', function () use ($salt) {
        expect(Passwords::verify('anything at all', "scrypt\$32768\$8\$1\${$salt}\$"))->toBeFalse()
            ->and(Passwords::verify('', "scrypt\$32768\$8\$1\${$salt}\$"))->toBeFalse();
    });

    it('refuses a hash portion that is not valid base64url', function () use ($salt) {
        expect(Passwords::verify('anything at all', "scrypt\$32768\$8\$1\${$salt}\$***"))->toBeFalse();
    });

    it('refuses a hash too short to be one', function () use ($salt) {
        expect(Passwords::verify('anything at all', "scrypt\$32768\$8\$1\${$salt}\$c2hvcnQ"))->toBeFalse();
    });

    it('refuses a missing salt', function () {
        $hash = rtrim(strtr(base64_encode(str_repeat("\x07", 64)), '+/', '-_'), '=');
        expect(Passwords::verify('anything at all', "scrypt\$32768\$8\$1\$\${$hash}"))->toBeFalse();
    });
});

describe('LegacyScrypt is RFC 7914 scrypt', function () {
    it('matches the RFC test vectors', function (string $password, string $salt, int $n, int $r, int $p, string $hex) {
        expect(bin2hex(LegacyScrypt::derive($password, $salt, $n, $r, $p, 64)))->toBe(str_replace(' ', '', $hex));
    })->with([
        'empty' => ['', '', 16, 1, 1, '77d6576238657b203b19ca42c18a0497f16b4844e3074ae8dfdffa3fede21442 fcd0069ded0948f8326a753a0fc81f17e8d3e0fb2e0d3628cf35e20c38d18906'],
        'p = 16' => ['password', 'NaCl', 1024, 8, 16, 'fdbabe1c9d3472007856e7190d01e9fe7c6ad7cbc8237830e77376634b373162 2eaf30d92e22a3886ff109279d9830dac727afb94a83ee6d8360cbdfa2cc0640'],
        'N = 2^14' => ['pleaseletmein', 'SodiumChloride', 16384, 8, 1, '7023bdcb3afd7348461c06cd81fd38ebfda8fbba904f8e3ea9b543f6545da1f2 d5432955613f0fcf62d49705242a9af9e61e85dc0d651e40dfcf017b45575887'],
    ]);
});

describe('hashes the old app made', function () {
    it('accepts the demo owner password stored by the old app, and nothing else', function () {
        $stored = LegacyHashes::oldDemo('owner', 'password_hash');
        expect($stored)->toStartWith('scrypt$32768$8$1$')
            ->and(Passwords::verify('demo-owner-2026', $stored))->toBeTrue()
            ->and(Passwords::verify('demo-owner-2027', $stored))->toBeFalse();
    });

    it('accepts the demo till PIN stored by the old app', function () {
        expect(Passwords::verify('8351', LegacyHashes::oldDemo('ama', 'pin_hash')))->toBeTrue();
    });

    it('accepts a unicode password hashed by the old code under Node', function () {
        $case = LegacyHashes::node('Adwoa-Ɔsɛe-2026');
        expect(Passwords::verify($case['secret'], $case['hash']))->toBeTrue();
    });

    it('normalises the candidate NFKC exactly as the old verify did', function () {
        $case = LegacyHashes::node("of\u{FB01}ce-\u{FF12}026-cafe\u{0301}");
        // Node said the plain spelling verifies; so must this.
        expect($case['aliasVerifies'])->toBeTrue()
            ->and(Passwords::verify($case['alias'], $case['hash']))->toBeTrue();
    });
});
