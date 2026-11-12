<?php

namespace Modules\Fuel\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Gate;

class FuelPriceGrid extends GridDefinition
{
    public function dataset(): string
    {
        return 'periods';
    }

    public function key(): string
    {
        return 'app.fuel.matrix';
    }

    public function title(): string
    {
        return 'DOE price matrix';
    }

    public function blurb(): ?string
    {
        return 'Literal DOE periods that overlap the selected dates. Overlapping periods remain separate.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName'),
            new GridColumn(key: 'FuelTypeNo', label: 'Grade no', format: 'number', sort: 'FuelTypeNo'),
            new GridColumn(key: 'FuelTypeDescription', label: 'Grade', sort: 'FuelTypeDescription'),
            new GridColumn(key: 'StartDate', label: 'From', format: 'date', sort: 'StartDate'),
            new GridColumn(key: 'EndDate', label: 'Through', format: 'date', sort: 'EndDate'),
            ...$this->prices(),
            new GridColumn(key: 'FleetMargin', label: 'Fleet margin / ℓ', format: 'money', sort: 'FleetMargin'),
            new GridColumn(key: 'SourceNote', label: 'Source notes', filter: 'none', wide: true),
        ];
    }

    /** @return array<int, GridColumn> */
    protected function prices(): array
    {
        return [
            new GridColumn(key: 'CostPrice', label: 'Cost / ℓ', format: 'money', sort: 'CostPrice'),
            new GridColumn(key: 'SellingPrice', label: 'Sell / ℓ', format: 'money', sort: 'SellingPrice'),
            new GridColumn(key: 'Margin', label: 'Margin / ℓ', format: 'money', sort: 'Margin'),
        ];
    }

    public function source(): GridSource
    {
        Gate::authorize('fuel.prices.view');

        return new ProcedureSource('usp_Fuel_GridPrices', acceptsFilters: true, extra: [
            'Dataset' => $this->dataset(), 'AllowedBranchIds' => self::allowed(),
        ]);
    }

    public static function allowed(): ?string
    {
        $context = app(BranchContext::class);
        $allowed = $context->isBranchWorkspace() ? [$context->id() ?? -1] : $context->allowed();

        return $allowed === [] ? null : implode(',', $allowed);
    }

    public function dateRange(): bool
    {
        return true;
    }

    public function cellsPartial(): string
    {
        return 'fuel::cells';
    }

    public function detailMode(): string
    {
        return 'expand';
    }

    public function rowActions(object $row): array
    {
        return [['label' => 'Source detail', 'url' => $this->detailUrl($row)]];
    }

    public function detailUrl(object $row): ?string
    {
        $date = substr((string) ($row->StartDate ?? $row->TradingDate), 0, 10);

        return route('app.fuel.detail', ['dataset' => $this->dataset(), 'key' => $row->RowKey, 'from' => $date, 'to' => $date]);
    }
}
