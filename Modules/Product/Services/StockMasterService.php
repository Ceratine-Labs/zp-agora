<?php

namespace Modules\Product\Services;

use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Reads of the stock master that are not the grid.
 *
 * The listing comes out of `agora.usp_Product_GridStockItems`, because a grid
 * the customer can change is the rule (feature-rules §2). What is here is the
 * detail panel and the lookups behind it — machinery rather than exposure, so
 * it does not need wrapping in T-SQL to satisfy a rule that was about the
 * customer's visibility.
 *
 * Every read goes through `agora.vw_*`, never through a model pointed at the
 * `pumpit` connection. That is not style: it is the one place the
 * override-or-legacy resolution lives, and a query that bypassed it would show
 * the customer's row where Agora holds a correction.
 */
class StockMasterService
{
    public function __construct(private ProcedureService $procedures = new ProcedureService) {}

    /**
     * Write an override, retire a line, park or unpark.
     *
     * Every rule lives in the procedure — see its header — so this is a
     * translation layer and nothing else. It does not decide anything, it does
     * not retry, and it does not catch the refusal: an AgoraProcException
     * carries the code the screen renders, and swallowing it here would turn
     * "another line already uses that POS code" into a blank page.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(int $branchId, string $itemNo, string $action, array $input, ?int $userId): object
    {
        return $this->procedures->write('agora.usp_Product_SaveStockItem', [
            'BranchId' => $branchId,
            'StockItemNo' => $itemNo,
            'Action' => $action,
            'StockItemDescription' => $input['StockItemDescription'] ?? null,
            'AreaNo' => isset($input['AreaNo']) ? (int) $input['AreaNo'] : null,
            'PosSystem' => $input['PosSystem'] ?? null,
            'POSCode' => $input['POSCode'] ?? null,
            'SellingPrice' => $input['SellingPrice'] ?? null,
            'PriceType' => $input['PriceType'] ?? null,
            'Factor' => $input['Factor'] ?? 0,
            'UOMCode' => $input['UOMCode'] ?? null,
            'IssueMultiple' => $input['IssueMultiple'] ?? 0,
            'IssueMultiplePercentage' => $input['IssueMultiplePercentage'] ?? 0,
            'QtyVarAllowance' => $input['QtyVarAllowance'] ?? 0,
            // A checkbox that is not ticked does not arrive at all, so every
            // boolean is defaulted here rather than being read as null and
            // silently becoming whatever the column's default was.
            'IsMonitoredItem' => (int) (bool) ($input['IsMonitoredItem'] ?? false),
            'IsDoCloseQtyCalc' => (int) (bool) ($input['IsDoCloseQtyCalc'] ?? false),
            'IsAllowNegativeQtyIssued' => (int) (bool) ($input['IsAllowNegativeQtyIssued'] ?? false),
            'IsAllowNegativeQtyClose' => (int) (bool) ($input['IsAllowNegativeQtyClose'] ?? false),
            'IsStockItemPreProduction' => (int) (bool) ($input['IsStockItemPreProduction'] ?? false),
            'IsPreProductionItem' => (int) (bool) ($input['IsPreProductionItem'] ?? false),
            'PreProductionTypeNo' => (int) ($input['PreProductionTypeNo'] ?? 0),
            'Ratio' => $input['Ratio'] ?? 0,
            'ProduceLimitPercentage' => $input['ProduceLimitPercentage'] ?? 0,
            'IsActive' => (int) (bool) ($input['IsActive'] ?? true),
            'Reason' => $input['reason'] ?? null,
            'UserId' => $userId,
        ]);
    }

    /**
     * The counting areas at one site, for the editor's area picker.
     *
     * @return array<int, object>
     */
    public function areas(int $branchId): array
    {
        return $this->db()->select(
            'SELECT AreaNo, AreaDescription, AreaGroup FROM [agora].[vw_StockArea] WHERE BranchId = ? ORDER BY AreaNo',
            [$branchId]
        );
    }

    /**
     * One stock line as it is in force — the override where there is one, the
     * customer's row otherwise.
     */
    public function find(int $branchId, string $itemNo): ?object
    {
        return $this->db()->selectOne('
            SELECT i.*,
                   b.Name AS BranchName,
                   s.LastCountedAt,
                   s.CountLinesAllTime,
                   s.CountLines90,
                   s.RefreshedAt AS CountStatsRefreshedAt,
                   p.CostPrice,
                   p.PosSellPrice,
                   p.QtyOnHand,
                   p.QtySoldThisMonth,
                   p.PosDescription,
                   NULLIF(p.Category, \'\')            AS Category,
                   NULLIF(p.LastSoldAt, \'1900-01-01\') AS LastSoldAt,
                   CASE WHEN c.PosCode IS NULL THEN CONVERT(bit, 0) ELSE c.IsActive END AS IsCritical
            FROM [agora].[vw_StockItem] i
            JOIN [agora].[Branch] b ON b.BranchId = i.BranchId
            LEFT JOIN [agora].[StockItemCountStat] s
                   ON s.BranchId = i.BranchId AND s.StockItemNo = i.StockItemNo
            /* All three columns — see the grid procedure. Two attaches a
               different product\'s cost to 713 lines across the estate. */
            LEFT JOIN [agora].[vw_StockItemPos] p
                   ON p.BranchId = i.BranchId AND p.PosSystem = i.PosSystem AND p.PosCode = i.POSCode
            LEFT JOIN [agora].[vw_StockItemCritical] c
                   ON c.BranchId = i.BranchId AND c.PosSystem = i.PosSystem AND c.PosCode = i.POSCode
            WHERE i.BranchId = ? AND i.StockItemNo = ?
        ', [$branchId, $itemNo]);
    }

    /**
     * The customer's own row, unshadowed.
     *
     * The detail panel shows it beside the effective one wherever the two
     * differ. That is the accepted cost of an override: two sources of truth,
     * and a screen that could not show both would make a divergence
     * invisible.
     */
    public function legacy(int $branchId, string $itemNo): ?object
    {
        return $this->db()->selectOne(
            'SELECT * FROM [agora].[vw_LegacyStockItem] WHERE BranchId = ? AND StockItemNo = ?',
            [$branchId, $itemNo]
        );
    }

    /** The counting area a line belongs to, with its loss grace. */
    public function area(int $branchId, int $areaNo): ?object
    {
        return $this->db()->selectOne(
            'SELECT * FROM [agora].[vw_StockArea] WHERE BranchId = ? AND AreaNo = ?',
            [$branchId, $areaNo]
        );
    }

    /**
     * When the last-counted rollup was last rebuilt, or null if it never has
     * been.
     *
     * The listing says this out loud. A "last counted" column that is a cache
     * and does not admit it is a column people will read as live, and the
     * rebuild is a command somebody runs rather than something a page load
     * triggers.
     */
    public function countStatsRefreshedAt(): ?string
    {
        $row = $this->db()->selectOne('SELECT MAX(RefreshedAt) AS RefreshedAt FROM [agora].[StockItemCountStat]');

        return $row?->RefreshedAt;
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
