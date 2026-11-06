<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Every database test starts from the seeded baseline, as the old tests/helpers did. */
    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        // Pages render without a built public/build/manifest.json.
        $this->withoutVite();
    }
}
