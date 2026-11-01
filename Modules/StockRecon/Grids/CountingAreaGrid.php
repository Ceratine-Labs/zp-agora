<?php

namespace Modules\StockRecon\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use Modules\StockRecon\Services\CountingAreaService;

class CountingAreaGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.stockrecon.countingareas';
    }

    public function title(): string
    {
        return 'Counting areas';
    }

    public function branchSelector(): bool
    {
        return true;
    }

    public function detailMode(): string
    {
        return 'expand';
    }

    public function columns(): array
    {
        return [
            new GridColumn('AreaDescription', 'Counting area', sort: 'AreaDescription', wide: true, link: true),
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('AreaNo', 'Area no', format: 'number', sort: 'AreaNo'),
            new GridColumn('AreaGroup', 'Area group', sort: 'AreaGroup'),
            new GridColumn('IsPreparedFood', 'Prepared food', format: 'bool', options: ['Yes', 'No']),
            new GridColumn('State', 'Status', format: 'chip', options: ['Proposed', 'Confirmed']),
            new GridColumn('FoodBasis', 'Basis', wide: true),
            new GridColumn('ConfirmedAt', 'Confirmed at', format: 'instant'),
            new GridColumn('ConfirmedByName', 'Confirmed by'),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource('agora.usp_StockRecon_GridCountingAreas', acceptsFilters: true,
            extra: CountingAreaService::access());
    }

    public function rowUrl(object $row): ?string
    {
        return route('app.stockrecon.countingareas.show', ['branch' => $row->BranchId, 'area' => $row->AreaNo]);
    }

    public function detailUrl(object $row): ?string
    {
        return $this->rowUrl($row);
    }
}
