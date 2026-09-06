<?php

namespace App\Grid;

use App\Grid\Sources\GridSource;

/**
 * One grid, declared once.
 *
 * A definition says what the grid is called, what its columns are, where its
 * rows come from and what a row leads to. Nothing else knows any of that — not
 * the controller, not the blade, not the exporter — so a column added here
 * appears in the header, the filter row, the chooser, the mobile cards and the
 * extract without another file being touched.
 *
 * `key()` is the GridKey, and feature-rules §3.6 is specific about what it is:
 * the ROUTE NAME (`app.cash.dropsafe`), qualified when a screen carries more
 * than one grid (`app.cash.dropsafe:bags`). Not the class name — one controller
 * serves several screens, and a class rename would silently orphan every
 * user's saved layout with no error anywhere.
 *
 * The catalogue is the contract. `columns()` is called on every request, so it
 * must be cheap and must not touch the database: a definition builds a set-
 * filter's options from a list it is given, never from a query it runs itself.
 */
abstract class GridDefinition
{
    /** The GridKey — the route name, qualified where a screen has two grids. */
    abstract public function key(): string;

    /** What the grid is called on the screen. */
    abstract public function title(): string;

    /** @return array<int, GridColumn> */
    abstract public function columns(): array;

    abstract public function source(): GridSource;

    /**
     * The procedure name the grid displays (feature-rules §3.4).
     *
     * Taken from the source, which knows it; overridable for the rare grid
     * whose rows come from one procedure but whose customer-facing object is
     * another.
     */
    public function procedure(): ?string
    {
        return $this->source()->name();
    }

    /**
     * The blade that writes the cell contents. The SHELL owns the structure —
     * the wrapper, the header, the sort marks, the row, the `<td>` and its
     * classes; the PARTIAL owns what goes inside the cell. A grid that needs a
     * cell the default vocabulary cannot write ships its own partial and
     * changes nothing else.
     */
    public function cellsPartial(): string
    {
        return 'grid._cells';
    }

    /** Sort value applied when the user has not chosen one. */
    public function defaultSort(): ?string
    {
        return null;
    }

    /** `asc` or `desc`. */
    public function defaultDirection(): string
    {
        return 'asc';
    }

    public function pageSize(): int
    {
        return (int) config('grids.page_size', 50);
    }

    /** Above this many rows an extract is refused and sent to the export centre. */
    public function exportCeiling(): int
    {
        return (int) config('grids.export_ceiling', 100000);
    }

    /**
     * The header filters, derived from the catalogue unless overridden.
     *
     * @return array<string, GridFilter>
     */
    public function filters(): array
    {
        $filters = [];

        foreach ($this->columns() as $column) {
            if ($column->filter === 'none') {
                continue;
            }

            $filters[$column->key] = GridFilter::forColumn($column);
        }

        return $filters;
    }

    /**
     * The label on the scope form's submit button.
     *
     * "Apply filters" by default, because that is what the button does on
     * almost every grid: it puts the search box and the dates into the query
     * string and reloads. It said "Run" on all of them, which is a promise of
     * consequence the control does not keep — a list of user accounts under a
     * bright button labelled Run reads as though something is about to happen
     * to those accounts. A control says exactly what it does.
     *
     * A grid whose submit genuinely starts work overrides this AND
     * submitIsPrimary(), so the two travel together.
     */
    public function submitLabel(): string
    {
        return 'Apply filters';
    }

    /**
     * Whether that button is the page's primary action.
     *
     * False by default. On a read-only grid the submit is housekeeping, and
     * painting it as the primary action makes it compete with the controls
     * that do change something. A grid that really does run a job says so.
     */
    public function submitIsPrimary(): bool
    {
        return false;
    }

    /** Whether a person may type into the column headers at all. */
    public function filterable(): bool
    {
        return $this->source()->supportsFilters();
    }

    /** Whether the grid carries a global search box. */
    public function searchable(): bool
    {
        return true;
    }

    /**
     * How a row opens its detail (feature-rules §3.5).
     *
     *   panel  — a panel on the right-hand side of the grid. The rule's shape.
     *   expand — the detail opens underneath the row, which is what an
     *            aggregate wants: "which lines" belongs under the total it
     *            explains. Driven by resources/js/components/row-detail.js,
     *            which already owns the `data-row-detail` markup contract; the
     *            grid emits it and stays out of the way.
     *   none   — rows do not open.
     */
    public function detailMode(): string
    {
        return 'none';
    }

    /** Where the detail fragment for one row is fetched from, or null. */
    public function detailUrl(object $row): ?string
    {
        return null;
    }

    /**
     * Where a row's own resource lives (feature-rules §3.7) — a route change,
     * not a panel. Null where the row names nothing: a reference that leads
     * nowhere must not be rendered as a link.
     */
    public function rowUrl(object $row): ?string
    {
        return null;
    }

    /** Whether rows carry a tick box for a bulk action. */
    public function selectable(): bool
    {
        return false;
    }

    /**
     * What a selected row is identified by. Required when selectable(); the
     * grid refuses to render tick boxes it cannot name.
     */
    public function rowKey(object $row): string|int|null
    {
        return null;
    }

    /**
     * A head-office grid carries the branches selector (feature-rules §3.3).
     * In the branch workspace BranchContext has already pinned the site, so it
     * does not appear and whatever arrives is ignored.
     */
    public function branchSelector(): bool
    {
        return true;
    }

    /** Whether the grid takes a date range. */
    public function dateRange(): bool
    {
        return false;
    }

    /** A one-line explanation under the title, where the grid needs one. */
    public function blurb(): ?string
    {
        return null;
    }

    /**
     * Column keys carried into the footer total row, derived from the
     * catalogue unless overridden.
     *
     * @return array<int, string>
     */
    public function totals(): array
    {
        return array_values(array_map(
            fn (GridColumn $c) => $c->key,
            array_filter($this->columns(), fn (GridColumn $c) => $c->total),
        ));
    }

    /** @return array<string, GridColumn> keyed by column key */
    final public function catalogue(): array
    {
        $catalogue = [];

        foreach ($this->columns() as $column) {
            $catalogue[$column->key] = $column;
        }

        return $catalogue;
    }

    /**
     * Every sort value the catalogue offers. The whitelist a submitted `sort`
     * is checked against — anything else falls back to the default rather than
     * being passed through, because an unrecognised @SortColumn is silently
     * ignored by the procedure's CASE and silence is worse than a default.
     *
     * @return array<int, string>
     */
    final public function sortValues(): array
    {
        return array_values(array_filter(array_map(
            fn (GridColumn $c) => $c->sort,
            $this->columns(),
        )));
    }
}
