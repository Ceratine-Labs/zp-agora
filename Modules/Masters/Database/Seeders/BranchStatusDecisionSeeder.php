<?php

namespace Modules\Masters\Database\Seeders;

use App\Support\ProcedureService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ryan's decision on the branch master (T021), 12 Nov 2026: four sites are
 * mothballed.
 *
 *   10  Munchies          11  Nseleni OK
 *   12  Ngwelezane OK     28  Richie Motors
 *
 * No cashups since 2025, or never. 16 Inkwazi and 29 Bullion stay Trading —
 * they are waiting on ZP — and region, brand and format stay as PumpIT has
 * them, so this seeder touches Status and nothing else.
 *
 * THROUGH THE AUDITED SAVE, NOT AROUND IT. Each branch is saved by
 * agora.usp_Masters_SaveBranch with every other value as it already is, so the
 * procedure's rules apply (IsTrading follows Status) and agora.MasterChange
 * records the change with the reason below. The branch's profit centres are
 * passed back unchanged, because the procedure makes them exactly the list it
 * is given.
 *
 * Idempotent: a branch already Mothballed is left alone. A branch whose
 * PumpIT name does not match the decision is REFUSED rather than guessed at —
 * the seeder throws, writes no ledger row, and the run says which one.
 * Writes only agora.Branch and agora.MasterChange; never PumpIT.
 */
class BranchStatusDecisionSeeder extends Seeder
{
    /** After BranchMasterSeeder (11), which migrates the branches. */
    public int $seedOrder = 12;

    public const REASON = 'Ryan 12 Nov 2026: mothballed (no cashups since 2025 / never)';

    /** BranchId => the name PumpIT gave it, as migrated (agora.Branch.LegacyName). */
    public const BRANCHES = [
        10 => 'Munchies',
        11 => 'Nseleni OK',
        12 => 'Ngwelezane OK',
        28 => 'Richie Motors',
    ];

    public function run(ProcedureService $procedures): void
    {
        $db = DB::connection(config('agora.connections.app'));
        $saved = 0;

        foreach (self::BRANCHES as $id => $expected) {
            $branch = $db->selectOne(
                'SELECT BranchId, Name, LegacyName, RegionId, BrandId, FormatId, Status, POSType, IsAdminEntity, IsActive,
                        CONVERT(varchar(18), CONVERT(binary(8), RowVer), 1) AS Version
                 FROM agora.Branch WHERE BranchId = ? AND DeletedAt IS NULL', [$id]);

            if ($branch === null) {
                throw new RuntimeException("Branch {$id} ({$expected}) is not in agora.Branch. Run BranchMasterSeeder first.");
            }
            if (strcasecmp(trim((string) ($branch->LegacyName ?? $branch->Name)), $expected) !== 0) {
                throw new RuntimeException("Branch {$id} is [{$branch->LegacyName}] in PumpIT, not [{$expected}]. Nothing was changed.");
            }
            if ($branch->Status === 'Mothballed') {
                continue;
            }

            $centres = $db->table('agora.BranchProfitCentre')->where('BranchId', $id)->pluck('ProfitCentreId')->all();

            $procedures->write('agora.usp_Masters_SaveBranch', [
                'BranchId' => $id,
                'Name' => $branch->Name,
                'RegionId' => $branch->RegionId,
                'BrandId' => $branch->BrandId,
                'FormatId' => $branch->FormatId,
                'Status' => 'Mothballed',
                'POSType' => $branch->POSType,
                'IsAdminEntity' => (int) $branch->IsAdminEntity,
                'IsActive' => (int) $branch->IsActive,
                'ProfitCentreIds' => implode(',', array_map('intval', $centres)),
                'ExpectedVersion' => $branch->Version,
                'Reason' => self::REASON,
                'UserId' => null,
                'Authorized' => 1,
            ]);
            $saved++;
        }

        $this->command?->info("  Branch status decision: {$saved} branch(es) mothballed (10, 11, 12, 28).");
    }
}
