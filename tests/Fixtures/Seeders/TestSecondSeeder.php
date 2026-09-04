<?php

namespace Tests\Fixtures\Seeders;

use Illuminate\Database\Seeder;

/** Later in the order than TestFirstSeeder, and earlier than the undeclared one. */
class TestSecondSeeder extends Seeder
{
    public int $seedOrder = 20;

    public function run(): void
    {
        TestState::ran(self::class);
    }
}
