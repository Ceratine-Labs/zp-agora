<?php

namespace Modules\Masters\Grids;

use App\Grid\GridColumn;

/**
 * Setup → The business → Categories and GP bands (T022): PumpIT's
 * SDK_CategoryGLCode, per branch. A category with no GP band is washed as a
 * warning — no "below min GP" check can ever return it.
 */
class CategoryGlGrid extends ReferenceMasterGrid
{
    public function slug(): string
    {
        return 'categories';
    }

    public function resource(): string
    {
        return 'categorygl';
    }

    public function procedureName(): string
    {
        return 'agora.usp_Masters_GridCategoryGlCodes';
    }

    public function legacySource(): string
    {
        return 'dbo.SDK_CategoryGLCode';
    }

    public function noun(): string
    {
        return 'POS category';
    }

    public function title(): string
    {
        return 'Categories and GP bands';
    }

    public function blurb(): ?string
    {
        return 'Every POS category, the profit centre it belongs to, and the GP and cover band it is held to. A category with no band is highlighted: nothing that checks GP can see it.';
    }

    public function defaultSort(): ?string
    {
        return 'BranchName';
    }

    public function columns(): array
    {
        return [
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('POSSystem', 'POS system', format: 'chip', sort: 'POSSystem',
                options: ['Aura', 'Arch', 'Gaap', 'Namos', 'Winbranch', 'Manual capture', 'Arch Liquor', 'Pilot', 'None', 'Nights Bridge']),
            new GridColumn('POSSystemId', 'POS system id', format: 'number', sort: 'POSSystemId', visible: false),
            new GridColumn('Category', 'POS category', sort: 'Category', link: true, wide: true),
            new GridColumn('StandardCategory', 'Standard category', sort: 'StandardCategory'),
            new GridColumn('ProfitCentre', 'Profit centre', sort: 'ProfitCentre'),
            new GridColumn('SupplierGLCode', 'Supplier GL', sort: 'SupplierGLCode', mono: true),
            new GridColumn('SalesGLCode', 'Sales GL', sort: 'SalesGLCode', mono: true),
            new GridColumn('MinGP', 'Min GP %', format: 'pct', sort: 'MinGP'),
            new GridColumn('MaxGP', 'Max GP %', format: 'pct', sort: 'MaxGP'),
            new GridColumn('MinDaysCover', 'Min days cover', format: 'number', sort: 'MinDaysCover'),
            new GridColumn('MaxDaysCover', 'Max days cover', format: 'number', sort: 'MaxDaysCover'),
            new GridColumn('GpBand', 'GP band', format: 'chip', sort: 'GpBand', options: ['Set', 'Missing']),
            new GridColumn('CoverBand', 'Cover band', format: 'chip', sort: 'CoverBand', options: ['Set', 'Missing'], visible: false),
            new GridColumn('IsActive', 'Active', format: 'bool', options: ['Yes', 'No']),
        ];
    }

    public function meanings(): array
    {
        return [
            'POSSystem' => 'The till system the category\'s sales arrive from, named as MIST_Main.dbo.POSSystems names its POSSystemId.',
            'POSSystemId' => 'PumpIT\'s POS system id. A category is unique per site AND POS system — a site running two tills systems carries both.',
            'StandardCategory' => 'The group-standard category it maps into (Setup → Standard categories).',
            'GpBand' => 'Missing when Min and Max GP are both 0: no GP check can return this category\'s lines.',
            'CoverBand' => 'Missing when Min and Max days cover are both 0.',
        ];
    }

    public function related(): array
    {
        return [['The same category at your other sites', 'No other site you can see carries this POS category.']];
    }

    public function writeEntity(): ?string
    {
        return 'CategoryGl';
    }

    public function saveProcedure(): ?string
    {
        return 'agora.usp_Masters_SaveCategoryBand';
    }

    public function editableFields(): array
    {
        return [
            'MinGP' => ['label' => 'Min GP %', 'type' => 'decimal', 'column' => 'MINGP', 'min' => 0, 'max' => 100, 'step' => '0.01',
                'help' => 'Below this, the category\'s lines are a margin exception. 0 and 0 is no band.'],
            'MaxGP' => ['label' => 'Max GP %', 'type' => 'decimal', 'column' => 'MAXGP', 'min' => 0, 'max' => 100, 'step' => '0.01'],
            'MinDaysCover' => ['label' => 'Min days cover', 'type' => 'decimal', 'column' => 'MINDaysCover', 'min' => 0, 'max' => 366, 'step' => '0.5'],
            'MaxDaysCover' => ['label' => 'Max days cover', 'type' => 'decimal', 'column' => 'MAXDaysCover', 'min' => 0, 'max' => 366, 'step' => '0.5'],
            'IsActive' => ['label' => 'Active', 'type' => 'bool', 'column' => 'IsActive'],
        ];
    }

    public function rowClass(object $row): ?string
    {
        return (($row->IsActive ?? false) && ($row->GpBand ?? null) === 'Missing') ? 'is-warn' : null;
    }
}
