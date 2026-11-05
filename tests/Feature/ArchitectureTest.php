<?php

declare(strict_types=1);

/*
| Rules that must hold in every file, not just today's. Ported in spirit from
| the old tests/db/transaction-behaviour.test.ts ("no service opens a
| transaction of its own") and tests/app/action-guards.test.ts.
*/

/** PHP source of app/, without comments or strings. */
function appCode(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = '';
        foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $files[str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())] = $code;
    }

    return $files;
}

it('opens a write transaction only through App\Database\Tx', function () {
    $offenders = [];
    foreach (appCode() as $path => $code) {
        if (str_ends_with($path, 'Database'.DIRECTORY_SEPARATOR.'Tx.php')) {
            continue;
        }
        if (preg_match('/DB::(transaction|beginTransaction|commit|rollBack)\s*\(|->(beginTransaction|commit|rollBack)\s*\(/', $code) === 1) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});

it('declares strict types in every PHP file under app/', function () {
    $missing = [];
    foreach (appCode() as $path => $code) {
        if (! str_contains($code, 'declare(strict_types=1);')) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([]);
});
