<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;

/**
 * Modules seed themselves; this only names them, in dependency order.
 *
 * T004 replaces this with the seed-master ledger (plan §3.5), which records
 * what has run per environment. Until then every seeder below is an upsert, so
 * re-running is safe.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CoreDatabaseSeeder::class,
        ]);
    }
}
