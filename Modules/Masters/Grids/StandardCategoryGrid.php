<?php

namespace Modules\Masters\Grids;

use App\Grid\GridColumn;

/** Setup → The business → Standard categories (T022): PumpIT's SS_StandardCategory, group-wide. */
class StandardCategoryGrid extends ReferenceMasterGrid
{
    public function slug(): string
    {
        return 'standard-categories';
    }

    public function resource(): string
    {
        return 'standardcategories';
    }

    public function procedureName(): string
    {
        return 'agora.usp_Masters_GridStandardCategories';
    }

    public function legacySource(): string
    {
        return 'dbo.SS_StandardCategory';
    }

    public function noun(): string
    {
        return 'Standard category';
    }

    public function perBranch(): bool
    {
        return false;
    }

    public function title(): string
    {
        return 'Standard categories';
    }

    public function blurb(): ?string
    {
        return 'The group-standard category each POS category maps into, and the GL accounts behind it.';
    }

    public function defaultSort(): ?string
    {
        return 'StandardCategoryId';
    }

    public function columns(): array
    {
        return [
            new GridColumn('StandardCategoryId', 'Id', format: 'number', sort: 'StandardCategoryId'),
            new GridColumn('StandardCategory', 'Standard category', sort: 'StandardCategory', link: true, wide: true),
            new GridColumn('SupplierGLCode', 'Supplier GL', sort: 'SupplierGLCode', mono: true),
            new GridColumn('SalesGLCode', 'Sales GL', sort: 'SalesGLCode', mono: true),
            new GridColumn('SharedGlCount', 'Others on this GL pair', format: 'number', sort: 'SharedGlCount'),
            new GridColumn('CategoryCount', 'POS categories mapped', format: 'number', sort: 'CategoryCount'),
            new GridColumn('BranchCount', 'Sites', format: 'number', sort: 'BranchCount'),
            new GridColumn('ProfitCentres', 'Profit centres', wide: true),
            new GridColumn('GlMismatchCount', 'Categories on another GL', format: 'number', sort: 'GlMismatchCount', visible: false),
            new GridColumn('IsActive', 'Active', format: 'bool', options: ['Yes', 'No']),
        ];
    }

    public function writeEntity(): ?string
    {
        return 'StandardCategory';
    }

    public function saveProcedure(): ?string
    {
        return 'agora.usp_Masters_SaveStandardCategory';
    }

    public function editableFields(): array
    {
        return [
            'SupplierGLCode' => ['label' => 'Supplier GL', 'type' => 'text', 'column' => 'SupplierGLCode', 'max' => 20,
                'help' => 'Digits only. The POS categories mapped to it keep their own GL codes.'],
            'SalesGLCode' => ['label' => 'Sales GL', 'type' => 'text', 'column' => 'SalesGLCode', 'max' => 20],
            'IsActive' => ['label' => 'Active', 'type' => 'bool', 'column' => 'IsActive',
                'help' => 'Refused while active POS categories map to it. The name is not changed from Agora.'],
        ];
    }

    public function meanings(): array
    {
        return [
            'SharedGlCount' => 'Other standard categories posting to the same supplier and sales GL. Fine for the ledger; it is why margin is read off the POS category, not the GL.',
            'CategoryCount' => 'Active POS categories PumpIT maps into this one (SDK_CategoryGLCode, by name), at the sites you can see.',
            'GlMismatchCount' => 'Mapped categories whose own GL codes differ from the standard\'s.',
        ];
    }

    public function related(): array
    {
        return [['POS categories mapped', 'No POS category maps into this standard category at the sites you can see.']];
    }
}
