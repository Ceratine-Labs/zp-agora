<?php

namespace Tests\Fixtures\Seeders;

use Illuminate\Database\Seeder;

/** Declares nothing, so it takes the default order of 50 and runs last. */
class TestUnorderedSeeder extends Seeder
{
    public function run(): void
    {
        TestState::ran(self::class);
    }
}
