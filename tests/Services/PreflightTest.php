<?php

declare(strict_types=1);

/*
| Port of the old tests/db/preflight.test.ts and tests/services/
| preflight-money.test.ts. "Database is not corrupt" (SQLite's
| integrity_check) has no MySQL equivalent that can run inside a test's
| transaction: InnoDB checksums its own pages, and CHECK TABLE commits.
*/

use App\Enums\UserRole;
use App\Services\AuthService;
use App\Services\PreflightService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

function preflightCheck(string $name): array
{
    foreach (PreflightService::run() as $check) {
        if ($check['name'] === $name) {
            return $check;
        }
    }
    throw new RuntimeException("No preflight check named \"{$name}\". Has it been renamed?");
}

it('passes the structural checks on a healthy database', function () {
    Shop::postOpeningCapital();
    foreach (['Database exists', 'No broken references', 'Migrations applied', 'The books balance', 'Stock matches its movement history', 'Every unit of stock belongs to a batch'] as $name) {
        expect(preflightCheck($name)['status'])->toBe('pass', $name);
    }
});

it('passes the MySQL checks a backup and a migration rely on', function () {
    // Warns exactly when this server has the binary log on and log_bin_trust_function_creators off.
    $server = DB::selectOne('SELECT @@log_bin AS b, @@log_bin_trust_function_creators AS t');
    $expected = (int) $server->b === 1 && (int) $server->t === 0 ? 'warn' : 'pass';
    expect(preflightCheck('Migrations can create triggers')['status'])->toBe($expected)
        ->and(preflightCheck('No scratch databases left over')['status'])->toBe('pass');
});

it('writes a balanced total as money, grouped', function () {
    Shop::postOpeningCapital(amount: 1_234_567_89);
    expect(preflightCheck('The books balance'))->toBe(['name' => 'The books balance', 'status' => 'pass', 'detail' => 'debits and credits both 1,234,567.89']);
});

it('BLOCKS when the books do not balance, naming the difference as money', function () {
    Shop::postOpeningCapital();
    $entryId = (int) DB::table('journal_entries')->max('id');
    DB::table('journal_lines')->insert(['entry_id' => $entryId, 'account_id' => Shop::accountId('1001'), 'debit_minor' => 600_000, 'credit_minor' => 0, 'line_no' => 99]);

    $check = preflightCheck('The books balance');
    expect($check['status'])->toBe('fail')
        ->and($check['detail'])->toContain('debits 6,500.00')
        ->and($check['detail'])->toContain('credits 500.00')
        ->and($check['detail'])->toContain('a difference of 6,000.00')
        ->and($check['detail'])->toContain('Do NOT trade on these books')
        ->and($check['detail'])->not->toContain('600000');
});

it('BLOCKS when nobody can sign in, and passes once an active owner exists', function () {
    expect(preflightCheck('An owner can sign in')['status'])->toBe('fail');
    AuthService::createUser(['username' => 'kwame', 'displayName' => 'Kwame Owusu', 'password' => 'owner-password-2026', 'role' => UserRole::Owner]);
    expect(preflightCheck('An owner can sign in')['status'])->toBe('pass');
});

it('BLOCKS on demo accounts when the database really was demo-seeded', function () {
    AuthService::createUser(['username' => 'owner', 'displayName' => 'Demo Owner', 'password' => 'demo-owner-2026', 'role' => UserRole::Owner]);
    DB::table('business_settings')->where('id', 1)->update(['has_demo_data' => true]);
    $check = preflightCheck('No demo accounts');
    expect($check['status'])->toBe('fail')->and($check['detail'])->toContain('README');
});

it('does NOT block a real shop whose own user happens to be called "owner"', function () {
    AuthService::createUser(['username' => 'owner', 'displayName' => 'Kwame Owusu', 'password' => 'a-real-password-2026', 'role' => UserRole::Owner]);
    $check = preflightCheck('No demo accounts');
    expect($check['status'])->toBe('warn')->and($check['detail'])->toContain('your own');
});

it('BLOCKS on demo records left in the books, and passes a clean database', function () {
    expect(preflightCheck('No demo records in the books')['status'])->toBe('pass');
    Shop::product(Shop::owner(), 'Demo Milo', 0, 1_000, ['isDemo' => true]);
    DB::table('products')->update(['is_demo' => true]);
    $check = preflightCheck('No demo records in the books');
    expect($check['status'])->toBe('fail')->and($check['detail'])->toContain('1 demo record');
});

it('BLOCKS when a product disagrees with its own stock history', function () {
    $actor = Shop::owner();
    $milo = Shop::product($actor, 'Milo', 500, 800);
    Shop::openingStock($actor, $milo, 10_000, 5_000);
    DB::table('products')->where('id', $milo)->update(['qty_on_hand_milli' => 9_000]);

    expect(preflightCheck('Stock matches its movement history'))->toMatchArray(['status' => 'fail'])
        ->and(preflightCheck('Stock matches its movement history')['detail'])->toContain('Milo');
});

it('warns about configuration a shop should decide on, and fails demo seeding', function () {
    config(['app.env' => 'local', 'nuna.seed_demo_data' => true, 'nuna.trust_proxy_headers' => true]);
    expect(preflightCheck('Running in production mode')['status'])->toBe('warn')
        ->and(preflightCheck('Demo seeding is off')['status'])->toBe('fail')
        ->and(preflightCheck('Forwarded headers are not blindly trusted')['status'])->toBe('warn');
    config(['app.env' => 'production', 'app.debug' => false]);
    expect(preflightCheck('Running in production mode')['status'])->toBe('pass');
});

it('finds mysqldump for backups', function () {
    expect(preflightCheck('Backups can be taken')['status'])->toBe('pass');
});

it('reads and never writes', function () {
    DB::enableQueryLog();
    PreflightService::run();
    foreach (DB::getQueryLog() as $q) {
        expect(strtolower(ltrim($q['query'])))->toMatch('/^(select|show)/');
    }
});

it('passes when the built screen assets are there, and BLOCKS when they are missing or incomplete', function () {
    expect(preflightCheck('Screen styles and scripts are built')['status'])->toBe('pass');

    $public = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nuna-public-'.bin2hex(random_bytes(4));
    mkdir($public.'/build/assets', recursive: true);
    $real = public_path();
    app()->usePublicPath($public);
    try {
        expect(preflightCheck('Screen styles and scripts are built'))->toMatchArray(['status' => 'fail', 'detail' => 'Missing from public/build: manifest.json. Every screen would load unstyled. Run `npm run build` on the dev PC; the built files ship with each release.']);

        // Only the CSS entry was built, and its file was never copied across.
        file_put_contents($public.'/build/manifest.json', json_encode(['resources/css/app.css' => ['file' => 'assets/app-x.css', 'isEntry' => true]]));
        $check = preflightCheck('Screen styles and scripts are built');
        expect($check['status'])->toBe('fail')
            ->and($check['detail'])->toContain('resources/js/app.js')
            ->and($check['detail'])->toContain('assets/app-x.css');
    } finally {
        app()->usePublicPath($real);
        array_map('unlink', glob($public.'/build/*.json') ?: []);
        rmdir($public.'/build/assets');
        rmdir($public.'/build');
        rmdir($public);
    }
});
