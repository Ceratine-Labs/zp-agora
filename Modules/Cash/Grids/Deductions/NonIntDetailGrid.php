<?php

namespace Modules\Cash\Grids\Deductions;

use App\Grid\GridColumn;

/**
 * Deductions → Non Integrated POS Deductions — Detail (T041, tab 5), row for
 * row with dbo.sp_RPT_NonIntegratedPOSDeductionsPAYROLLDETAIL: per person, day
 * and area on the Lotto / Electricity / Airtime areas, R1.00 or more.
 */
class NonIntDetailGrid extends DeductionGrid
{
    public function key(): string
    {
        return 'app.cash.deductions.nonintdetail';
    }

    public function title(): string
    {
        return 'Non-integrated POS deductions — detail';
    }

    protected function procedureName(): string
    {
        return 'usp_Cash_GridNonIntPosDetail';
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
            new GridColumn(key: 'AreaDescription', label: 'Area', sort: 'AreaDescription'),
            new GridColumn(key: 'EmployeeCount', label: 'Staff on area', format: 'number', sort: 'EmployeeCount'),
            new GridColumn(key: 'EmployeeTotalLoss', label: 'Loss', format: 'money', sort: 'EmployeeTotalLoss', total: true),
            new GridColumn(key: 'AreaNo', label: 'Area no', format: 'number', visible: false),
        ];
    }
}
