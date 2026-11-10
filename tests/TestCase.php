<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;

require_once __DIR__.'/Processes/run.php';

abstract class TestCase extends BaseTestCase
{
    /** Every database test starts from the seeded baseline, as the old tests/helpers did. */
    protected bool $seed = true;

    /** This run's own database, made on the first test (see ownDatabase). */
    private static ?string $database = null;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $configured = $app['config']->get('database.connections.mysql');
        // Only the phpunit.xml default is swapped: DB_DATABASE=... on the command line still wins.
        if (self::$database !== null || $configured['database'] === 'nunabooks_test') {
            self::$database ??= self::ownDatabase($configured);
            $app['config']->set('database.connections.mysql.database', self::$database);
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Pages render without a built public/build/manifest.json.
        $this->withoutVite();
    }

    /**
     * nunabooks_test_run_<pid>: two runs on one MySQL server (two agents, a run in
     * a terminal) never migrate, seed or truncate each other's tables. Dropped
     * when this run ends; what a killed run left behind goes on the next run.
     * Child processes (artisan in a test) inherit the name. Only *_run_<pid> is
     * ever swept, so a database pinned with DB_DATABASE is never dropped.
     *
     * @param  array<string, mixed>  $config
     */
    private static function ownDatabase(array $config): string
    {
        $name = 'nunabooks_test_run_'.getmypid();
        $connect = fn (): PDO => new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password']);
        $pdo = $connect();

        foreach ($pdo->query("SHOW DATABASES LIKE 'nunabooks\\_test\\_run\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $database) {
            if (preg_match('/^nunabooks_test_run_(\d+)$/', $database, $m) === 1 && (int) $m[1] !== getmypid() && ! processRunning((int) $m[1])) {
                $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
            }
        }
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin");
        register_shutdown_function(fn () => $connect()->exec("DROP DATABASE IF EXISTS `{$name}`"));

        putenv("DB_DATABASE={$name}");
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $name;

        return $name;
    }
}
