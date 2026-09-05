<?php

namespace App\Grid\Export;

/**
 * The extract is bigger than a request cycle should carry.
 *
 * feature-rules §3.2: above 100 000 rows the grid refuses and sends the user to
 * the Export centre, which produces the same file out of band. Refusing is the
 * feature — a browser waiting four minutes on a download that may still time
 * out is not an extract, it is an outage with a progress bar.
 */
class GridExportTooLarge extends \RuntimeException
{
    public function __construct(public readonly int $total, public readonly int $ceiling)
    {
        parent::__construct(
            'This answer is '.number_format($total).' rows and the grid extracts up to '
            .number_format($ceiling).'. Narrow it, or send it to the export centre, which '
            .'produces the file out of the request cycle.'
        );
    }
}
