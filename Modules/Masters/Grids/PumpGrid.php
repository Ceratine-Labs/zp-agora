<?php

namespace Modules\Masters\Grids;

use App\Grid\GridColumn;

/** Setup → Sites and forecourt → Pumps (T022): PumpIT's dbo.BRN_Pump, per branch. Read-only. */
class PumpGrid extends ReferenceMasterGrid
{
    public function slug(): string
    {
        return 'pumps';
    }

    public function resource(): string
    {
        return 'pumps';
    }

    public function procedureName(): string
    {
        return 'agora.usp_Masters_GridPumps';
    }

    public function legacySource(): string
    {
        return 'dbo.BRN_Pump';
    }

    public function noun(): string
    {
        return 'Pump';
    }

    public function eyebrow(): string
    {
        return 'Setup · Sites and forecourt';
    }

    public function title(): string
    {
        return 'Pumps';
    }

    public function blurb(): ?string
    {
        return 'Every pump on every forecourt, in reading order, with its grade and the tank it draws from.';
    }

    public function defaultSort(): ?string
    {
        return 'PumpOrder';
    }

    public function columns(): array
    {
        return [
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('PumpNo', 'Pump', sort: 'PumpNo', link: true, mono: true),
            new GridColumn('PumpOrder', 'Order', format: 'number', sort: 'PumpOrder'),
            new GridColumn('FuelTypeDescription', 'Fuel type', sort: 'FuelTypeDescription'),
            new GridColumn('FuelTypeNo', 'Fuel type no', format: 'number', sort: 'FuelTypeNo', visible: false),
            new GridColumn('TankNo', 'Tank no', sort: 'TankNo', mono: true),
            new GridColumn('TankDescription', 'Tank', sort: 'TankDescription'),
            new GridColumn('POSType', 'POS', sort: 'POSType', mono: true, visible: false),
            new GridColumn('POSIdentifier', 'POS identifier', sort: 'POSIdentifier', mono: true),
            new GridColumn('IsActive', 'Active', format: 'bool', options: ['Yes', 'No']),
        ];
    }

    public function meanings(): array
    {
        return [
            'PumpOrder' => 'BRN_Pump.[Order]: the order the pump readings are captured in.',
            'FuelTypeDescription' => 'The grade, joined at this site — a fuel-type number means a different grade elsewhere.',
            'TankDescription' => 'The tank, joined at this site.',
        ];
    }
}
