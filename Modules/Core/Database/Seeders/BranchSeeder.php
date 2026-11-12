<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\BranchMigrationService;

/**
 * Copies dbo.SS_Branch into agora.Branch.
 *
 * A copy rather than a view, because BranchId leads the clustered index of
 * every table in the system — the table that defines that id has to be ours.
 * It stays reconcilable: BranchId here IS SSBranchId, so a join back to legacy
 * data needs no translation.
 *
 * Since T021 (12 Nov 2026) this delegates to agora.usp_Migrate_Branch, which
 * owns the whole derivation — region, brand, format, status, POS type, the six
 * administrative entities and each site's profit centres — so a fresh install
 * and an existing one arrive at the same branch master. Before that this class
 * copied the bare PumpIT ids and marked the administrative entities by name;
 * on a database where it already ran, BranchMasterSeeder does the migration.
 */
class BranchSeeder extends Seeder
{
    /** Branches first: everything else carries a BranchId. */
    public int $seedOrder = 10;

    public function run(BranchMigrationService $migration): void
    {
        $result = $migration->run();

        $this->command?->info('  Branches: '.$result['status']->Message);
    }
}
