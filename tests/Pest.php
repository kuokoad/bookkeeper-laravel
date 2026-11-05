<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Tests run against a real MySQL database (nunabooks_test, see phpunit.xml),
| never SQLite: the point is to test MySQL's own behaviour — CHECKs,
| triggers, collation and locking.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Database', 'Feature', 'Services');

pest()->extend(TestCase::class)->in('Domain');
