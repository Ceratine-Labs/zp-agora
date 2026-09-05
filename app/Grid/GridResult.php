<?php

namespace App\Grid;

use Illuminate\Support\Collection;

/**
 * Everything `<x-data-grid>` needs, and nothing it would have to go and fetch.
 *
 * A component never queries the database (plan §3.8), so the whole answer —
 * rows, columns as this user has them, the state of the sort, the totals, the
 * URLs every control points at — is assembled by GridService and handed over
 * as one prop. The blade reads; it does not decide.
 *
 * The URL helpers live here rather than in the view because every control on
 * the grid rebuilds the CURRENT url with one thing changed: a sort link keeps
 * the filters, a page link keeps the sort, the extract keeps all of it. Doing
 * that in blade is how a control ends up dropping a parameter nobody notices
 * until a colleague opens the link and sees different rows.
 */
final class GridResult
{
    /**
     * @param  array<int, GridColumnView>  $columns  every column, in the user's order
     * @param  Collection<int, object>  $rows  this page
     * @param  array<string, float|null>  $totals  by column key
     * @param  array<string, mixed>  $query  the current query string
     * @param  array<string, mixed>  $state  the user's saved layout
     */
    public function __construct(
        public GridDefinition $definition,
        public array $columns,
        public Collection $rows,
        public GridQuery $gridQuery,
        public int $total,
        public array $totals,
        public bool $totalsAreGrand,
        public string $textSize,
        public array $state,
        public string $baseUrl,
        public array $query,
        public float $ms,
        /** A procedure's refusal, where it declined to answer. Null on the happy path. */
        public ?string $refusal = null,
    ) {}

    public function key(): string
    {
        return $this->definition->key();
    }

    /** @return array<int, GridColumnView> */
    public function visibleColumns(): array
    {
        return array_values(array_filter($this->columns, fn (GridColumnView $c) => $c->visible));
    }

    /**
     * The first three visible columns — the mobile card's headline
     * (plan §3.9, "clean over capable"). Three because that is what fits at
     * 375px without the card becoming a table with rounded corners.
     *
     * @return array<int, GridColumnView>
     */
    public function cardColumns(): array
    {
        return array_slice($this->visibleColumns(), 0, 3);
    }

    /** The rest of the visible columns, which the card reveals on expand. */
    /** @return array<int, GridColumnView> */
    public function cardRest(): array
    {
        return array_slice($this->visibleColumns(), 3);
    }

    public function pages(): int
    {
        return $this->gridQuery->pageSize > 0
            ? max(1, (int) ceil($this->total / $this->gridQuery->pageSize))
            : 1;
    }

    public function page(): int
    {
        return $this->gridQuery->page;
    }

    /** Is this page only part of the answer? Decides the limit note. */
    public function isPartial(): bool
    {
        return $this->total > $this->rows->count();
    }

    /** Is the whole answer too big to extract in the request cycle? (feature-rules §3.2) */
    public function overCeiling(): bool
    {
        return $this->total > $this->definition->exportCeiling();
    }

    public function sort(): ?string
    {
        return $this->gridQuery->sort;
    }

    public function ascending(): bool
    {
        return $this->gridQuery->ascending;
    }

    /** The current URL with some parameters changed; a null value removes one. */
    /** @param array<string, mixed> $changes */
    public function urlWith(array $changes): string
    {
        $query = array_merge($this->query, $changes);
        $query = array_filter($query, fn (mixed $v) => $v !== null && $v !== '' && $v !== []);

        return $query === [] ? $this->baseUrl : $this->baseUrl.'?'.http_build_query($query);
    }

    /**
     * Where a column header links. Clicking the column already sorted flips it;
     * clicking a different one starts at the direction that column reads best
     * in — a number descending, because "the biggest" is the question a number
     * column is usually being asked.
     */
    public function sortUrl(GridColumn $column): string
    {
        if (! $column->isSortable()) {
            return $this->urlWith([]);
        }

        $current = $this->sort() === $column->sort;
        $direction = $current
            ? ($this->ascending() ? 'desc' : 'asc')
            : ($column->isNumeric() ? 'desc' : 'asc');

        return $this->urlWith([
            $this->param('sort') => $column->sort,
            $this->param('dir') => $direction,
            $this->param('page') => null,
        ]);
    }

    /** `ascending` / `descending` / null, for aria-sort on the header. */
    public function ariaSort(GridColumn $column): ?string
    {
        if (! $column->isSortable() || $this->sort() !== $column->sort) {
            return null;
        }

        return $this->ascending() ? 'ascending' : 'descending';
    }

    public function pageUrl(int $page): string
    {
        return $this->urlWith([$this->param('page') => $page <= 1 ? null : $page]);
    }

    /**
     * Where the extract goes.
     *
     * The whole view state travels in the URL and is re-applied server-side, so
     * what comes down is what the user is looking at (feature-rules §3.2). A
     * malformed state degrades to an unfiltered extract rather than failing the
     * download — nothing here can throw on a value it does not recognise.
     */
    public function extractUrl(string $format): string
    {
        $query = array_filter($this->query, fn (mixed $v) => $v !== null && $v !== '' && $v !== []);
        $query['format'] = $format;
        $query['columns'] = implode(',', array_map(
            fn (GridColumnView $c) => $c->key(),
            $this->visibleColumns(),
        ));

        return route('app.grids.extract', ['grid' => $this->key()]).'?'.http_build_query($query);
    }

    public function stateUrl(): string
    {
        return route('app.grids.columns.store', ['grid' => $this->key()]);
    }

    /**
     * The query-string name for one of the grid's own parameters.
     *
     * Prefixed with the grid's instance suffix when a screen carries two grids
     * (`app.cash.dropsafe:bags` → `bags_sort`), so sorting one does not page
     * the other. A screen with a single grid keeps the short names, because
     * those are the URLs people paste.
     */
    public function param(string $name): string
    {
        $suffix = $this->definition->key();
        $position = strrpos($suffix, ':');

        return $position === false ? $name : substr($suffix, $position + 1).'_'.$name;
    }
}
