<?php

namespace Tests\Feature\Assets;

use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Modules\Assets\Services\AssetsService;
use Modules\Core\Models\Branch;

/**
 * Assets on branch 999, and the few legacy rows they need, in the LOCAL
 * database and the LOCAL PumpIT stub only.
 *
 * Branch 999 is TEST-Playwright: inactive and non-trading, so nothing here can
 * be mistaken for a real site. Everything written is on that branch or named
 * TSTA:
 *
 *   PumpIT stub   ASSET_AssetGroup / ASSET_AssetType  groups TSTA (3 years),
 *                                                     TSTC (6 years) and TSTB
 *                                                     (no period)
 *                 BRN_Suppliers   TSTA-S1, TSTA-S2 at 999
 *                 BRN_Transaction / BRN_TransactionLine  TSTA-* requests at 999
 *   Agora         agora.Asset, AssetMovement, AssetRepair rows on branch 999
 *
 * cleanUpAssets() removes all of it. The register's own tables are
 * transactional and are never deleted by the application; a test deleting its
 * own rows on a test branch in a local container is the tearDown every test
 * here owes, not a precedent.
 */
trait AssetsFixture
{
    protected const BRANCH = 999;

    protected const GROUP = 'TSTA';

    protected const GROUP_NO_RATE = 'TSTB';

    protected const GROUP_SIX = 'TSTC';

    protected const S1 = 'TSTA-S1';

    protected const S2 = 'TSTA-S2';

    protected function seedAssetMasters(): void
    {
        $db = $this->db();

        $db->table('PumpIT.dbo.ASSET_AssetGroup')->insert([
            ['AssetGroupCode' => self::GROUP, 'AssetGroupDescription' => 'TEST-Asset group'],
            ['AssetGroupCode' => self::GROUP_NO_RATE, 'AssetGroupDescription' => 'TEST-Group with no period'],
            ['AssetGroupCode' => self::GROUP_SIX, 'AssetGroupDescription' => 'TEST-Six-year group'],
        ]);

        $db->table('PumpIT.dbo.ASSET_AssetType')->insert([
            ['AssetGroupCode' => self::GROUP, 'AssetTypeCode' => 'TSTA01', 'AssetTypeDescription' => 'TEST-Fridge'],
            ['AssetGroupCode' => self::GROUP, 'AssetTypeCode' => 'TSTA02', 'AssetTypeDescription' => 'TEST-Printer'],
            ['AssetGroupCode' => self::GROUP_NO_RATE, 'AssetTypeCode' => 'TSTB01', 'AssetTypeDescription' => 'TEST-Land'],
            ['AssetGroupCode' => self::GROUP_SIX, 'AssetTypeCode' => 'TSTC01', 'AssetTypeDescription' => 'TEST-Chip fryer'],
        ]);

        $db->table('PumpIT.dbo.BRN_Suppliers')->insert([
            ['SSBranchId' => self::BRANCH, 'SupplierCode' => self::S1, 'SupplierName' => 'TEST-Snowman', 'IsCash' => 0, 'IsStock' => 0, 'isActive' => 1],
            ['SSBranchId' => self::BRANCH, 'SupplierCode' => self::S2, 'SupplierName' => 'TEST-Zululand Refrigeration', 'IsCash' => 0, 'IsStock' => 0, 'isActive' => 1],
        ]);

        // The periods the procedures are handed: TSTA over three years (the
        // office-equipment period), TSTC over six (kitchen equipment), TSTB none.
        config([
            'assets.depreciation.provisional' => true,
            'assets.depreciation.residual_pct' => 0,
            'assets.depreciation.groups' => [
                self::GROUP => ['life_years' => 3, 'sars_class' => 'TEST-class', 'note' => null],
                self::GROUP_NO_RATE => ['life_years' => null, 'sars_class' => 'TEST-none', 'note' => null],
                self::GROUP_SIX => ['life_years' => 6, 'sars_class' => 'TEST-class', 'note' => null],
            ],
        ]);
    }

