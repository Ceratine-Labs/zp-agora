<?php

namespace App\Grid;

/**
 * What the grid is being asked for, cleaned, and in one object.
 *
 * Built once by GridService from the query string, the user's saved layout and
 * the definition's defaults, then handed to the source and — unchanged — to
 * the exporter. That last part is the whole point: the export must be what the
 * user is looking at (feature-rules §3.2), and the only way to be certain of
 * that is for both to run the same question rather than two questions that are
 * meant to agree.
 *
 * Everything here has already been whitelisted. A sort that is not in the
 * catalogue, a page size out of bounds, a filter on a column that does not
 * exist — none of them reach this object; they are dropped by GridService and
 * the grid answers with its defaults instead of failing the request. A grid is
 * a read; a malformed URL should degrade, not 500.
 */
final class GridQuery
{
    /**
     * @param  array<int, int>  $branchIds  empty means every branch in scope
     * @param  array<int, array<string, mixed>>  $filters  normalised by GridFilter
     */
    public function __construct(
        public array $branchIds = [],
        public ?string $from = null,
        public ?string $to = null,
        public ?string $search = null,
        public ?string $sort = null,
        public bool $ascending = true,
        public int $page = 1,
        public int $pageSize = 50,
        public array $filters = [],
    ) {}

    /** The same question, asked for one whole set rather than one page. Used by the export. */
    public function forExport(int $ceiling): self
    {
        $clone = clone $this;
        $clone->page = 1;
        $clone->pageSize = $ceiling;

        return $clone;
    }

    /** The same question on a different page. */
    public function onPage(int $page): self
    {
        $clone = clone $this;
        $clone->page = max(1, $page);

        return $clone;
    }

    /**
     * The eight parameters of the grid procedure contract (feature-rules §2).
     *
     * Named exactly as the template declares them, because ProcedureService
     * binds by name — a renamed parameter here is a proc that silently runs on
     * its defaults.
     *
     * @return array<string, mixed>
     */
    public function procedureParameters(): array
    {
        return [
            'BranchIds' => $this->branchIds === [] ? null : implode(',', $this->branchIds),
            'DateFrom' => $this->from,
            'DateTo' => $this->to,
            'Search' => $this->search,
            'SortColumn' => $this->sort,
            'SortAsc' => (int) $this->ascending,
            'Page' => $this->page,
            'PageSize' => $this->pageSize,
        ];
    }

    /**
     * The header filters, as the JSON a procedure that accepts them is given.
     *
     * The eight-parameter template has nowhere to put a per-column filter, and
     * feature-rules §3.1 requires one. Rather than filter in PHP — which would
     * put the matching semantics in two places, the exact failure §3.2 warns
     * about — a grid procedure may declare a ninth parameter, @FiltersJson,
     * and do the work in T-SQL with OPENJSON. A definition says whether its
     * procedure has one; when it does not, it may not declare header filters.
     */
    public function filtersJson(): ?string
    {
        if ($this->filters === []) {
            return null;
        }

        return json_encode(array_values($this->filters), JSON_THROW_ON_ERROR);
    }
}
