<?php

namespace Modules\StockRecon\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;

/**
 * What balancing must refuse to hide — the exception report.
 *
 * THIS IS THE HALF WORTH MORE THAN THE BALANCING, and it is a real grid rather
 * than a hand-rolled table because it is exactly the shape feature-rules §3 is
 * about: thousands of rows, header filters, an export, saved column widths, and
 * a footer total the reader is going to take to a branch meeting.
 *
 * It reads a RUN rather than the source. The classification needs the same
 * chain arithmetic the balancing does — the running total, the dormant test,
 * the active-shift denominators — and computing it a second time would put the
 * same rules in two procedures, which is how two screens end up disagreeing
 * about how many exceptions a branch has. A preview writes nothing to the
 * customer's estate, so running one to get this report costs two seconds.
 *
 * DEFAULT ORDER IS WORST CLASS FIRST, THEN BIGGEST MONEY. That is the order
 * somebody actually works the list in, and it puts the A-class rows — stock
 * that entered the store with no paperwork — above every counting habit.
 */
class StockReconExceptionGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.stockrecon.exceptions';
    }

    public function title(): string
    {
        return 'Stock recon exceptions';
    }

    public function blurb(): ?string
    {
        return 'Every line the balancing reported instead of amending. A-class rows are stock that '
            .'entered the store without an issue being captured and no amendment can remove them; '
            .'B is a data fault; C is a count that was never taken; D is the residual short that '
            .'survives balancing and is the only class that can carry a charge.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'ExceptionCode', label: 'Class', sort: 'ExceptionCode', format: 'chip'),
            new GridColumn(key: 'Outcome', label: 'What it is', sort: 'Outcome', wide: true),
            new GridColumn(key: 'AreaName', label: 'Area', sort: 'AreaName'),
            new GridColumn(key: 'ItemDescription', label: 'Item', sort: 'ItemDescription', wide: true, link: true),
            new GridColumn(key: 'POSCode', label: 'POS code', sort: 'POSCode', mono: true),
            new GridColumn(key: 'StockLocation', label: 'Location', visible: false),
            new GridColumn(key: 'TransactionDate', label: 'Date', format: 'date', sort: 'TransactionDate'),
            new GridColumn(key: 'ShiftNo', label: 'Shift', format: 'number'),
            new GridColumn(key: 'QtyOpen', label: 'Open', format: 'number'),
            new GridColumn(key: 'QtyIssued', label: 'Issued', format: 'number'),
            new GridColumn(key: 'QtyClose', label: 'Close', format: 'number'),
            new GridColumn(key: 'QtyPOS', label: 'POS', format: 'number'),
            new GridColumn(key: 'QtyVar', label: 'Variance', format: 'number', sort: 'QtyVar'),
            new GridColumn(key: 'ChainNetVar', label: 'Chain total', format: 'number',
                title: 'T — the whole window\'s variance for this item. Fixed by the dates; no amendment changes it.'),
            new GridColumn(key: 'ActiveLen', label: 'Active shifts', format: 'number', visible: false,
                title: 'Dormant shifts are out of every denominator on this report'),
            new GridColumn(key: 'SellPrice', label: 'Price', format: 'money', visible: false),
            new GridColumn(key: 'ExceptionValue', label: 'Value', format: 'money',
                sort: 'ExceptionValue', total: true,
                title: 'What this exception is worth at the price the shift was selling at'),
        ];
    }

    /**
     * Keyed by COLUMN, which is what the header row looks each column up by.
     *
     * The class list is the tick-list filter, and its options come from config
     * so the legend on the screen, the chip on the row and the filter cannot
     * end up three different vocabularies.
     *
     * @return array<string, GridFilter>
     */
    public function filters(): array
    {
        return [
            'ExceptionCode' => new GridFilter(column: 'ExceptionCode', type: 'set',
                options: array_keys((array) config('stockrecon.exceptions'))),
            'AreaName' => new GridFilter(column: 'AreaName', type: 'text'),
            'ItemDescription' => new GridFilter(column: 'ItemDescription', type: 'text'),
            'POSCode' => new GridFilter(column: 'POSCode', type: 'text'),
            'TransactionDate' => new GridFilter(column: 'TransactionDate', type: 'date'),
            'ExceptionValue' => new GridFilter(column: 'ExceptionValue', type: 'number'),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource(
            'agora.usp_StockRecon_GridExceptions',
            acceptsFilters: true,
            extra: ['RunId' => $this->runId()],
        );
    }

    public function defaultSort(): ?string
    {
        // Null, so the procedure's own ordering stands: worst class first, and
        // inside a class the biggest money first.
        return null;
    }

    /** A run is about one site; the scope came with the run. */
    public function branchSelector(): bool
    {
        return false;
    }

    /**
     * The item's own resource, which is the chain it sits in.
     *
     * feature-rules §3.7: a name that leads somewhere is a link, and a name
     * that leads nowhere is not rendered as one. The chain is where every
     * question this row raises is answered.
     */
    public function rowUrl(object $row): ?string
    {
        $runId = $this->runId();

        return $runId === null ? null : route('app.stockrecon.line', ['run' => $runId, 'line' => $row->Id]);
    }

    /**
     * The run these rows belong to, off the request.
     *
     * Null rather than a guess when it is absent or not a number. 0 then
     * matches nothing in the procedure, so an extract asked for without a run
     * comes back empty rather than as every exception in the estate.
     */
    private function runId(): ?int
    {
        $run = request()->query('run');

        return is_numeric($run) ? (int) $run : null;
    }
}
