<?php

namespace Modules\Cash\Grids\Deductions;

use App\Grid\GridColumn;

/**
 * Deductions → Cash Short Deductions Per Employee (T041, tab 1).
 *
 * PumpIT's report of that name, row for row: every cashier short worse than
 * the leniency and every pump attendant short — and, new with Agora, every
 * approved cash-bag short, which is never on a cashup (PM decision 01a0e5b5).
 * A short inside the leniency is written off and, as in the legacy, not
 * listed; the stat strip says how many and how much.
 */
class CashShortGrid extends DeductionGrid
{
    public const SOURCES = ['Cashier short', 'Pump attendant short', 'Cash-bag short'];

    public function key(): string
    {
        return 'app.cash.deductions';
    }

    public function title(): string
    {
        return 'Cash short deductions per employee';
    }

    protected function procedureName(): string
    {
        return 'usp_Cash_GridDeductionCashShorts';
    }

    protected function extraArguments(): array
    {
        return ['Leniency' => (float) config('cash.deductions.cash_leniency', 3.00)];
    }

    protected function statKeys(): array
    {
        return ['AllCashierShorts', 'WrittenOffShifts', 'WrittenOffAmount', 'Employees'];
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName', filter: 'text'),
            new GridColumn(key: 'EmployeeName', label: 'Employee', sort: 'EmployeeName', wide: true),
            new GridColumn(key: 'EmployeeCode', label: 'Code', sort: 'EmployeeCode', mono: true),
            new GridColumn(key: 'SourceLabel', label: 'Short', sort: 'SourceLabel', filter: 'set', options: self::SOURCES),
            new GridColumn(key: 'TransactionDate', label: 'Date', format: 'date', sort: 'TransactionDate'),
            new GridColumn(key: 'TillDescription', label: 'Till', sort: 'TillDescription'),
            new GridColumn(key: 'ShiftDescription', label: 'Shift', sort: 'ShiftDescription', filter: 'text'),
            new GridColumn(key: 'ZReading', label: 'Z reading', format: 'money', sort: 'ZReading',
                title: 'The cashup\'s ClosingBalance — the Z-read it was balanced against'),
            new GridColumn(key: 'PumpAttShort', label: 'Pump att short', format: 'money', sort: 'PumpAttShort', total: true,
                title: 'A line of the cashup\'s pump shortages leg (-AmountShort)'),
            new GridColumn(key: 'CashierShort', label: 'Cashier short', format: 'money', sort: 'CashierShort', total: true,
                title: 'Every tender leg added up, less the Z reading — or a cash-bag short a recon match raised'),
            new GridColumn(key: 'LeniencyAllowance', label: 'Leniency', format: 'money',
                title: 'A cashier short at or inside this is written off (config cash.deductions.cash_leniency)'),
            new GridColumn(key: 'Deduction', label: 'Deduction', format: 'money', sort: 'Deduction', total: true,
                title: 'What comes off the person\'s pay — in full; there is no partial rule'),
            new GridColumn(key: 'DayEndNo', label: 'Day end', mono: true),
            new GridColumn(key: 'StaffShortsNo', label: 'Staff short', format: 'number', mono: true,
                title: 'The staff short behind a pump or cash-bag short'),
            new GridColumn(key: 'Note', label: 'Note', wide: true),
            new GridColumn(key: 'TillNo', label: 'Till no', format: 'number', visible: false),
            new GridColumn(key: 'ShiftNo', label: 'Shift no', format: 'number', visible: false),
        ];
    }

    /** A cashier or pump short opens its cashup on Daily Banking. */
    public function rowActions(object $row): array
    {
        if (($row->Source ?? null) === 'cashbag_short' || empty($row->TillNo)) {
            return [];
        }

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
