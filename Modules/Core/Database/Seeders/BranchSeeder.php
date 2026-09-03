<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;

/**
 * Copies dbo.SS_Branch into agora.Branch.
 *
 * A copy rather than a view, because BranchId leads the clustered index of
 * every table in the system — the table that defines that id has to be ours.
 * It stays reconcilable: BranchId here IS SSBranchId, so a join back to legacy
 * data needs no translation.
 *
 * The six administrative entities are marked IsTrading = false. They are
 * branches for accounting and always have been, but they never keep a trading
 * day, and leaving them in a day-close queue is how the queue stops meaning
 * anything. The list is by name because the legacy schema has no column that
 * says so — ClassId is 1 for almost everything, including real sites.
 */
class BranchSeeder extends Seeder
{
    private const ADMINISTRATIVE = [
        'AJLG Properties',
        'Zululand Petroleum',
        'Arcum Venandi',
        'Jakarie Vulstasie',
        'Thokozile Trust',
        'Zickyza Properties',
    ];

    public function run(): void
    {
        $legacy = DB::connection(config('agora.connections.primary'))
            ->table('dbo.SS_Branch')
            ->select('SSBranchId', 'BranchName', 'BrandId', 'RegionId', 'ClassId', 'IsActive')
            ->orderBy('SSBranchId')
            ->get();

        foreach ($legacy as $row) {
            $branch = Branch::query()->acrossBranches()
                ->firstOrNew(['BranchId' => (int) $row->SSBranchId]);

            $branch->fill([
                'Name' => trim($row->BranchName),
                'BrandId' => $row->BrandId,
                'RegionId' => $row->RegionId,
                'ClassId' => $row->ClassId,
                'IsTrading' => ! in_array(trim($row->BranchName), self::ADMINISTRATIVE, true),
                'IsActive' => (bool) $row->IsActive,
                'SortOrder' => (int) $row->SSBranchId,
            ]);
            $branch->BranchId = (int) $row->SSBranchId;
            $branch->save();
        }

        $this->command?->info('  Branches: '.$legacy->count().' from dbo.SS_Branch ('
            .count(self::ADMINISTRATIVE).' administrative).');
    }
}
