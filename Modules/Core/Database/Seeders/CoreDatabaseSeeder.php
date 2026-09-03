<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Core's seeders, in the only order they work in.
 *
 * Branches first because everything carries a BranchId; roles before users
 * because a user names one; menu sections before menu items because an item
 * addresses its parent by path within a section.
 *
 * T004 replaces this with the seed-master ledger (plan §3.5) so a seeder runs
 * once per environment and records that it did. Until then this is safe to
 * re-run: every seeder below upserts.
 */
class CoreDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BranchSeeder::class,
            RoleSeeder::class,
            MenuSeeder::class,
            UserSeeder::class,
        ]);
    }
}
