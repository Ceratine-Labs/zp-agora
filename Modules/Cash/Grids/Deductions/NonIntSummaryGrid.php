<?php

namespace Modules\Cash\Grids\Deductions;

use App\Grid\GridColumn;

/**
 * Deductions → Non Integrated POS Deductions — Summary (T041, tab 4), row for
 * row with dbo.sp_RPT_NonIntegratedPOSDeductionsPAYROLL: each person's loss on
 * the Lotto / Electricity / Airtime areas over the period, R1.00 or more.
 * Not Zapper or Yumbi settlements — see the procedure's header.
 */
class NonIntSummaryGrid extends DeductionGrid
{
    public function key(): string
    {
        return 'app.cash.deductions.nonint';
    }

    public function title(): string
    {
        return 'Non-integrated POS deductions — summary';
    }

    protected function procedureName(): string
    {
        return 'usp_Cash_GridNonIntPosSummary';
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
            new GridColumn(key: 'AreaNo', label: 'Area no', format: 'number', sort: 'AreaNo', visible: false),
            new GridColumn(key: 'AreaDescription', label: 'Area', sort: 'AreaDescription'),
            new GridColumn(key: 'Lines', label: 'Counts', format: 'number', sort: 'Lines', total: true),
            new GridColumn(key: 'EmployeeTotalLoss', label: 'Loss', format: 'money', sort: 'EmployeeTotalLoss', total: true),
        ];
    }
}
