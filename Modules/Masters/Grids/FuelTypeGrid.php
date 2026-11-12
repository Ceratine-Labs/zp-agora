<?php

namespace Modules\Masters\Grids;

use App\Grid\GridColumn;

/** Setup → Sites and forecourt → Fuel types (T022): PumpIT's dbo.BRN_FuelType, per branch. Read-only. */
class FuelTypeGrid extends ReferenceMasterGrid
{
    public function slug(): string
    {
        return 'fuel-types';
    }

    public function resource(): string
    {
        return 'fueltypes';
    }

    public function procedureName(): string
    {
        return 'agora.usp_Masters_GridFuelTypes';
    }

    public function legacySource(): string
    {
        return 'dbo.BRN_FuelType';
    }

    public function noun(): string
    {
        return 'Fuel type';
    }

    public function eyebrow(): string
    {
        return 'Setup · Sites and forecourt';
    }

    public function title(): string
    {
        return 'Fuel types';
    }

    public function blurb(): ?string
    {
        return 'The grades each forecourt sells. A fuel-type number names a different grade at another site, so every lookup is per branch.';
    }

    public function defaultSort(): ?string
    {
        return 'FuelTypeNo';
    }

    public function columns(): array
    {
        return [
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('FuelTypeNo', 'Fuel type no', format: 'number', sort: 'FuelTypeNo'),
            new GridColumn('FuelTypeDescription', 'Fuel type', sort: 'FuelTypeDescription', link: true, wide: true),
            new GridColumn('FuelCode', 'Code', sort: 'FuelCode', mono: true, visible: false),
            new GridColumn('POSType', 'POS', sort: 'POSType', mono: true),
            new GridColumn('POSIdentifier', 'POS identifier', sort: 'POSIdentifier', mono: true),
            new GridColumn('IsFleetCard', 'Fleet card', format: 'bool', options: ['Yes', 'No']),
            new GridColumn('HandlingFee', 'Handling fee', format: 'money', sort: 'HandlingFee'),
            new GridColumn('PumpCount', 'Pumps', format: 'number', sort: 'PumpCount'),
            new GridColumn('TankCount', 'Tanks', format: 'number', sort: 'TankCount'),
        ];
    }

    public function meanings(): array
    {
        return [
            'FuelTypeNo' => 'Numbered per site: FuelTypeDescription is not unique over FuelTypeNo across branches.',
            'PumpCount' => 'Pumps of this grade at this site (BRN_Pump).',
            'TankCount' => 'Tanks those pumps draw from.',
        ];
    }

    public function related(): array
    {
        return [['Pumps', 'No pump at this site dispenses this grade.']];
    }

    public function writeEntity(): ?string
    {
        return 'FuelType';
    }

    public function saveProcedure(): ?string
    {
        return 'agora.usp_Masters_SaveFuelType';
    }

    public function editableFields(): array
    {
        return [
            'FuelTypeDescription' => ['label' => 'Fuel type name', 'type' => 'text', 'column' => 'FuelTypeDescription', 'max' => 35,
                'help' => 'This site\'s row only. Pumps, tanks and prices name the fuel type by number, so they follow. The POS link, fleet-card flag and handling fee are not changed from here.'],
        ];
    }
}
