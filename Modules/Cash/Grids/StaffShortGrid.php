<?php

namespace Modules\Cash\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;

/**
 * Cash → Staff shorts list (T040): every staff short across sites — PumpIT's
 * staff-shorts list and its approval queue as one grid, over
 * agora.usp_Cash_GridStaffShorts.
 *
 * One row per short. Reason is the code the short reads as — Agora's own for a
 * short raised in Agora, the legacy spelling's alias for one raised in PumpIT
 * (47 spellings of "pump short" are one filter value) — and Note keeps the text
 * as it was typed. Waiting shorts first, then approved ones not yet on a
 * cashup; both are listed whatever the period, because they are the work.
 *
 * The sites and the period are the grid's own (the branches selector and the
 * date range, feature-rules §3.3). An empty selection is the sites the person
 * may see (the procedure's AllowedBranchIds), never "every site in the estate".
 *
 * A row opens the short on its site's Staff shorts screen, where it is decided
 * or posted.
 */
class StaffShortGrid extends GridDefinition
{
    public const STATUSES = ['Waiting', 'Approved', 'Posted', 'Declined'];

    public const RAISED_IN = ['Agora', 'PumpIT'];

    public const SOURCES = ['Staff shorts', 'Phone', 'Cash-bag match', 'PumpIT'];

    public function __construct(protected BranchContext $context) {}

    public function key(): string
    {
        return 'app.cash.staffshorts.list';
    }

    public function title(): string
    {
        return 'Staff shorts';
    }

    public function blurb(): ?string
    {
        return 'Every staff short — who it is against, why, and whether it is waiting for an approver, approved, '
            .'on a cashup or declined.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName', filter: 'text'),
            new GridColumn(key: 'StaffShortsNo', label: 'Short', format: 'mono', sort: 'StaffShortsNo', mono: true, filter: 'number', link: true,
                title: 'BRN_StaffShorts.StaffShortsNo — SS_UniqueNumber section 2'),
            new GridColumn(key: 'TransactionDate', label: 'Day', format: 'date', sort: 'TransactionDate',
                title: 'The day the short belongs to — for a short raised in PumpIT, the moment it was captured'),
            new GridColumn(key: 'EmployeeName', label: 'Against', sort: 'EmployeeName', wide: true),
            new GridColumn(key: 'AmountShort', label: 'Amount', format: 'money', sort: 'AmountShort', total: true),
            new GridColumn(key: 'ReasonLabel', label: 'Reason', sort: 'ReasonLabel', filter: 'set',
                options: ['Pump short', 'Cashier short', 'Stock loss', 'Driver drinks / promotion', 'Other'],
                title: 'The code — for a legacy short, what its free-text reason reads as'),
            new GridColumn(key: 'Note', label: 'Note', wide: true,
                title: 'The note; for a legacy short, the reason as it was typed'),
            new GridColumn(key: 'Status', label: 'Status', sort: 'Status', filter: 'set', options: self::STATUSES),
            new GridColumn(key: 'DecidedBy', label: 'Decided by', sort: 'DecidedBy'),
            new GridColumn(key: 'DecidedAt', label: 'Decided', format: 'date', sort: 'DecidedAt'),
            new GridColumn(key: 'PostedTo', label: 'On the cashup', title: 'The pump-shortages line it was posted to'),
            new GridColumn(key: 'DeclinedReason', label: 'Declined because', wide: true, visible: false),
            new GridColumn(key: 'RaisedBy', label: 'Raised by', sort: 'RaisedBy', visible: false),
            new GridColumn(key: 'RaisedIn', label: 'Raised in', sort: 'RaisedIn', visible: false, filter: 'set', options: self::RAISED_IN),
            new GridColumn(key: 'Source', label: 'From', sort: 'Source', visible: false, filter: 'set', options: self::SOURCES),
            new GridColumn(key: 'SourceRef', label: 'Match', visible: false, mono: true),
            new GridColumn(key: 'EmployeeCode', label: 'Employee code', sort: 'EmployeeCode', mono: true, visible: false),
            new GridColumn(key: 'PostedDate', label: 'Cashup day', format: 'date', sort: 'PostedDate', visible: false),
            new GridColumn(key: 'CapturedAt', label: 'Captured', format: 'datetime', visible: false, filter: 'none'),
            new GridColumn(key: 'AgeDays', label: 'Age (days)', format: 'number', sort: 'AgeDays', visible: false),
        ];
    }

    public function source(): GridSource
    {
        $allowed = $this->context->allowed();

        return new ProcedureSource(
            'agora.usp_Cash_GridStaffShorts',
            acceptsFilters: true,
            extra: ['AllowedBranchIds' => $allowed === [] ? null : implode(',', $allowed)],
        );
    }

    public function cellsPartial(): string
    {
        return 'cash::grid._staffshort-cells';
    }

    public function dateRange(): bool
    {
        return true;
    }

    /** The short itself, on its site's Staff shorts screen (feature-rules §3.7). */
    public function rowUrl(object $row): ?string
    {
        return route('app.cash.staffshorts', ['branch_id' => $row->BranchId, 'short' => $row->StaffShortsNo]);
    }

    public function rowActions(object $row): array
    {
        return [[
            'label' => match ($row->Status ?? '') {
                'Waiting' => 'Decide',
                'Approved' => ($row->Source ?? '') === 'Cash-bag match' ? 'Open' : 'Post',
                default => 'Open',
            },
            'url' => $this->rowUrl($row),
        ]];
    }
}
