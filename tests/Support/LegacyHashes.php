<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use RuntimeException;

/**
 * Hashes the OLD app made: its demo users in tests/Fixtures/old-demo.db
 * (written by its own migrations and seed), and tests/Fixtures/legacy-scrypt.json
 * (its hashPassword/hashPin run under Node, see make-legacy-scrypt.mts).
 */
final class LegacyHashes
{
    public static function oldDemo(string $username, string $column): string
    {
        $pdo = new PDO('sqlite:'.base_path('tests/Fixtures/old-demo.db'), options: [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $statement = $pdo->prepare("SELECT {$column} FROM users WHERE username = ?");
        $statement->execute([$username]);

        return (string) $statement->fetchColumn();
    }

    /** @return array{kind: string, secret: string, hash: string, alias: ?string, aliasVerifies: ?bool} */
    public static function node(string $secret): array
    {
        foreach (json_decode((string) file_get_contents(base_path('tests/Fixtures/legacy-scrypt.json')), true, flags: JSON_THROW_ON_ERROR) as $case) {
            if ($case['secret'] === $secret) {
                return $case;
            }
        }
        throw new RuntimeException("no fixture for {$secret}");
    }
}
