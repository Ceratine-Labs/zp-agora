<?php

namespace Modules\Cash\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;

/**
 * Cash → Pump shortages (T038): what each pump attendant was short, shift by
 * shift — the legacy "Pump Shortages by Attendant" report.
 *
 * One row per attendant per cashup: a line of the cashup's pump shortages leg
 * (BRN_DailyBankingEmployees, through agora.vw_CashupLeg), so a till and
 * shift's rows add up to exactly the Pump shortages figure the Daily Banking
 * screen and the Cashups grid show for that cashup. Each line reached the
 * cashup as an approved staff short; its reason, note, who raised it and who
 * approved it come from that staff short.
 *
 * The site and the period are the grid's own (the branches selector and the
 * date range) — a head-office result set across sites (feature-rules §3.3). An
 * empty selection is the sites the person may see (the procedure's
 * AllowedBranchIds), never "every site in the estate".
 *
 * A row names where to go next: the cashup on Daily Banking.
 */
class PumpShortageGrid extends GridDefinition
{
    public const COUNTED = ['Counted', 'Not counted'];

    public const FLAGGED = ['Pump attendant', 'Not flagged'];

    public function __construct(protected BranchContext $context) {}

    public function key(): string
    {
        return 'app.cash.pumpshortages';
    }

    public function title(): string
    {
        return 'Pump shortages';
    }

    public function blurb(): ?string
    {
        return 'What each pump attendant was short, by shift — the pump shortages leg of every cashup, '
            .'with the staff short that put it there.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName', filter: 'text'),
            new GridColumn(key: 'TransactionDate', label: 'Date', format: 'date', sort: 'TransactionDate'),
            new GridColumn(key: 'ShiftDescription', label: 'Shift', sort: 'ShiftDescription'),
            new GridColumn(key: 'TillDescription', label: 'Till', sort: 'TillNo'),
            new GridColumn(key: 'EmployeeName', label: 'Attendant', sort: 'EmployeeName', wide: true),
            new GridColumn(key: 'AmountShort', label: 'Short', format: 'money', sort: 'AmountShort', total: true,
                title: 'The line on the cashup\'s pump shortages leg (BRN_DailyBankingEmployees.AmountShort)'),
            new GridColumn(key: 'Reason', label: 'Reason', sort: 'Reason', wide: true),
            new GridColumn(key: 'StaffShortsNo', label: 'Staff short', format: 'number', sort: 'StaffShortsNo', mono: true,
                title: 'The staff short that put the line on the cashup'),
            new GridColumn(key: 'CashierName', label: 'Cashier', sort: 'CashierName',
                title: 'The person the cashup belongs to'),
            new GridColumn(key: 'Counted', label: 'Counted', format: 'bool', filter: 'set', options: self::COUNTED,
                title: 'Whether the cashup\'s total counts the line — PumpIT drops an attendant who is not on its employee list'),
            new GridColumn(key: 'EmployeeCode', label: 'Attendant code', mono: true, visible: false),
            new GridColumn(key: 'IsPumpAttendant', label: 'Pump attendant', format: 'bool', visible: false, filter: 'set',
                options: self::FLAGGED, title: 'BRN_Employee.IsPumpAttendant'),
            new GridColumn(key: 'Note', label: 'Note', wide: true, visible: false),
            new GridColumn(key: 'RaisedByName', label: 'Raised by', visible: false),
            new GridColumn(key: 'RaisedAt', label: 'Raised', format: 'datetime', visible: false, filter: 'none'),
            new GridColumn(key: 'ApprovedByName', label: 'Approved by', visible: false),
            new GridColumn(key: 'ApprovedAt', label: 'Approved', format: 'datetime', visible: false, filter: 'none'),
            new GridColumn(key: 'CashierCode', label: 'Cashier code', mono: true, visible: false, filter: 'none'),
        ];
    }

    public function source(): GridSource
    {
        $allowed = $this->context->allowed();

        return new ProcedureSource(
            'agora.usp_Cash_GridPumpShortages',
            acceptsFilters: true,
            extra: ['AllowedBranchIds' => $allowed === [] ? null : implode(',', $allowed)],
        );
    }

    public function dateRange(): bool
    {
        return true;
    }

    /** The cashup the line is on, on Daily Banking. */
    public function rowActions(object $row): array
    {
        return [[
            'label' => 'Daily banking',
            'url' => route('app.cash.banking', [
                'branch_id' => $row->BranchId,
                'date' => substr((string) $row->TransactionDate, 0, 10),
                'till' => $row->TillNo,
                'shift' => $row->ShiftNo,
            ]),
        ]];
    }
}
