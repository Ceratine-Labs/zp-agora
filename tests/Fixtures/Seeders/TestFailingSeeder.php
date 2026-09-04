<?php

namespace Tests\Fixtures\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

/** Throws, so the run stops and nothing is recorded for it. */
class TestFailingSeeder extends Seeder
{
    public int $seedOrder = 60;

    public function run(): void
    {
        TestState::ran(self::class);

        throw new RuntimeException('TEST-seeder failed on purpose.');
    }
}
