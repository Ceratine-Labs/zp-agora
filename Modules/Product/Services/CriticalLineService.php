<?php

namespace Modules\Product\Services;

use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The critical list's write path, and the one number the screen leads with.
 *
 * The listing itself comes out of agora.usp_Product_GridCriticalLines, because
 * a grid the customer can change is the rule (feature-rules §2). What is here
 * is the write and a headline count — machinery, not exposure.
 */
class CriticalLineService
{
    public function __construct(private ProcedureService $procedures = new ProcedureService) {}

    /**
     * Put a line on the list, take it off, park or unpark.
     *
     * Every rule is the procedure's. This does not decide anything and does
     * not catch the refusal: an AgoraProcException carries the message the
     * screen shows, and swallowing it here would turn "this site's POS file
     * has no such code" into a blank page.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(int $branchId, string $posSystem, string $posCode, string $action, array $input, ?int $userId): object
    {
        return $this->procedures->write('agora.usp_Product_SaveCriticalLine', [
            'BranchId' => $branchId,
            'PosSystem' => $posSystem,
            'PosCode' => $posCode,
            'Action' => $action,
            'Description' => $input['Description'] ?? null,
            'Category' => $input['Category'] ?? null,
            // An unticked checkbox does not arrive at all, so it is defaulted
            // here rather than read as null and silently taking the column
            // default.
            'IsActive' => (int) (bool) ($input['IsActive'] ?? false),
            'Reason' => $input['reason'] ?? null,
            'UserId' => $userId,
        ]);
    }

    /**
     * How many active critical lines are at or below zero on hand, in the
     * branches currently in scope.
     *
     * The screen leads with this because it is the question people open the
     * screen holding. Across the estate on 20 September 2026 it was 709 of
     * 1,677 active lines, which is not a number anybody should have to find
     * by sorting a grid.
     */
    public function outOfStockCount(Request $request): int
    {
        $branches = array_values(array_filter(array_map(
            intval(...),
            array_filter(explode(',', (string) $request->query('branches', '')), fn (string $v) => $v !== '')
        )));

        $sql = '
            SELECT COUNT_BIG(*) AS n
            FROM [agora].[vw_StockItemCritical] c
            LEFT JOIN [agora].[vw_StockItemPos] p
                   ON p.BranchId = c.BranchId AND p.PosSystem = c.PosSystem AND p.PosCode = c.PosCode
            WHERE c.IsActive = 1 AND p.QtyOnHand <= 0
        ';

        if ($branches !== []) {
            $sql .= ' AND c.BranchId IN ('.implode(',', array_fill(0, count($branches), '?')).')';
        }

        $row = $this->db()->selectOne($sql, $branches);

        return $row === null ? 0 : (int) $row->n;
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
