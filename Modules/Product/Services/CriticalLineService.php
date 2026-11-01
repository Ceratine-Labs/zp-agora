<?php

namespace Modules\Product\Services;

use App\Grid\GridQuery;
use App\Support\ProcedureService;
use LogicException;

/**
 * The critical list's write path, and the one number the screen leads with.
 *
 * Both the listing and its headline count use the same procedure and
 * validated query, so a drilldown cannot count a different set of lines.
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
     * Count out-of-stock lines within the grid's validated scope and filters.
     * The procedure owns the stock-status rule; this only narrows the query.
     */
    public function outOfStockCount(GridQuery $query): int
    {
        foreach ($query->filters as $filter) {
            if (($filter['column'] ?? null) !== 'Status') {
                continue;
            }
            $statuses = is_array($filter['in'] ?? null) ? $filter['in'] : [];
            if (array_filter($statuses, fn (mixed $value): bool => is_string($value)
                && strcasecmp($value, 'Out of stock') === 0) === []) {
                return 0;
            }
        }

        $summary = $query->onPage(1);
        $summary->pageSize = 1;
        $summary->filters = array_values(array_filter($query->filters,
            fn (array $filter): bool => ($filter['column'] ?? null) !== 'Status'));
        $summary->filters[] = ['column' => 'Status', 'type' => 'set', 'in' => ['Out of stock']];

        $sets = $this->procedures->callSets('agora.usp_Product_GridCriticalLines',
            $summary->procedureParameters() + ['FiltersJson' => $summary->filtersJson()]);
        $count = ($sets[1] ?? collect())->first();
        if ($count === null || ! isset($count->TotalRows)) {
            throw new LogicException('The critical-line summary did not return its filtered count.');
        }

        return (int) $count->TotalRows;
    }
}
