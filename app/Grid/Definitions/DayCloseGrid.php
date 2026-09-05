<?php

namespace App\Grid\Definitions;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;

/**
 * Day close status, through `<x-data-grid>`.
 *
 * This is the migration target, not a mock-up. It is the SAME procedure the
 * Reports module already renders through `<x-table>` —
 * `usp_Reports_GridDayClose` — declared as a GridDefinition instead of as a
 * `columns` array in `Modules/Reports/Config/config.php`. Put the two screens
 * side by side and the difference is the header filters, the extract, the
 * column chooser and the persistence; the procedure and the figures are
 * identical, which is the point.
 *
 * The columns are the report's own, in its order and its wording. The formats
 * are the same words too — `text` `number` `money` `date` `chip`. The
 * catalogue's vocabulary is a SUPERSET of `<x-reports::cell>`'s rather than a
 * rival to it: it adds `rk` `lk` `pct` `cpl` `litres` `delta` `mono` for the
 * tiles and margins the reports do not carry yet, and every type the reports do
 * use means the same thing here and is written by the same Format call.
 *
 * The procedure takes the eight parameters and no @FiltersJson, so this grid
 * declares no per-column header filters: a filter this source cannot answer
 * would have to be answered in PHP, and that is the one thing feature-rules
 * §3.2 tells us not to do. The global @Search is what it has, and it works.
 */
class DayCloseGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.dev.grids:dayclose';
    }

    public function title(): string
    {
        return 'Day close status';
    }

    public function blurb(): ?string
    {
        return 'Which sites have finished the day, which are still open, and what is holding each one up. '
            .'The same procedure the Reports module runs, rendered through the grid instead of the table.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName'),
            new GridColumn(key: 'ReconDate', label: 'Trading day', format: 'date', sort: 'ReconDate'),
            new GridColumn(key: 'Status', label: 'Status', format: 'chip', sort: 'Status'),
            new GridColumn(key: 'HoldingUp', label: 'Holding it up', sort: 'HoldingUp', wide: true),
            new GridColumn(key: 'StepsDone', label: 'Steps done', format: 'number', sort: 'StepsDone'),
            new GridColumn(key: 'ReconsOutstanding', label: 'Recons open', format: 'number', sort: 'ReconsOutstanding', total: true),
            new GridColumn(key: 'Cashups', label: 'Cashups', format: 'number', sort: 'Cashups', total: true),
            // No `sort` — the procedure's CASE has no key for it, and a header
            // that links to a sort the procedure silently ignores is worse
            // than one that does not link at all.
            new GridColumn(key: 'DayEnds', label: 'Day ends', format: 'number', visible: false),
            new GridColumn(key: 'CashierShort', label: 'Cashier short', format: 'money', sort: 'CashierShort', total: true),
            new GridColumn(key: 'PumpShort', label: 'Pump short', format: 'money', sort: 'PumpShort', total: true),
            new GridColumn(key: 'TotalAmount', label: 'Total', format: 'money', sort: 'TotalAmount', total: true),
            new GridColumn(key: 'EODNo', label: 'EOD no', sort: 'EODNo', mono: true, visible: false),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource('usp_Reports_GridDayClose');
    }

    public function defaultSort(): ?string
    {
        return 'ReconDate';
    }

    public function defaultDirection(): string
    {
        return 'desc';
    }

    public function dateRange(): bool
    {
        return true;
    }
}
