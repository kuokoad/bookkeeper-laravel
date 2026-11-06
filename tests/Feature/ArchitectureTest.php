<?php

declare(strict_types=1);

/*
| Rules that must hold in every file, not just today's. Ported in spirit from
| the old tests/db/transaction-behaviour.test.ts ("no service opens a
| transaction of its own") and tests/app/action-guards.test.ts.
*/

use App\Services\PreflightService;

/**
 * PHP source under these folders, keyed by path, without comments; string
 * contents are dropped too unless $keepStrings.
 *
 * @param  list<string>  $roots
 * @return array<string, string>
 */
function phpSource(array $roots, bool $keepStrings = false): array
{
    $drop = $keepStrings ? [T_COMMENT, T_DOC_COMMENT] : [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];
    $files = [];
    foreach ($roots as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = '';
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], $drop, true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            $files[str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())] = $code;
        }
    }

    return $files;
}

/** Everything that runs against the live books: the app, migrations and seeders, and routes. */
function writerRoots(): array
{
    return [app_path(), database_path(), base_path('routes')];
}

/** @return list<string> files outside Tx.php that match */
function transactionOffenders(string $pattern, bool $keepStrings): array
{
    $offenders = [];
    foreach (phpSource(writerRoots(), $keepStrings) as $path => $code) {
        if (! str_ends_with($path, 'app'.DIRECTORY_SEPARATOR.'Database'.DIRECTORY_SEPARATOR.'Tx.php') && preg_match($pattern, $code) === 1) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

it('opens a write transaction only through App\Database\Tx', function () {
    // DB::transaction(...), DB::connection('x')->transaction(...), $pdo->beginTransaction() and the like.
    expect(transactionOffenders('/DB::(transaction|beginTransaction|commit|rollBack)\s*\(|->(transaction|beginTransaction|commit|rollBack)\s*\(/i', false))->toBe([]);
});

it('sends no raw START TRANSACTION, COMMIT or ROLLBACK to MySQL', function () {
    // Upper case only: SQL keywords as written here, not the word "commit" in a message.
    expect(transactionOffenders('/\b(START\s+TRANSACTION|BEGIN\s+WORK|COMMIT|ROLLBACK)\b/', true))->toBe([]);
});

it('declares strict types in every PHP file under app/', function () {
    $missing = [];
    foreach (phpSource([app_path()]) as $path => $code) {
        if (! str_contains($code, 'declare(strict_types=1);')) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([]);
});

it('ships a complete build: every file the manifest names, for every vite.config.js input', function () {
    // Whether the build matches the sources is `npm run build:check` (a rebuild that changes nothing).
    expect(PreflightService::missingAssets())->toBe([]);
    foreach (PreflightService::ENTRY_POINTS as $entry) {
        expect((string) file_get_contents(base_path('vite.config.js')))->toContain("'{$entry}'");
    }
});
