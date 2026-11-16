<?php

namespace Modules\Recon\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;

/**
 * The bank recon pivot as a file (16 Nov 2026) — a GridDefinition for the
 * extract writers and nothing else.
 *
 * The pivot is not a grid: its columns are the areas or the days the scope
 * asks for, and its rows are added up from one cached procedure answer by
 * ReconPivot. But CsvWriter and XlsxWriter read a GridExtract, and a
 * GridExtract carries a definition for its title, its file name and its
 * ceiling — so this is that definition, built per download from
 * ReconPivot::sheet() and handed to GridExtract::fromRows().
 *
 * DELIBERATELY NOT REGISTERED in config/grids.php. A registered
 * `app.recon.dashboard:pivot` key would make GridService read `pivot_*`
 * parameters and open a second door to the rows through the generic extract
 * endpoint; the dashboard's own export route is the only one.
 *
 * The source is named for the procedure the figures come from and is never
 * paged: the rows arrive already built.
 */
final class ReconPivotSheet extends GridDefinition
{
    /**
     * @param  string  $title  ReconPivot::sheet()'s title; the file name is its slug and the date
     * @param  list<GridColumn>  $columns  ReconPivot::sheet()'s columns, in file order
     */
    public function __construct(
        private readonly string $title,
        private readonly array $columns,
    ) {}

    public function key(): string
    {
        return 'app.recon.dashboard:pivot';
    }

    public function title(): string
    {
        return $this->title;
    }

    public function columns(): array
    {
        return $this->columns;
    }

    public function source(): GridSource
    {
        return new ProcedureSource('agora.usp_Recon_DashboardOutstanding');
    }

    /** The dashboard, whose permission and menu entry guard the file. */
    public function screenRoute(): string
    {
        return 'app.recon.dashboard';
    }
}
