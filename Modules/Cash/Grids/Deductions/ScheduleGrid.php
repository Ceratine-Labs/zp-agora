<?php

namespace Modules\Cash\Grids\Deductions;

use App\Grid\GridColumn;

/**
 * Deductions → Payroll (T041): the schedule as BUILT (agora.Deduction) for the
 * current runs inside the period — one row per thing that was short, with its
 * allowance and what payroll takes. The payroll file is this, added up per
 * person.
 */
class ScheduleGrid extends DeductionGrid
{
    public const SOURCES = ['Cashier short', 'Pump attendant short', 'Cash-bag short', 'Stock loss', 'Non-integrated POS'];

    public const OUTCOMES = ['Deducted', 'Written off'];

    public const RUN_STATUSES = ['built', 'exported'];

    public function key(): string
    {
        return 'app.cash.deductions.payroll';
    }

    public function title(): string
    {
        return 'Deduction schedule';
    }

    protected function procedureName(): string
    {
        return 'usp_Cash_GridDeductionSchedule';
    }

    protected function statKeys(): array
    {
        return ['WrittenOffAmount', 'Employees', 'Runs'];
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName', filter: 'text'),
            new GridColumn(key: 'EmployeeName', label: 'Employee', sort: 'EmployeeName', wide: true),
            new GridColumn(key: 'EmployeeCode', label: 'Code', sort: 'EmployeeCode', mono: true),
            new GridColumn(key: 'SourceLabel', label: 'What', sort: 'SourceLabel', filter: 'set', options: self::SOURCES),
            new GridColumn(key: 'TransactionDate', label: 'Date', format: 'date', sort: 'TransactionDate'),
            new GridColumn(key: 'AreaGroup', label: 'Area group', sort: 'AreaGroup'),
            new GridColumn(key: 'SourceRef', label: 'Reference', wide: true),
            new GridColumn(key: 'Amount', label: 'Short', format: 'money', sort: 'Amount', total: true),
            new GridColumn(key: 'Allowance', label: 'Allowance', format: 'money', sort: 'Allowance'),
            new GridColumn(key: 'Deducted', label: 'Deducted', format: 'money', sort: 'Deducted', total: true),
            new GridColumn(key: 'Outcome', label: 'Outcome', format: 'chip', sort: 'Outcome', filter: 'set', options: self::OUTCOMES),
            new GridColumn(key: 'Period', label: 'Period', sort: 'Period'),
            new GridColumn(key: 'RunStatus', label: 'Run', format: 'chip', filter: 'set', options: self::RUN_STATUSES,
                title: 'built — not yet in a payroll file; exported — in one, and the period cannot be rebuilt'),
            new GridColumn(key: 'TillNo', label: 'Till no', format: 'number', visible: false),
            new GridColumn(key: 'ShiftNo', label: 'Shift no', format: 'number', visible: false),
            new GridColumn(key: 'AreaNo', label: 'Area no', format: 'number', visible: false),
            new GridColumn(key: 'PostDescription', label: 'Post', visible: false),
            new GridColumn(key: 'Note', label: 'Note', wide: true, visible: false),
            new GridColumn(key: 'RunId', label: 'Run no', format: 'number', mono: true, visible: false),
        ];
    }
}
