<?php

namespace Modules\StockRecon\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use Modules\StockRecon\Services\WaterMeterService;

class WaterMeterGrid extends GridDefinition
{
    public function __construct(private readonly WaterMeterService $meters) {}

    public function key(): string
    {
        return 'app.stockrecon.water';
    }

    public function title(): string
    {
        return 'Water meter reconciliation';
    }

    public function blurb(): ?string
    {
        return 'Cumulative readings in litres, separate from stock counts. A valid interval is the current captured closing reading less the previous shift’s captured closing reading. Invalid intervals carry no calculated volume or loss value.';
    }

    public function cellsPartial(): string
    {
        return 'stockrecon::grid._water-cells';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'TransactionDate', label: 'Date', format: 'date', sort: 'TransactionDate'),
            new GridColumn(key: 'ShiftNo', label: 'Shift', format: 'number'),
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName'),
            new GridColumn(key: 'MeterLabel', label: 'Meter', sort: 'MeterLabel', wide: true),
            new GridColumn(key: 'Status', label: 'Reading status', sort: 'Status'),
            new GridColumn(key: 'PreviousDate', label: 'Previous date', format: 'date'),
            new GridColumn(key: 'PreviousShift', label: 'Previous shift', format: 'number'),
            new GridColumn(key: 'PreviousReading', label: 'Previous reading (L)', format: 'qty', compactQuantity: true),
            new GridColumn(key: 'CurrentReading', label: 'Current reading (L)', format: 'qty', compactQuantity: true),
            new GridColumn(key: 'MeterLitres', label: 'Dispensed (L)', format: 'qty', compactQuantity: true,
                title: 'Only adjacent, unique, nonnegative, nondecreasing captured readings produce a volume'),
            new GridColumn(key: 'POSLitres', label: 'POS litres', format: 'qty', compactQuantity: true,
                title: 'The original POS quantity for this shift; retained even when a meter interval is invalid'),
            new GridColumn(key: 'DifferenceLitres', label: 'Dispensed less POS (L)', format: 'qty', compactQuantity: true, sort: 'DifferenceLitres', total: true,
                title: 'A comparison in litres, never a monetary stock loss'),
            new GridColumn(key: 'RawOpening', label: 'Captured opening', format: 'qty', compactQuantity: true, visible: false),
            new GridColumn(key: 'LiveOpening', label: 'Working opening', format: 'qty', compactQuantity: true, visible: false),
            new GridColumn(key: 'LiveClosing', label: 'Working closing', format: 'qty', compactQuantity: true, visible: false),
            new GridColumn(key: 'LivePOS', label: 'Working POS', format: 'qty', compactQuantity: true, visible: false),
            new GridColumn(key: 'StockItemNo', label: 'Item no', mono: true, visible: false),
            new GridColumn(key: 'StockItemDescription', label: 'Stock master description', visible: false),
            new GridColumn(key: 'MasterUOM', label: 'Stock master UOM', visible: false),
            new GridColumn(key: 'AreaDescription', label: 'Area', visible: false),
        ];
    }

    public function filters(): array
    {
        $filters = ['Status' => new GridFilter('Status', 'set', array_values(WaterMeterService::STATUSES))];
        foreach (['BranchName', 'MeterLabel', 'StockItemNo', 'StockItemDescription', 'MasterUOM', 'AreaDescription'] as $column) {
            $filters[$column] = new GridFilter($column, 'text');
        }
        foreach (['ShiftNo', 'PreviousShift', 'PreviousReading', 'CurrentReading', 'MeterLitres', 'POSLitres',
            'DifferenceLitres', 'RawOpening', 'LiveOpening', 'LiveClosing', 'LivePOS'] as $column) {
            $filters[$column] = new GridFilter($column, 'number');
        }
        foreach (['TransactionDate', 'PreviousDate'] as $column) {
            $filters[$column] = new GridFilter($column, 'date');
        }

        return $filters;
    }

    public function source(): GridSource
    {
        return new ProcedureSource('agora.usp_StockRecon_GridWaterMeters', acceptsFilters: true,
            extra: ['AllowedBranchIds' => $this->meters->allowedBranchIds()]);
    }

    public function totals(): array
    {
        return ['Lines', 'ComparableIntervals', 'InvalidIntervals', 'ComparableMeterLitres', 'ComparablePOSLitres', 'DifferenceLitres'];
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
        return isset($row->BranchId, $row->StockItemNo)
            ? route('app.master.stock.show', ['branch' => $row->BranchId, 'item' => $row->StockItemNo]) : null;
    }

    public function rowClass(object $row): ?string
    {
        return ($row->StatusCode ?? '') === 'valid' ? null : 'is-warn';
    }
}
