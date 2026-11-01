<?php

namespace Modules\StockRecon\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use Modules\StockRecon\Services\WeeklyStockService;

class WeeklyCaseGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.stockrecon.weekly';
    }

    public function title(): string
    {
        return 'Weekly investigation cases';
    }

    public function branchSelector(): bool
    {
        return true;
    }

    public function dateRange(): bool
    {
        return request()->query('snapshot') === null;
    }

    public function detailMode(): string
    {
        return 'expand';
    }

    public function defaultSort(): string
    {
        return 'WeekStart';
    }

    public function defaultDirection(): string
    {
        return 'desc';
    }

    public function columns(): array
    {
        return [
            new GridColumn('WeekStart', 'Week starting', format: 'date', sort: 'WeekStart'),
            new GridColumn('RegisterName', 'Register', filter: 'set', options: ['Item Register', 'Period Items', 'Opening vs Closing', 'Balancing Breaches', 'Issue Sheet Failures', 'Count Compliance', 'Count Recency', 'Recipe vs Recon', 'Potatoes & Buns', 'Control Actions']),
            new GridColumn('PeriodFrom', 'Control period from', format: 'date', sort: 'PeriodFrom', visible: false),
            new GridColumn('PeriodTo', 'Control period through', format: 'date', sort: 'PeriodTo', visible: false),
            new GridColumn('EvidenceDate', 'Evidence date', format: 'date', sort: 'EvidenceDate', visible: false),
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('Description', 'Item', wide: true, link: true, sort: 'Description'),
            new GridColumn('AreaDescription', 'Counting area', sort: 'AreaDescription'),
            new GridColumn('POSCode', 'POS code', sort: 'POSCode'),
            new GridColumn('SourceSystem', 'Count system', filter: 'set', options: ['WINBRANCH', 'AURA', 'PILOT', 'NAMOS']),
            new GridColumn('Scenario', 'Scenario', format: 'chip', options: ['OK', 'A1', 'A2', 'A3', 'B1', 'B2', 'C1', 'C2', 'D1', 'D2', 'D3', 'D4', 'RV', 'NC', 'E1', 'E2', 'E3', 'IR', 'V', 'A4', 'C3', 'S10a', 'S10b', 'S7', 'S8', 'S11', 'S1', 'S3', 'S4', 'S4a', 'S4b', 'RECIPE', 'ACTION']),
            new GridColumn('ReconQty', 'Recon variance', format: 'number', compactQuantity: true, sort: 'ReconQty'),
            new GridColumn('CountQty', 'Site count variance', format: 'number', compactQuantity: true, sort: 'CountQty'),
            new GridColumn('StoreroomQty', 'Count less recon', format: 'number', compactQuantity: true, sort: 'StoreroomQty'),
            new GridColumn('ValueAtRiskSelling', 'Exposure at selling', format: 'money', sort: 'ValueAtRiskSelling'),
            new GridColumn('UnitCost', 'Period unit cost', format: 'number', sort: 'UnitCost', visible: false),
            new GridColumn('CostBasis', 'Cost basis', sort: 'CostBasis', visible: false),
            new GridColumn('ReconValueCost', 'Period recon at cost', format: 'money', sort: 'ReconValueCost', visible: false),
            new GridColumn('CountValueCost', 'Period count at cost', format: 'money', sort: 'CountValueCost', visible: false),
            new GridColumn('StoreroomValueCost', 'Period storeroom at cost', format: 'money', sort: 'StoreroomValueCost', visible: false),
            new GridColumn('ResponsibleRole', 'Responsible role', sort: 'ResponsibleRole', visible: false),
            new GridColumn('Priority', 'Priority', format: 'chip', options: ['Low', 'Medium', 'High']),
            new GridColumn('Status', 'Status', format: 'chip', options: ['Open', 'Investigating', 'Waiting', 'Escalated', 'Closed']),
            new GridColumn('OwnerName', 'Owner', sort: 'OwnerName'),
            new GridColumn('DueDate', 'Due date', format: 'date', sort: 'DueDate'),
            new GridColumn('Finding', 'Written finding', sort: 'Finding', visible: false),
            new GridColumn('EvidenceReference', 'Evidence reference', sort: 'EvidenceReference', visible: false),
            new GridColumn('NextAction', 'Required action', sort: 'NextAction', visible: false),
            new GridColumn('SignedOffAt', 'Approval time', format: 'datetime', sort: 'SignedOffAt', visible: false),
            new GridColumn('ClosureOutcome', 'Closure outcome', sort: 'ClosureOutcome', visible: false),
            new GridColumn('RecoveryAmount', 'Rand charged / recovered', format: 'money', sort: 'RecoveryAmount'),
            new GridColumn('CorrectionStatus', 'Corrected in PumpIT', filter: 'set', options: ['Y', 'N'], visible: false),
            new GridColumn('ActionTaken', 'Action completed', sort: 'ActionTaken', visible: false),
            new GridColumn('ActionDate', 'Action date', format: 'date', sort: 'ActionDate', visible: false),
            new GridColumn('ClosedDate', 'Closed date', format: 'date', sort: 'ClosedDate', visible: false),
            new GridColumn('SignedOffName', 'Head-office approver', sort: 'SignedOffName', visible: false),
            new GridColumn('RecoveryReference', 'Recovery evidence', sort: 'RecoveryReference', visible: false),
            new GridColumn('CorrectionReference', 'Correction evidence', sort: 'CorrectionReference', visible: false),
            new GridColumn('SignoffNote', 'Approval note', sort: 'SignoffNote', visible: false),
            new GridColumn('LastCountDate', 'Last proven count', format: 'date', sort: 'LastCountDate'),
            new GridColumn('SharedCountCases', 'Cases sharing count', format: 'number', sort: 'SharedCountCases'),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource('agora.usp_StockRecon_GridWeeklyCases', acceptsFilters: true,
            extra: ['SnapshotRef' => request()->query('snapshot')] + WeeklyStockService::access());
    }

    public function rowUrl(object $row): ?string
    {
        return route('app.stockrecon.weekly.show', ['branch' => $row->BranchId, 'case' => $row->Id]);
    }

    public function detailUrl(object $row): ?string
    {
        return $this->rowUrl($row);
    }
}
