<?php

namespace Modules\Masters\Grids;

use App\Grid\GridColumn;

/** Setup → People and assets → Utility meters (T022): PumpIT's dbo.BRN_UtilityMeter, per branch. Read-only. */
class UtilityMeterGrid extends ReferenceMasterGrid
{
    public function slug(): string
    {
        return 'meters';
    }

    public function resource(): string
    {
        return 'meters';
    }

    public function procedureName(): string
    {
        return 'agora.usp_Masters_GridUtilityMeters';
    }

    public function legacySource(): string
    {
        return 'dbo.BRN_UtilityMeter';
    }

    public function noun(): string
    {
        return 'Utility meter';
    }

    public function eyebrow(): string
    {
        return 'Setup · People and assets';
    }

    public function title(): string
    {
        return 'Utility meters';
    }

    public function blurb(): ?string
    {
        return 'Every electricity, water and gas meter, and when each was last read. Readings are captured on Today → Utility reading.';
    }

    public function defaultSort(): ?string
    {
        return 'UtilityTypeName';
    }

    public function columns(): array
    {
        return [
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('UtilityTypeName', 'Utility', sort: 'UtilityTypeName'),
            new GridColumn('MeterNo', 'Meter no', sort: 'MeterNo', link: true, mono: true),
            new GridColumn('MeterDescription', 'Description', sort: 'MeterDescription', wide: true),
            new GridColumn('ReadingCount', 'Readings', format: 'number', sort: 'ReadingCount'),
            new GridColumn('LastReadingDate', 'Last read', format: 'date', filter: 'none'),
            new GridColumn('LastClosingReading', 'Last closing reading', format: 'number', sort: 'LastClosingReading'),
            new GridColumn('UtilityMeterId', 'Meter id', format: 'number', sort: 'UtilityMeterId', visible: false),
        ];
    }

    public function meanings(): array
    {
        return [
            'LastReadingDate' => 'The latest reading on BRN_UtilityTransaction for this site, utility and meter.',
            'LastClosingReading' => 'As captured. PumpIT\'s stored opening and usage are recalculated by its own screens, so they are shown only as stored.',
        ];
    }

    public function related(): array
    {
        return [['Last ten readings', 'No reading has been captured on this meter.']];
    }
}
