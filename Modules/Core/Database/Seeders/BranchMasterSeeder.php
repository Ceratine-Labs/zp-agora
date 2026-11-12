<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\BranchMigrationService;

/**
 * The branch master migration (T021) on a database whose BranchSeeder already
 * ran.
 *
 * BranchSeeder is in the seed ledger everywhere Agora exists today, so it will
 * not run again; this one-shot runs agora.usp_Migrate_Branch once. On a fresh
 * install BranchSeeder has already migrated every branch and this finds
 * nothing to do. Writes only Agora's own tables; reads PumpIT through
 * agora.vw_Legacy*.
 */
class BranchMasterSeeder extends Seeder
{
    /** Straight after BranchSeeder (10). */
    public int $seedOrder = 11;

    public function run(BranchMigrationService $migration): void
    {
        $result = $migration->run();

        $this->command?->info('  Branch master: '.$result['status']->Message);
    }
}
