<?php

namespace Modules\People\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;

/**
 * Setup → People and assets → Employee areas (T023), over
 * agora.usp_People_GridEmployeeAreas.
 *
 * One row per BRN_EmployeeArea row: a person, and a counting area they may be
 * signed on to. Opens on the active people, as PumpIT's sign-on screens do;
 * `status=inactive` or `all` shows the rest, including codes that are on no
 * employee. The person's name opens the person.
 */
class EmployeeAreaGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.setup.employee-areas';
    }

    public function title(): string
    {
        return 'Employee areas';
    }

    public function blurb(): ?string
    {
        return 'Which counting areas each person may be signed on to at a stock count.';
    }

    public function defaultSort(): ?string
    {
        return 'BranchName';
    }

    public function columns(): array
    {
        return [
            new GridColumn('EmployeeName', 'Employee', sort: 'EmployeeName', link: true, wide: true),
            new GridColumn('AreaDescription', 'Area', sort: 'AreaDescription', wide: true),
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('AreaNo', 'Area no', format: 'number', sort: 'AreaNo'),
            new GridColumn('EmployeeCode', 'Code', format: 'mono', sort: 'EmployeeCode', mono: true, filter: 'text'),
            new GridColumn('PostDescription', 'Post', sort: 'PostDescription'),
            new GridColumn('Status', 'Status', format: 'chip', sort: 'Status', filter: 'set',
                options: ['Active', 'Inactive', 'Not on the employee list']),
            new GridColumn('AreaGroup', 'Area group', visible: false),
            new GridColumn('NickName', 'Known as', visible: false),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource('agora.usp_People_GridEmployeeAreas', acceptsFilters: true, extra: [
            'Status' => EmployeeGrid::status(),
        ]);
    }

    /** The person (feature-rules §3.7) — not a link when the code is on no employee. */
    public function rowUrl(object $row): ?string
    {
        if (! (bool) ($row->OnEmployeeList ?? false)) {
            return null;
        }

        return route('app.setup.employees.show', ['branch' => $row->BranchId, 'code' => trim((string) $row->EmployeeCode)]);
    }

    public function rowClass(object $row): ?string
    {
        return match ($row->Status ?? null) {
            'Inactive' => 'is-warn',
            'Not on the employee list' => 'is-serious',
            default => null,
        };
    }
}
