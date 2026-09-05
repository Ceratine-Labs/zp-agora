<?php

namespace App\Grid;

use Illuminate\Support\Collection;

/**
 * One page of rows, and how many there are altogether.
 *
 * `total` is the count of the whole filtered set, not of the page — it is what
 * makes "showing 50 of 12 480" possible and what decides whether an extract is
 * inside the ceiling. A source that cannot say has a bug, not a shortcut: the
 * grid procedure contract returns it as result set 2 for exactly this reason.
 *
 * `totals` is optional and comes from a source that returns a third result
 * set: a grand total over the whole filtered set rather than over the page.
 * Where it is absent the service adds up the page, and the footer says which
 * of the two it is showing — "total of the page" and "total of the answer" are
 * different numbers and a footer that does not distinguish them is a lie
 * waiting to be quoted.
 *
 * `ms` is how long the source took. It is on the grid as a small note, because
 * a procedure over a big table is the thing the customer will ask about, and
 * an answer that starts with a measured number is a shorter conversation.
 */
final class GridPage
{
    /**
     * @param  Collection<int, object>  $rows
     * @param  array<string, float|int|null>  $totals  grand totals from the source, keyed by column
     */
    public function __construct(
        public Collection $rows,
        public int $total,
        public array $totals = [],
        public float $ms = 0.0,
    ) {}
}
