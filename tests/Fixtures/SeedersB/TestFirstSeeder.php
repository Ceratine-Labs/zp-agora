<?php

namespace Tests\Fixtures\SeedersB;

use Illuminate\Database\Seeder;
use Tests\Fixtures\Seeders\TestState;

/**
 * Deliberately shares its SHORT name with Tests\Fixtures\Seeders\TestFirstSeeder.
 *
 * Every module ships a `MenuSeeder`, so two seeders with one short name is the
 * normal case in this application, not a contrived one. It lives in its own
 * directory because the catalog walks recursively — a subdirectory of the main
 * fixture folder would join that catalog and change what the ordering test
 * sees.
 */
class TestFirstSeeder extends Seeder
{
    public int $seedOrder = 10;

    public function run(): void
    {
        TestState::ran(self::class);
    }
}
