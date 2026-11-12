<?php

namespace Modules\Fuel\Services;

use App\Grid\GridQuery;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Modules\Fuel\Grids\FuelPriceGrid;

/**
 * The fuel matrix's price-change log and its per-grade summary.
 *
 * Presentation only: what counts as a move, the price sets either side of it
 * and the summary figures are all decided by agora.usp_Fuel_GetPriceMoves,
 * over BRN_FuelPrice. The scope is the grid's own — the same sites, the same
 * dates — so the log can never describe a different selection from the one
 * the matrix above it is showing.
 */
class FuelPriceMoves
{
    public const PROCEDURE = 'usp_Fuel_GetPriceMoves';

    public function __construct(private ProcedureService $procedures) {}

    /** @return array{summary: Collection<int, object>, moves: Collection<int, object>} */
    public function build(GridQuery $query): array
    {
        $sets = $this->procedures->callSets(self::PROCEDURE, [
            'BranchIds' => $query->branchIds === [] ? null : implode(',', $query->branchIds),
            'DateFrom' => $query->from,
            'DateTo' => $query->to,
            'AllowedBranchIds' => FuelPriceGrid::allowed(),
        ]);

        return ['summary' => $sets[0] ?? collect(), 'moves' => $sets[1] ?? collect()];
    }
}
