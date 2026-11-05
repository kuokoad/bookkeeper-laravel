<?php

declare(strict_types=1);

/*
| ARCHITECTURE §2: the domain layer is pure. No database, no clock, no I/O,
| no framework. This reads every file in app/Domain, so it also catches the
| next file added. The financial correctness lives here, and it can only be
| tested exhaustively while it stays pure.
*/

it('keeps app/Domain free of the database, the clock and the framework', function () {
    $forbidden = [
        '/\buse\s+Illuminate\\\\/' => 'imports the framework',
        '/\bDB::/' => 'touches the database',
        '/\b(now|today|time|date|microtime|hrtime)\s*\(/' => 'reads the clock',
        '/new\s+\\\\?DateTime(Immutable)?\b/' => 'reads the clock',
        '/\b(file_get_contents|fopen|file_put_contents|curl_init)\s*\(/' => 'does I/O',
        '/\b(rand|mt_rand|random_int)\s*\(/' => 'is not deterministic',
    ];

    $problems = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Domain')));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        // Code only: strings and comments may mention a date without reading the clock.
        $code = '';
        foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        foreach ($forbidden as $pattern => $why) {
            if (preg_match($pattern, $code) === 1) {
                $problems[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())." {$why}";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('declares strict types in every domain file, so a float is never truncated into money', function () {
    $missing = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Domain')));
    foreach ($files as $file) {
        if ($file->getExtension() === 'php' && ! str_contains(file_get_contents($file->getPathname()), 'declare(strict_types=1);')) {
            $missing[] = $file->getFilename();
        }
    }

    expect($missing)->toBe([]);
});
