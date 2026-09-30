<?php

namespace Modules\Recon\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use Modules\Recon\Services\ReconService;

/**
 * The deposits behind a run's proposals — the RIGHT side of the expand panel,
 * for a whole run or for one proposal.
 *
 * The counterpart to ReconBankSideGrid, and registered for the same reason:
 * so the standard extract endpoint can serve it. See that class for why these
 * rows cannot be a query.
 *
 * THE DEPOSIT SIDE HAS NO SINGLE ID ACROSS THE FAMILY — ABSA keys on a batch
 * number, FNB on a batch reference, CashMachine on a slip. `SourceRef` is
 * whichever of those this area uses, and `Detail` is the rest of the key the
 * drill could put a name to. That is also why a committed line's deposits are
 * read out of ReconMatch's SourceKeyJson rather than out of the source table:
 * once ReconBatchNoPumpIT is stamped, the table no longer returns them.
 */
class ReconMopsSideGrid extends GridDefinition
{
    public function __construct(private ReconService $service) {}

    public function key(): string
    {
        return 'app.recon.run:mops';
    }

    public function title(): string
    {
        return 'Reconciliation deposits';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'KeyRef', label: 'Reference', mono: true),
            new GridColumn(key: 'KeyRef2', label: 'Second reference', mono: true, visible: false),
            new GridColumn(key: 'Outcome', label: 'Proposal outcome'),
            new GridColumn(key: 'CommitState', label: 'State'),
            new GridColumn(key: 'ReconBatchNo', label: 'Batch', mono: true),
            new GridColumn(key: 'SourceRef', label: 'Deposit reference', mono: true),
            new GridColumn(key: 'SourceDate', label: 'Date', format: 'date'),
            new GridColumn(key: 'Detail', label: 'Detail', wide: true),
            new GridColumn(key: 'Amount', label: 'Amount', format: 'money', total: true),

            // Cash bags only (27 Sep 2026): whose bag it was, what was declared
            // at the drop safe, and the line of the confirmed split the bag's
            // cash was accounted on. The line is the CASHIER's, so it repeats
            // on each of their bags and carries no total — adding it down the
            // bags would count a cashier's share once per bag.
            new GridColumn(key: 'CashierName', label: 'Cashier', visible: $this->cashBags()),
            new GridColumn(key: 'CashierCode', label: 'Cashier code', mono: true, visible: $this->cashBags()),
            new GridColumn(key: 'DropSafeAmount', label: 'Drop safe', format: 'money', total: true, visible: $this->cashBags()),
            new GridColumn(key: 'AllocationBatchNo', label: 'Cashier line: batch', mono: true, visible: $this->cashBags()),
            new GridColumn(key: 'AllocationShare', label: 'Cashier line: share of the variance', format: 'money', visible: $this->cashBags()),
            new GridColumn(key: 'AllocationAccountable', label: 'Cashier line: accountable', format: 'money', visible: $this->cashBags()),
            new GridColumn(key: 'AllocationShort', label: 'Cashier line: staff short', visible: $this->cashBags()),

            new GridColumn(key: 'SourceId', label: 'Source id', mono: true, visible: false),
            new GridColumn(key: 'LineId', label: 'Proposal id', mono: true, visible: false),
        ];
    }

    /**
     * Whether the extract was asked for from a cash-bag run.
     *
     * The run page and the drill panel say so in the link (`area=CashBags`),
     * because the catalogue must not touch the database to find out: the
     * cashier columns are declared for every area, like the CC and DD legs on
     * the proposals, and only shown by default where they can hold anything.
     * A wrong value costs nothing — the columns come out empty.
     */
    private function cashBags(): bool
    {
        return request()->query('area') === 'CashBags';
    }

    public function source(): GridSource
    {
        return new ReconSideSource('mops', $this->service);
    }

    public function searchable(): bool
    {
        return false;
    }

    public function filterable(): bool
    {
        return false;
    }

    public function branchSelector(): bool
    {
        return false;
    }
}
