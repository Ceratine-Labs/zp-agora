<?php

namespace App\Grid\Sources;

use App\Grid\GridPage;
use App\Grid\GridQuery;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;

/**
 * A grid over a stored procedure — the normal case.
 *
 * WHERE THE PAGING HAPPENS, AND WHY
 *
 * In the procedure. `OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize
 * ROWS ONLY`, with the size of the whole filtered set returned as result set 2.
 * PHP sends eight parameters and receives at most @PageSize rows; it never
 * holds the answer, only the page.
 *
 * Two alternatives were considered and rejected:
 *
 *   Page in PHP. Call the procedure, fetch everything, `slice()`. Ten thousand
 *   rows of twenty columns cross the wire and land in PHP memory to render
 *   fifty of them, on every sort click and every page turn — and the sort has
 *   to be reimplemented in PHP, which puts the ordering semantics in two
 *   places. feature-rules §3.2 names that as the trap ZP paid for, in the
 *   filter rather than the sort, and it is the same trap.
 *
 *   Page around the procedure in T-SQL. `INSERT INTO #rows EXEC agora.usp_…`
 *   and then OFFSET/FETCH over the temp table. It works, but the temp table
 *   has to be declared with the exact result shape before the EXEC, which
 *   means this class would have to know the columns of every procedure it
 *   calls — the one thing the eight-parameter contract exists to avoid — and
 *   the procedure still does all of its own work first, so nothing is saved.
 *
 * WHAT IT COSTS. The procedure re-runs its whole query for every page: the set
 * is built, counted and then one page is taken. Turning to page 40 of a
 * 10 000-row answer costs the same as page 1. That is the price of a stateless
 * grid whose logic the customer can open in SSMS and change — and it is a
 * price, so it is measured rather than assumed: see docs/grid.md, and the
 * timings in tests/Feature/Grid/GridProcedurePagingTest.
 *
 * HEADER FILTERS. The eight-parameter template has nowhere to put a per-column
 * filter. A procedure that wants them declares a ninth, @FiltersJson, and
 * reads it with OPENJSON; a source constructed with `acceptsFilters: false`
 * refuses to be given any, rather than quietly dropping them or — worse —
 * applying them in PHP where the semantics would then exist twice.
 *
 * SCOPE THE TEMPLATE CANNOT CARRY. Some grids are ABOUT something the nine
 * parameters have no room for: the recon run list is one area's runs, and
 * whose they are. That is not a header filter — the user did not type it and
 * cannot clear it — so it does not belong in @FiltersJson, and it is not a
 * search either. `extra` is the escape hatch: named arguments the definition
 * resolves per request and hands over, on top of the template.
 *
 * It is deliberately narrow. `extra` may not overwrite a template parameter —
 * a grid quietly redefining @PageSize would break paging in a way nothing
 * reports — so a collision throws rather than wins.
 */
final class ProcedureSource implements GridSource
{
    /** @param array<string, scalar|null> $extra */
    public function __construct(
        private string $procedure,
        private bool $acceptsFilters = false,
        private ?string $connection = null,
        private array $extra = [],
    ) {}

    public function page(GridQuery $query): GridPage
    {
        $parameters = $query->procedureParameters();

        if ($collisions = array_intersect_key($this->extra, $parameters)) {
            throw new \LogicException(
                "[{$this->procedure}] passes ".implode(', ', array_keys($collisions))
                .' as an extra argument, but the grid template already sends it. '
                .'Rename the procedure\'s parameter — the template owns those nine names.'
            );
        }

        $parameters += $this->extra;

        if ($this->acceptsFilters) {
            $parameters['FiltersJson'] = $query->filtersJson();
        } elseif ($query->filters !== []) {
            throw new \LogicException(
                "[{$this->procedure}] does not declare @FiltersJson, so it cannot answer a header filter. "
                .'Add the parameter to the procedure, or drop the filters from the definition — do not '
                .'filter in PHP: that is the same rule living in two places.'
            );
        }

        $started = microtime(true);
        $sets = $this->procedures()->callSets($this->procedure, $parameters);
        $ms = (microtime(true) - $started) * 1000;

        $rows = $sets[0] ?? collect();

        // Result set 2 is one row, (TotalRows BIGINT), by contract. A procedure
        // that has lost it is a bug in the procedure — counting the page here
        // instead would report 50 of 50 for an answer of ten thousand, and the
        // extract ceiling would never be reached.
        $total = ($sets[1] ?? collect())->first();

        if ($total === null || ! property_exists($total, 'TotalRows')) {
            throw new \LogicException(
                "[{$this->procedure}] did not return (TotalRows BIGINT) as its second result set. "
                .'Every grid procedure returns the page and then the size of the whole set.'
            );
        }

        return new GridPage(
            rows: $rows,
            total: (int) $total->TotalRows,
            totals: $this->grandTotals($sets[2] ?? null),
            ms: $ms,
        );
    }

    public function name(): string
    {
        return str_contains($this->procedure, '.')
            ? $this->procedure
            : config('agora.schema').'.'.$this->procedure;
    }

    public function supportsFilters(): bool
    {
        return $this->acceptsFilters;
    }

    /**
     * An optional third result set: one row of grand totals over the whole
     * filtered set, keyed by column name. Absent on most procedures, and the
     * service falls back to totalling the page when it is.
     *
     * @param  Collection<int, object>|null  $set
     * @return array<string, float|int|null>
     */
    private function grandTotals(mixed $set): array
    {
        $row = $set instanceof Collection ? $set->first() : null;

        if (! is_object($row)) {
            return [];
        }

        /** @var array<string, float|int|null> $totals */
        $totals = [];

        foreach (get_object_vars($row) as $key => $value) {
            $totals[$key] = is_numeric($value) ? (float) $value : null;
        }

        return $totals;
    }

    private function procedures(): ProcedureService
    {
        $service = app(ProcedureService::class);

        return $this->connection === null ? $service : $service->on($this->connection);
    }
}
