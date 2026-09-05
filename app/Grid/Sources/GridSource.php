<?php

namespace App\Grid\Sources;

use App\Grid\GridPage;
use App\Grid\GridQuery;

/**
 * Where a grid's rows come from.
 *
 * Two implementations, and the grid cannot tell them apart:
 *
 *   ProcedureSource — a `agora.usp_{Module}_Grid{Object}`, which is what every
 *                     user-facing grid uses (feature-rules §2). The procedure
 *                     filters, sorts, pages and counts.
 *   EloquentSource  — a query builder, for the machinery grids that are not
 *                     exposure: a dev surface, an admin list of our own rows.
 *                     Wrapping one of those in T-SQL satisfies a rule that was
 *                     about the customer's visibility and nothing else.
 *
 * Both page SERVER-SIDE. Neither returns more than it was asked for. A source
 * that returns everything and lets the caller slice is the thing this
 * interface exists to make impossible: at ten thousand rows the page is
 * already gone before the slice happens.
 */
interface GridSource
{
    /** One page of rows, plus the size of the whole filtered set. */
    public function page(GridQuery $query): GridPage;

    /**
     * The name the grid displays so the customer can go and open it
     * (feature-rules §3.4), or null when there is nothing for them to open.
     */
    public function name(): ?string;

    /** Whether this source can answer a per-column header filter at all. */
    public function supportsFilters(): bool;
}
