<?php

namespace Modules\Masters\Grids;

use App\Grid\GridColumn;

/** Setup → The business → Profit centres (T022): PumpIT's SS_ProfitCentre, group-wide. */
class ProfitCentreGrid extends ReferenceMasterGrid
{
    public function slug(): string
    {
        return 'profit-centres';
    }

    public function resource(): string
    {
        return 'profitcentres';
    }

    public function procedureName(): string
    {
        return 'agora.usp_Masters_GridProfitCentres';
    }

    public function legacySource(): string
    {
        return 'dbo.SS_ProfitCentre';
    }

    public function noun(): string
    {
        return 'Profit centre';
    }

    public function perBranch(): bool
    {
        return false;
    }

    public function title(): string
    {
        return 'Profit centres';
    }

    public function blurb(): ?string
    {
        return 'The profit centres every branch reports against. Fuel is five of them, not one.';
    }

    public function defaultSort(): ?string
    {
        return 'ProfitCentreId';
    }

    public function columns(): array
    {
        return [
            new GridColumn('ProfitCentreId', 'Id', format: 'number', sort: 'ProfitCentreId'),
            new GridColumn('Name', 'Profit centre', sort: 'Name', link: true, wide: true),
            new GridColumn('BranchCount', 'Sites running it', format: 'number', sort: 'BranchCount'),
            new GridColumn('CategoryCount', 'POS categories mapped', format: 'number', sort: 'CategoryCount'),
            new GridColumn('NoBandCount', 'Categories with no GP band', format: 'number', sort: 'NoBandCount'),
            new GridColumn('StandardCategories', 'Standard categories', wide: true, visible: false),
            new GridColumn('IsActive', 'Active', format: 'bool', options: ['Yes', 'No']),
        ];
    }

    public function writeEntity(): ?string
    {
        return 'ProfitCentre';
    }

    public function saveProcedure(): ?string
    {
        return 'agora.usp_Masters_SaveProfitCentre';
    }

    public function editableFields(): array
    {
        return [
            'IsActive' => ['label' => 'Active', 'type' => 'bool', 'column' => 'IsActive',
                'help' => 'The name is not changed from Agora: PumpIT copies it onto every sale. Refused while active categories map to it.'],
        ];
    }

    public function meanings(): array
    {
        return [
            'BranchCount' => 'Sites whose branch master lists this centre (Setup → Branches).',
            'CategoryCount' => 'Active POS categories PumpIT maps to it, across the sites you can see.',
            'NoBandCount' => 'Active categories under this centre with MinGP and MaxGP both 0 — no "below min GP" check can ever catch them.',
        ];
    }

    public function related(): array
    {
        return [['Sites', 'No site runs this centre or maps a category to it.']];
    }

    public function rowClass(object $row): ?string
    {
        return ($row->NoBandCount ?? 0) > 0 ? 'is-warn' : null;
    }
}
