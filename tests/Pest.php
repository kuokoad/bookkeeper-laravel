<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Tests run against a real MySQL database (nunabooks_test, see phpunit.xml),
| never SQLite: the point is to test MySQL's own behaviour — CHECKs,
| triggers, collation and locking.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Database', 'Feature', 'Services', 'Invariants');

pest()->extend(TestCase::class)->in('Domain');

// These run real processes that commit, so they use their own databases and no RefreshDatabase.
pest()->extend(TestCase::class)->in('Processes');
