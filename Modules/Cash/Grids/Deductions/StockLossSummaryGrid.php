<?php

namespace Modules\Cash\Grids\Deductions;

use App\Grid\GridColumn;

/**
 * Deductions → Stock Loss By Employee — Summary (T041, tab 2): PumpIT's
 * "Stock Loss Report By Employee" (PAYROLL), row for row — each person's share
 * of the recon lines that lost stock, per counting area, over the period.
 */
class StockLossSummaryGrid extends DeductionGrid
{
    public function key(): string
    {
        return 'app.cash.deductions.stockloss';
    }

    public function title(): string
    {
        return 'Stock loss by employee — summary';
    }

    protected function procedureName(): string
    {
        return 'usp_Cash_GridStockLossSummary';
    }

    protected function statKeys(): array
    {
        return ['Employees', 'Areas'];
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName', filter: 'text'),
            new GridColumn(key: 'EmployeeCode', label: 'Code', sort: 'EmployeeCode', mono: true),
            new GridColumn(key: 'EmployeeName', label: 'Employee', sort: 'EmployeeName', wide: true),
            new GridColumn(key: 'PostDescription', label: 'Post', sort: 'PostDescription'),
            new GridColumn(key: 'AreaNo', label: 'Area no', format: 'number', sort: 'AreaNo'),
            new GridColumn(key: 'AreaDescription', label: 'Area', sort: 'AreaDescription'),
            new GridColumn(key: 'AreaGroup', label: 'Group', sort: 'AreaGroup', filter: 'text'),
            new GridColumn(key: 'Lines', label: 'Counts', format: 'number', sort: 'Lines', total: true,
                title: 'Recon lines (item, day) behind the figure'),
            new GridColumn(key: 'EmployeeTotalLoss', label: 'Total loss', format: 'money', sort: 'EmployeeTotalLoss', total: true,
                title: 'The person\'s share, at the price each line was selling at'),
        ];
    }
}
