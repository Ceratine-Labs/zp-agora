<?php

namespace Modules\People\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use Illuminate\Support\Facades\Gate;
use Modules\People\Services\EmployeeWriteService;

/**
 * Setup → People and assets → Employees (T023), over
 * agora.usp_People_GridEmployees.
 *
 * One row per person in PumpIT's BRN_Employee. The name is composed in the
 * view ("Surname FirstName", as PumpIT writes it everywhere) and the parts
 * are columns of their own. Opens on the ACTIVE people; `status=inactive` or
 * `status=all` in the query string widens it, so the extract and a pasted
 * link show the same rows. `system=1` adds the PUMPIT / ADMIN accounts.
 *
 * The post filter is the scope's `f[Post]`, a filter on PostDescription by
 * name, because posts are numbered per branch and the number means nothing
 * across sites.
 */
class EmployeeGrid extends GridDefinition
{
    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive (terminated)', 'all' => 'Active and inactive'];

    public function key(): string
    {
        return 'app.setup.employees';
    }

    public function title(): string
    {
        return 'Employees';
    }

    public function blurb(): ?string
    {
        return match (self::status()) {
            'inactive' => 'The inactive people — PumpIT\'s IsActive is off. They stay on every shift they worked.',
            'all' => 'Everybody on PumpIT\'s employee list, active or not.',
            default => 'The active people at each site, with their post and the counting areas they sign on to.',
        };
    }

    public function defaultSort(): ?string
    {
        return 'BranchName';
    }

    public function columns(): array
    {
        return [
            new GridColumn('EmployeeName', 'Name', sort: 'EmployeeName', link: true, wide: true,
                title: 'Surname and first name, composed — PumpIT stores the parts'),
            new GridColumn('PostDescription', 'Post', sort: 'PostDescription',
                title: 'BRN_EmployeePost, by the branch\'s own post number'),
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('EmployeeCode', 'Code', format: 'mono', sort: 'EmployeeCode', mono: true, filter: 'text',
                title: 'BRN_Employee.EmployeeCode — what the till, the cashup and the count record'),
            new GridColumn('NickName', 'Known as', sort: 'NickName'),
            new GridColumn('Status', 'Status', format: 'chip', sort: 'Status', filter: 'set', options: ['Active', 'Inactive']),
            new GridColumn('IsPumpAttendant', 'Pump attendant', format: 'bool', options: ['Yes', 'No']),
            new GridColumn('AreaCount', 'Areas', format: 'number', sort: 'AreaCount',
                title: 'Counting areas this person may be signed on to (BRN_EmployeeArea)'),
            new GridColumn('Areas', 'Area list', wide: true, visible: false),
            new GridColumn('Title', 'Title', visible: false),
            new GridColumn('Initials', 'Initials', visible: false),
            new GridColumn('FirstName', 'First name', sort: 'FirstName', visible: false),
            new GridColumn('Surname', 'Surname', sort: 'Surname', visible: false),
            new GridColumn('PostNo', 'Post no', format: 'number', sort: 'PostNo', visible: false,
                title: 'Numbered per branch — the same number is a different post at another site'),
            new GridColumn('DateJoined', 'Joined', format: 'date', sort: 'DateJoined', visible: false,
                title: 'As PumpIT holds it; 22 Mar 2016 is the import date on most rows'),
            new GridColumn('DateDepart', 'Departed', format: 'date', sort: 'DateDepart', visible: false,
                title: 'As PumpIT holds it — set on many active people too, so IsActive is the status'),
            new GridColumn('CashDeviceCode', 'Cash device code', mono: true, visible: false),
        ];
    }

    public function filters(): array
    {
        // The scope's post picker: an exact match on the description.
        return parent::filters() + ['Post' => new GridFilter(column: 'PostDescription', type: 'text')];
    }

    public function source(): GridSource
    {
        return new ProcedureSource('agora.usp_People_GridEmployees', acceptsFilters: true, extra: [
            'Status' => self::status(),
            'IncludeSystem' => (int) request()->boolean('system'),
        ]);
    }

    public function detailMode(): string
    {
        return 'expand';
    }

    public function rowUrl(object $row): ?string
    {
        return route('app.setup.employees.show', ['branch' => $row->BranchId, 'code' => trim((string) $row->EmployeeCode)]);
    }

    public function detailUrl(object $row): ?string
    {
        return $this->rowUrl($row);
    }

    /** Open, and Edit where the write is on and the caller may change people or areas. */
    public function rowActions(object $row): array
    {
        $actions = [['label' => 'Open', 'url' => (string) $this->rowUrl($row)]];

        if (EmployeeWriteService::live() && Gate::any(['people.employees.edit', 'people.areas.edit'])) {
            $actions[] = [
                'label' => 'Edit',
                'url' => route('app.setup.employees.edit', ['branch' => $row->BranchId, 'code' => trim((string) $row->EmployeeCode)]),
                'primary' => true,
            ];
        }

        return $actions;
    }

    public function rowClass(object $row): ?string
    {
        return (int) ($row->IsActive ?? 1) === 1 ? null : 'is-warn';
    }

    /** `active`, `inactive` or `all`, from the query string; anything else is `active`. */
    public static function status(): string
    {
        $status = (string) request()->query('status', 'active');

        return array_key_exists($status, self::STATUSES) ? $status : 'active';
    }
}
