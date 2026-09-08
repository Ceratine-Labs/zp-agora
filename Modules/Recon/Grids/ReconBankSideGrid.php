<?php

namespace Modules\Recon\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use Modules\Recon\Services\ReconService;

/**
 * The bank lines behind a run's proposals — the LEFT side of the expand panel,
 * for a whole run or for one proposal.
 *
 * Registered only so the standard extract endpoint can serve it. Nothing
 * renders this as a grid: the screen shows these rows inside the panel, where
 * the narrative carries its marked extraction window and the two sides sit
 * next to each other. A flat table of them is what an extract is FOR.
 *
 * Every row carries the proposal it belongs to, because "which bank lines
 * settled batch 204" is the question somebody opens this file to answer.
 */
class ReconBankSideGrid extends GridDefinition
{
    public function __construct(private ReconService $service) {}

    public function key(): string
    {
        return 'app.recon.run:bank';
    }

    public function title(): string
    {
        return 'Reconciliation bank lines';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'KeyRef', label: 'Reference', mono: true),
            new GridColumn(key: 'KeyRef2', label: 'Second reference', mono: true, visible: false),
            new GridColumn(key: 'Outcome', label: 'Proposal outcome'),
            new GridColumn(key: 'CommitState', label: 'State'),
            new GridColumn(key: 'ReconBatchNo', label: 'Batch', mono: true),
            new GridColumn(key: 'BankStatementLineID', label: 'Bank line', mono: true),
            new GridColumn(key: 'LineDate', label: 'Date', format: 'date'),
            new GridColumn(key: 'Description', label: 'Narrative', wide: true),
            new GridColumn(key: 'Leg', label: 'Leg'),
            new GridColumn(key: 'Amount', label: 'Amount', format: 'money', total: true),
            // What the line held BEFORE this run touched it — the difference
            // between "we reconciled this" and "it was already reconciled".
            new GridColumn(key: 'ReconState', label: 'Recon state', format: 'number', visible: false),
            new GridColumn(key: 'SourceReconBatchNo', label: 'Existing batch', mono: true, visible: false),
            new GridColumn(key: 'LineId', label: 'Proposal id', mono: true, visible: false),
        ];
    }

    public function source(): GridSource
    {
        return new ReconSideSource('bank', $this->service);
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
