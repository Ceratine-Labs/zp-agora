<?php

namespace Modules\Utilities\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;
use Modules\Utilities\Services\UtilitiesService;

/**
 * Utility meter readings — the `Utility meter readings` definition (T056).
 *
 * Every reading across every site the person may see, with usage and cost at
 * tariff. It is the list under the capture screen and the definition the
 * report library binds to, so there is one readings answer rather than two.
 *
 * The arithmetic is agora.vw_UtilityReading's: opening = the previous reading
 * whenever it was taken, usage = closing - opening - prepaid (the customer's
 * own formula). The capture screen and the zero-usage flag read the same view,
 * so no two screens can disagree about a meter.
 *
 * DEFAULT ORDER is newest first. The question somebody opens this with is
 * "what did we read lately, and what looks wrong", and the Status column is
 * what answers the second half — Zero usage, Reading went backwards and No
 * reading are the three that are always a finding.
 */
class UtilityReadingGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.utilities.index';
    }

    public function title(): string
    {
        return 'Utility meter readings';
    }

    public function blurb(): ?string
    {
        return 'Every reading, with the usage it implies and its cost at tariff. Usage is the closing less '
            .'the previous reading less any prepaid units bought — the customer\'s own formula, measured '
            .'from the previous reading whenever it was taken rather than from yesterday.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'TransactionDate', label: 'Date', format: 'date', sort: 'TransactionDate'),
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName'),
            new GridColumn(key: 'MeterDescription', label: 'Meter', sort: 'MeterDescription', link: true,
                title: 'Opens this site\'s capture screen on this day'),
            new GridColumn(key: 'UtilityTypeDescription', label: 'Type', sort: 'UtilityTypeDescription'),
            new GridColumn(key: 'OpeningReading', label: 'Opening', format: 'number',
                title: 'The previous reading\'s closing, whenever it was taken'),
            new GridColumn(key: 'ClosingReading', label: 'Closing', format: 'number', sort: 'ClosingReading'),
            new GridColumn(key: 'PrepaidUnitsPurchased', label: 'Prepaid', format: 'number',
                title: 'Prepaid units bought on this day, which do not count as usage'),
            new GridColumn(key: 'Usage', label: 'Usage', format: 'number', sort: 'Usage', total: true,
                title: 'Closing - opening - prepaid'),
            new GridColumn(key: 'Cost', label: 'Cost', format: 'money', sort: 'Cost', total: true,
                title: 'Usage at the tariff for this meter type. A dash means no tariff is set for the type.'),
            new GridColumn(key: 'Status', label: 'Status', format: 'chip', sort: 'Status',
                title: 'No reading = PumpIT holds the day as 0 / 0, which is not a reading: no usage, and the next reading is measured past it'),
            new GridColumn(key: 'GapDays', label: 'Days since last', format: 'number', sort: 'GapDays',
                visible: false, title: 'Days between this reading and the one before it'),
            new GridColumn(key: 'Tariff', label: 'Tariff', format: 'money', visible: false,
                title: 'Rand per unit for this meter type'),
            new GridColumn(key: 'StoredOpeningReading', label: 'Stored opening', format: 'number', visible: false,
                title: 'What PumpIT holds, which its own screen rewrites every time it is opened'),
            new GridColumn(key: 'StoredUsage', label: 'Stored usage', format: 'number', visible: false),
            new GridColumn(key: 'StoredDisagrees', label: 'Stored differs', format: 'bool', visible: false),
            new GridColumn(key: 'RowsForDay', label: 'Rows that day', format: 'number', visible: false,
                title: 'More than 1 means PumpIT holds two rows for this meter on this day; the newest is shown'),
            new GridColumn(key: 'MeterNo', label: 'Meter no', mono: true, visible: false),
            // PumpIT's own CreateDateTime: the server's local clock, as the
            // legacy has always written it — NOT Agora's UTC. Labelled so.
            new GridColumn(key: 'CapturedAt', label: 'Captured (PumpIT clock)', format: 'datetime', visible: false,
                title: 'When the row was written into PumpIT, on the server\'s local clock'),
        ];
    }

    /**
     * Keyed by COLUMN. The status vocabulary is the procedure's, listed here
     * so the tick list offers exactly the words the column can hold.
     *
     * @return array<string, GridFilter>
     */
    public function filters(): array
    {
        return [
            'TransactionDate' => new GridFilter(column: 'TransactionDate', type: 'date'),
            'BranchName' => new GridFilter(column: 'BranchName', type: 'text'),
            'MeterDescription' => new GridFilter(column: 'MeterDescription', type: 'text'),
            'UtilityTypeDescription' => new GridFilter(column: 'UtilityTypeDescription', type: 'text'),
            'Usage' => new GridFilter(column: 'Usage', type: 'number'),
            'Cost' => new GridFilter(column: 'Cost', type: 'number'),
            'Status' => new GridFilter(column: 'Status', type: 'set', options: [
                'Normal', 'Zero usage', 'Reading went backwards', 'No reading', 'First reading',
            ]),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource(
            'agora.usp_Utilities_GridReadings',
            acceptsFilters: true,
            extra: [
                'TariffsJson' => app(UtilitiesService::class)->tariffsJson(),
                // Every site this person may see at all — not the same
                // question as the sites they selected, which is empty for a
                // head-office user who picked none.
                'AllowedBranchIds' => self::allowedBranchIds(),
            ],
        );
    }

    public function defaultSort(): ?string
    {
        // The procedure's own order: newest first, then site and meter.
        return null;
    }

    public function dateRange(): bool
    {
        return true;
    }

    public function branchSelector(): bool
    {
        return true;
    }

    /** The meter's site, on that day, on the capture screen. */
    public function rowUrl(object $row): ?string
    {
        return route('app.utilities.index', [
            'branch_id' => $row->BranchId,
            'date' => substr((string) $row->TransactionDate, 0, 10),
        ]);
    }

    /** The branch grant as a CSV, or null where the grant is the whole estate. */
    private static function allowedBranchIds(): ?string
    {
        $allowed = app(BranchContext::class)->allowed();

        return $allowed === [] ? null : implode(',', $allowed);
    }
}
