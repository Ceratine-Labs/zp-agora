<?php

namespace Modules\Masters\Grids;

use App\Grid\GridColumn;

/** Setup → Sites and forecourt → Tills (T022): PumpIT's dbo.BRN_Tills, per branch. Read-only. */
class TillGrid extends ReferenceMasterGrid
{
    public function slug(): string
    {
        return 'tills';
    }

    public function resource(): string
    {
        return 'tills';
    }

    public function procedureName(): string
    {
        return 'agora.usp_Masters_GridTills';
    }

    public function legacySource(): string
    {
        return 'dbo.BRN_Tills';
    }

    public function noun(): string
    {
        return 'Till';
    }

    public function eyebrow(): string
    {
        return 'Setup · Sites and forecourt';
    }

    public function title(): string
    {
        return 'Tills';
    }

    public function blurb(): ?string
    {
        return 'The tills each site cashes up, the POS each belongs to, and when each last cashed up.';
    }

    public function defaultSort(): ?string
    {
        return 'TillNo';
    }

    public function columns(): array
    {
        return [
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('TillNo', 'Till no', format: 'number', sort: 'TillNo'),
            new GridColumn('TillDescription', 'Till', sort: 'TillDescription', link: true, wide: true),
            new GridColumn('PosLocation', 'POS', sort: 'PosLocation', mono: true),
            new GridColumn('LastCashup', 'Last cashup', format: 'date', filter: 'none'),
        ];
    }

    public function meanings(): array
    {
        return [
            'TillNo' => 'Numbered per site: till 1 is a different till at every branch, so a till is always site and number together.',
            'LastCashup' => 'The latest cashup (BRN_DailyBanking) on this till. Blank: none on record.',
        ];
    }

    public function writeEntity(): ?string
    {
        return 'Till';
    }

    public function saveProcedure(): ?string
    {
        return 'agora.usp_Masters_SaveTill';
    }

    public function editableFields(): array
    {
        return [
            'TillDescription' => ['label' => 'Till name', 'type' => 'text', 'column' => 'TillDescription', 'max' => 30,
                'help' => 'The name PumpIT\'s cashup and Z-read screens show. The till number and its POS stay as they are, so no Z-read or cashup moves.'],
        ];
    }
}
