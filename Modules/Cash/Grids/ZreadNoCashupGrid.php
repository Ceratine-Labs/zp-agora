<?php

namespace Modules\Cash\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;

/**
 * Cash → Z-reads with no cashup (T037).
 *
 * A till's reading allocated to a till, a shift and a person, with sales on
 * it, and no cashup for that till and shift that day: the takings are known
 * and what they were banked as is not. The legacy "ZReadings vs Cashups - NO
 * Cashup" report, on the effective allocation (agora.vw_CashZread).
 *
 * Each row names where to go next rather than what went wrong: the Daily
 * Banking screen for that site and day, where the Z-read is listed as not
 * captured.
 */
class ZreadNoCashupGrid extends GridDefinition
{
    public function __construct(protected BranchContext $context) {}

    public function key(): string
    {
        return 'app.cash.cashups.nocashup';
    }

    public function title(): string
    {
        return 'Z-reads with no cashup';
    }

    public function blurb(): ?string
    {
        return 'Allocated Z-reads with sales on them and no cashup for their till and shift that day. '
            .'An unallocated Z-read is not here — it is on the Unallocated Z-reads queue first.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName'),
            new GridColumn(key: 'ZReadDate', label: 'Date', format: 'date', sort: 'ZReadDate'),
            new GridColumn(key: 'Location', label: 'Loc', sort: 'Location', filter: 'set',
                options: (array) config('cash.zread.locations', [])),
            new GridColumn(key: 'TerminalNo', label: 'Terminal', mono: true),
            new GridColumn(key: 'EodCounter', label: 'EOD', sort: 'EodCounter', mono: true),
            new GridColumn(key: 'TillDescription', label: 'Till', sort: 'TillNo'),
            new GridColumn(key: 'ShiftDescription', label: 'Shift'),
            new GridColumn(key: 'EmployeeName', label: 'Cashier', sort: 'EmployeeName', wide: true),
            new GridColumn(key: 'Sales', label: 'Sales', format: 'money', sort: 'Sales', total: true),
            new GridColumn(key: 'AgeDays', label: 'Age (days)', format: 'number', sort: 'AgeDays'),
            new GridColumn(key: 'PosUserId', label: 'Operator ID', mono: true, visible: false),
            new GridColumn(key: 'LogFile', label: 'Log file', mono: true, visible: false, filter: 'none'),
            new GridColumn(key: 'AllocatedBy', label: 'Allocated in', visible: false, filter: 'none',
                title: 'agora: saved on ZREAD Allocation. legacy: allocated on the PumpIT ZREAD Update screen.'),
        ];
    }

    public function source(): GridSource
    {
        $allowed = $this->context->allowed();

        return new ProcedureSource(
            'agora.usp_Cash_GridZreadsNoCashup',
            acceptsFilters: true,
            extra: ['AllowedBranchIds' => $allowed === [] ? null : implode(',', $allowed)],
        );
    }

    public function dateRange(): bool
    {
        return true;
    }

    /** The day's Daily Banking screen, where this Z-read is listed as not captured. */
    public function rowActions(object $row): array
    {
        return [[
            'label' => 'Daily banking',
            'url' => route('app.cash.banking', [
                'branch_id' => $row->BranchId,
                'date' => substr((string) $row->ZReadDate, 0, 10),
                'till' => $row->TillNo,
                'shift' => $row->ShiftNo,
            ]),
        ]];
    }
}
