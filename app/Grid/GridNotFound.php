<?php

namespace App\Grid;

/**
 * A GridKey nothing in `config/grids.php` answers to.
 *
 * Its own type rather than a generic 404, because the two callers want
 * different things from it: a screen turns it into a NotFoundHttpException,
 * and the column-state endpoint refuses the write — a layout saved under a key
 * no grid claims is a row nothing will ever read again.
 */
class GridNotFound extends \RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct("No grid is registered as [{$key}] in config/grids.php.");
    }
}
