<?php

namespace Modules\Fuel\Grids;

use App\Grid\GridColumn;

class FuelHistoryGrid extends FuelPriceGrid
{
    public function dataset(): string
    {
        return 'observations';
    }

    public function key(): string
    {
        return 'app.fuel.history';
    }

    public function title(): string
    {
        return 'POS price observations';
    }

    public function blurb(): ?string
    {
        return 'Dates filter the trading date. Every source-keyed observation remains separate, including different prices on the same day.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName'),
            new GridColumn(key: 'FuelTypeNo', label: 'Grade no', format: 'number', sort: 'FuelTypeNo'),
            new GridColumn(key: 'FuelTypeDescription', label: 'Grade', sort: 'FuelTypeDescription'),
            new GridColumn(key: 'TradingDate', label: 'Trading date', format: 'date', sort: 'TradingDate'),
            new GridColumn(key: 'SourceDate', label: 'Source date', format: 'date', sort: 'SourceDate'),
            ...$this->prices(),
            new GridColumn(key: 'IsFleetCard', label: 'Fleet', format: 'bool', sort: 'IsFleetCard', options: ['0', '1']),
            new GridColumn(key: 'SourceNote', label: 'Source notes', filter: 'none', wide: true),
            new GridColumn(key: 'POSType', label: 'POS type', sort: 'POSType', visible: false),
            new GridColumn(key: 'POSIdentifier', label: 'POS identifier', sort: 'POSIdentifier', visible: false),
            new GridColumn(key: 'EodCounter', label: 'EOD', sort: 'EodCounter', visible: false),
            new GridColumn(key: 'HandlingFee', label: 'Handling fee', format: 'money', sort: 'HandlingFee', visible: false),
            new GridColumn(key: 'SellingPriceMatrix', label: 'Matrix sell / ℓ', format: 'money', sort: 'SellingPriceMatrix', visible: false),
            new GridColumn(key: 'SalesVariance', label: 'Source variance', format: 'money', sort: 'SalesVariance', visible: false),
        ];
    }
}
