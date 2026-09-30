<?php

namespace Modules\Cash\Grids\Deductions;

use App\Grid\GridColumn;

/**
 * Deductions → Stock Loss By Employee — Detail (T041, tab 3): PumpIT's "Stock
 * Loss Report By Employee Detail" (PAYROLLDETAIL), row for row — per person,
 * day, shift and area, with the area group's daily loss grace the payroll
 * build measures the period against.
 */
class StockLossDetailGrid extends DeductionGrid
{
    public function key(): string
    {
        return 'app.cash.deductions.stocklossdetail';
    }

    public function title(): string
    {
        return 'Stock loss by employee — detail';
    }

    protected function procedureName(): string
    {
        return 'usp_Cash_GridStockLossDetail';
    }

    protected function statKeys(): array
    {
        return ['Employees', 'Areas'];
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName', filter: 'text'),
            new GridColumn(key: 'TransactionDate', label: 'Date', format: 'date', sort: 'TransactionDate'),
            new GridColumn(key: 'EmployeeCode', label: 'Code', sort: 'EmployeeCode', mono: true),
            new GridColumn(key: 'EmployeeName', label: 'Employee', sort: 'EmployeeName', wide: true),
            new GridColumn(key: 'PostDescription', label: 'Post', sort: 'PostDescription'),
            new GridColumn(key: 'AreaNo', label: 'Area no', format: 'number', sort: 'AreaNo', visible: false),
            new GridColumn(key: 'AreaDescription', label: 'Area', sort: 'AreaDescription'),
            new GridColumn(key: 'AreaGroup', label: 'Group', sort: 'AreaGroup', filter: 'text'),
            new GridColumn(key: 'ShiftDescription', label: 'Shift', sort: 'ShiftNo', filter: 'text'),
            new GridColumn(key: 'EmployeeCount', label: 'Staff on area', format: 'number', sort: 'EmployeeCount',
                title: 'More than one: the loss is shared equally, so the same rand appears against each name'),
            new GridColumn(key: 'LossGraceDaily', label: 'Daily grace', format: 'money',
                title: 'STK_AreaGroup.LossGraceDaily — the allowance per day the payroll build measures the period against'),
            new GridColumn(key: 'EmployeeTotalLoss', label: 'Loss', format: 'money', sort: 'EmployeeTotalLoss', total: true),
            new GridColumn(key: 'ShiftNo', label: 'Shift no', format: 'number', visible: false),
        ];
    }
}
