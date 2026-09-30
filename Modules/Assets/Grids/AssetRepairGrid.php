<?php

namespace Modules\Assets\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use Modules\Assets\Services\AssetsService;

/**
 * Repair job cards, with the comeback flag (T057).
 *
 * A comeback is a second call on the same asset within 30 days of the call
 * before it — agora.vw_AssetRepairCall, not this class and not the procedure.
 * The cost is the approved purchase request's (agora.vw_AssetRepairCost); a
 * request paying for several cards shows on each, and the footer counts it
 * once.
 *
 * "Comebacks only" is the tick list on the Comeback column, so a filtered
 * link can be sent to whoever manages the supplier.
 */
class AssetRepairGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.assets.repairs';
    }

    public function title(): string
    {
        return 'Repair job cards';
    }

    public function blurb(): ?string
    {
        return 'Job cards by asset and supplier. A comeback is a second call on the same asset within 30 days '
            .'of the one before; it counts against the supplier whose repair did not hold. The cost is the '
            .'approved purchase request\'s.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'JobCardNo', label: 'Job card', sort: 'JobCardNo', mono: true, link: true),
            new GridColumn(key: 'CallDate', label: 'Called', format: 'date', sort: 'CallDate'),
            new GridColumn(key: 'AssetNo', label: 'Asset no', sort: 'AssetNo', mono: true),
            new GridColumn(key: 'AssetTypeDescription', label: 'Type'),
            new GridColumn(key: 'SiteName', label: 'Site', sort: 'SiteName',
                title: 'Where the asset stood when it was called in — the site whose request pays for it'),
            new GridColumn(key: 'Problem', label: 'Problem', wide: true),
            new GridColumn(key: 'SupplierName', label: 'Supplier', sort: 'SupplierName'),
            new GridColumn(key: 'SupplierCode', label: 'Supplier code', mono: true, visible: false, filter: 'none'),
            new GridColumn(key: 'SupplierReference', label: 'Supplier ref', visible: false, filter: 'none'),
            new GridColumn(key: 'TransactionNo', label: 'Purchase request', mono: true),
            new GridColumn(key: 'RequestState', label: 'Request', format: 'chip', filter: 'set',
                options: ['No request yet', 'Awaiting approval', 'Approved', 'Declined', 'Request not found']),
            new GridColumn(key: 'RepairCost', label: 'Cost', format: 'money', sort: 'RepairCost', total: true,
                title: 'The approved purchase request\'s amount. The footer counts a request shared by several cards once.'),
            new GridColumn(key: 'SharedBy', label: 'Cards on request', format: 'number', visible: false, filter: 'none'),
            new GridColumn(key: 'DateExpectedReturn', label: 'Expected back', format: 'date', visible: false),
            new GridColumn(key: 'DateActualReturn', label: 'Back / done', format: 'date'),
            new GridColumn(key: 'DaysOut', label: 'Days out', format: 'number', sort: 'DaysOut', visible: false),
            new GridColumn(key: 'IsComeback', label: 'Comeback', filter: 'set', options: ['Yes', 'No']),
            new GridColumn(key: 'DaysSincePrevious', label: 'Days since last call', format: 'number', visible: false),
            new GridColumn(key: 'PrevJobCardNo', label: 'Previous card', mono: true, visible: false, filter: 'none'),
            new GridColumn(key: 'PrevSupplierName', label: 'Previous supplier', visible: false,
                title: 'Whose repair did not hold, when this call is a comeback'),
            new GridColumn(key: 'Result', label: 'Result', format: 'chip', sort: 'Result', filter: 'set',
                options: ['Comeback', 'Overdue', 'Late', 'Open', 'Done', 'Cancelled']),
            new GridColumn(key: 'BranchName', label: 'Belongs to', visible: false, filter: 'none'),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource(
            'agora.usp_Assets_GridRepairs',
            acceptsFilters: true,
            extra: ['AllowedBranchIds' => app(AssetsService::class)->allowedBranchIds()],
        );
    }

    public function cellsPartial(): string
    {
        return 'assets::grid._cells';
    }

    public function dateRange(): bool
    {
        return true;
    }

    public function branchSelector(): bool
    {
        return true;
    }

    public function rowUrl(object $row): ?string
    {
        return route('app.assets.repairs.show', ['branch' => $row->BranchId, 'repair' => $row->RepairId]);
    }
}