    /**
     * A purchase request at branch 999, as the legacy client writes one.
     *
     * @param  array<int, float>  $lines
     */
    protected function purchaseRequest(string $no, string $supplier, array $lines, bool $approved = true, bool $declined = false): void
    {
        $db = $this->db();

        $db->table('PumpIT.dbo.BRN_Transaction')->insert([
            'SSBranchId' => self::BRANCH, 'TransactionNo' => $no, 'TypeCode' => '02', 'SupplierCode' => $supplier,
            'PurchaseRequestDate' => '2026-08-01', 'QuoteNo' => 'TEST', 'Approved' => $approved ? 1 : 0,
            'IsDeclined' => $declined ? 1 : 0, 'Posted' => 0, 'CreateDateTime' => now(),
        ]);

        foreach (array_values($lines) as $i => $amount) {
            $db->table('PumpIT.dbo.BRN_TransactionLine')->insert([
                'SSBranchId' => self::BRANCH, 'TransactionNo' => $no, 'TypeCode' => '02', 'ItemNo' => $i + 1,
                'ExpenseNo' => 1, 'PurchaseAmount' => $amount, 'PurchaseRequestDescription' => 'TEST-line '.($i + 1),
                'IsEmailSent' => 0, 'IsApprovedEmailSent' => 0, 'Posted' => 0,
            ]);
        }
    }

    /**
     * Register an asset through the procedure, exactly as the screen does.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function registerAsset(array $overrides = []): int
    {
        $result = $this->procs()->write('agora.usp_Assets_SaveAsset', $overrides + [
            'BranchId' => self::BRANCH,
            'AssetGroupCode' => self::GROUP,
            'AssetTypeCode' => 'TSTA01',
            'Make' => 'TEST-Make',
            'Model' => 'TEST-Model',
            'SerialNo' => null,
            'DatePurchased' => '2026-01-15',
            'Cost' => 36000,
            'Condition' => 'GOOD',
            'UserId' => null,
        ]);

        return (int) $result->Id;
    }

    /** @param  array<string, mixed>  $overrides */
    protected function raiseRepair(int $assetId, string $callDate, ?string $supplier = self::S1, array $overrides = []): int
    {
        $result = $this->procs()->write('agora.usp_Assets_SaveRepair', $overrides + [
            'BranchId' => self::BRANCH,
            'AssetId' => $assetId,
            'CallDate' => $callDate,
            'SupplierCode' => $supplier,
            'Problem' => 'TEST-Not cooling',
            'UserId' => null,
        ]);

        return (int) $result->Id;
    }

    /** Another real, active site in the local database, for a transfer to go to. */
    protected function otherBranch(): int
    {
        return (int) Branch::query()->acrossBranches()
            ->where('BranchId', '<>', self::BRANCH)
            ->where('IsActive', true)
            ->orderBy('BranchId')
            ->value('BranchId');
    }

    protected function ratesJson(): string
    {
        return app(AssetsService::class)->ratesJson();
    }

    protected function cleanUpAssets(): void
    {
        $db = $this->db();

        $db->table('agora.AssetRepair')->where('BranchId', self::BRANCH)->delete();
        $db->table('agora.AssetMovement')->where('BranchId', self::BRANCH)->delete();
        $db->table('agora.Asset')->where('BranchId', self::BRANCH)->delete();

        $db->table('PumpIT.dbo.BRN_TransactionLine')->where('SSBranchId', self::BRANCH)->where('TransactionNo', 'like', 'TSTA%')->delete();
        $db->table('PumpIT.dbo.BRN_Transaction')->where('SSBranchId', self::BRANCH)->where('TransactionNo', 'like', 'TSTA%')->delete();
        $db->table('PumpIT.dbo.BRN_Suppliers')->where('SSBranchId', self::BRANCH)->where('SupplierCode', 'like', 'TSTA%')->delete();
        $db->table('PumpIT.dbo.ASSET_AssetType')->whereIn('AssetGroupCode', [self::GROUP, self::GROUP_NO_RATE, self::GROUP_SIX])->delete();
        $db->table('PumpIT.dbo.ASSET_AssetGroup')->whereIn('AssetGroupCode', [self::GROUP, self::GROUP_NO_RATE, self::GROUP_SIX])->delete();
    }

    protected function procs(): ProcedureService
    {
        return app(ProcedureService::class);
    }

    protected function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
