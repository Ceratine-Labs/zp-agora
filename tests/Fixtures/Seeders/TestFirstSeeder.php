<?php

namespace Tests\Fixtures\Seeders;

use Illuminate\Database\Seeder;

/** Declares an order, so it must run before the one that declares a later one. */
class TestFirstSeeder extends Seeder
{
    public int $seedOrder = 10;

    public function run(): void
    {
        TestState::ran(self::class);

        $this->command?->info('first seeder ran');
    }
}
