<?php

namespace Modules\Masters\Grids;

use App\Grid\GridColumn;

/** Setup → Sites and forecourt → Tanks (T022): PumpIT's dbo.BRN_Tank, per branch. Read-only. */
class TankGrid extends ReferenceMasterGrid
{
    public function slug(): string
    {
        return 'tanks';
    }

    public function resource(): string
    {
        return 'tanks';
    }

    public function procedureName(): string
    {
        return 'agora.usp_Masters_GridTanks';
    }

    public function legacySource(): string
    {
        return 'dbo.BRN_Tank';
    }

    public function noun(): string
    {
        return 'Tank';
    }

    public function eyebrow(): string
    {
        return 'Setup · Sites and forecourt';
    }

    public function title(): string
    {
        return 'Tanks';
    }

    public function blurb(): ?string
    {
        return 'The tanks each forecourt holds, the grades drawn from them and the pumps on each.';
    }

    public function defaultSort(): ?string
    {
        return 'TankNo';
    }

    public function columns(): array
    {
        return [
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('TankNo', 'Tank no', sort: 'TankNo', mono: true),
            new GridColumn('TankDescription', 'Tank', sort: 'TankDescription', link: true, wide: true),
            new GridColumn('FuelTypes', 'Grades', sort: 'FuelTypes'),
            new GridColumn('PumpCount', 'Pumps', format: 'number', sort: 'PumpCount'),
            new GridColumn('CreateDateTime', 'Created in PumpIT', format: 'datetime', filter: 'none', visible: false),
        ];
    }

    public function meanings(): array
    {
        return [
            'FuelTypes' => 'The grades of the pumps drawing from this tank, at this site.',
        ];
    }

    public function related(): array
    {
        return [['Pumps', 'No pump draws from this tank.']];
    }
}
