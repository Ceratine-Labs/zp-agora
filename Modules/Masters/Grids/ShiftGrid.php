<?php

namespace Modules\Masters\Grids;

use App\Grid\GridColumn;

/** Setup → Sites and forecourt → Shifts (T022): PumpIT's dbo.BRN_Shifts, per branch. Read-only. */
class ShiftGrid extends ReferenceMasterGrid
{
    public function slug(): string
    {
        return 'shifts';
    }

    public function resource(): string
    {
        return 'shifts';
    }

    public function procedureName(): string
    {
        return 'agora.usp_Masters_GridShifts';
    }

    public function legacySource(): string
    {
        return 'dbo.BRN_Shifts';
    }

    public function noun(): string
    {
        return 'Shift';
    }

    public function eyebrow(): string
    {
        return 'Setup · Sites and forecourt';
    }

    public function title(): string
    {
        return 'Shifts';
    }

    public function blurb(): ?string
    {
        return 'The shifts each site trades. Three shift numbers exist estate-wide, so a shift is always a site and a number together.';
    }

    public function defaultSort(): ?string
    {
        return 'ShiftNo';
    }

    public function columns(): array
    {
        return [
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('ShiftNo', 'Shift no', format: 'number', sort: 'ShiftNo'),
            new GridColumn('ShiftDescription', 'Shift', sort: 'ShiftDescription', link: true, wide: true),
            new GridColumn('IsActive', 'Active', format: 'bool', options: ['Yes', 'No']),
            new GridColumn('LastCashup', 'Last cashup', format: 'date', filter: 'none'),
        ];
    }

    public function meanings(): array
    {
        return [
            'ShiftNo' => 'Only 1, 2 and 3 exist estate-wide: joined on the number alone, a shift fans out 21.67 times. Shift 2 is "Do Not Use" at twelve sites.',
            'LastCashup' => 'The latest cashup (BRN_DailyBanking) on this shift. Blank: none on record.',
        ];
    }
}
