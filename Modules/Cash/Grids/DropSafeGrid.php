<?php

namespace Modules\Cash\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;

/**
 * Cash → Drop safe bags (T039): every bag dropped into a site's manual safe —
 * the mockup's Drop Safe grid and PumpIT's drop safe report
 * (dbo.sp_SelectDropSafe), across sites.
 *
 * One row per bag, from agora.usp_Cash_GridDropSafe. The Status column is the
 * "In the safe" chip — PumpIT's own rule, CollectionId still 0 — and the grid
 * lists those first. A bag over the threshold with no reason is flagged, not
 * hidden.
 *
 * The sites and the period (the drop day) are the grid's own — the branches
 * selector and the date range (feature-rules §3.3). An empty selection is the
 * sites the person may see (the procedure's AllowedBranchIds), never "every
 * site in the estate".
 *
 * A row names where to go next: its site's Drop safe screen, and the bag itself
 * there when it can still be corrected.
 */
class DropSafeGrid extends GridDefinition
{
    public const STATUSES = ['In the safe', 'Collected'];

    public const BANKED = ['On a cashup', 'Not on a cashup'];

    public const SOURCES = ['Agora', 'PumpIT'];

    public const REASON = ['Needs a reason', 'Has one, or needs none'];

    public function __construct(protected BranchContext $context) {}

    public function key(): string
    {
        return 'app.cash.dropsafe.bags';
    }

    public function title(): string
    {
        return 'Drop safe bags';
    }

    public function blurb(): ?string
    {
        return 'Every bag dropped into a manual safe, why, who dropped it and who witnessed it — '
            .'and whether the security company has collected it yet.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName', filter: 'text'),
            new GridColumn(key: 'DropDate', label: 'Dropped', format: 'date', sort: 'DropDate'),
            new GridColumn(key: 'DropTime', label: 'Time', sort: 'DropTime', mono: true),
            new GridColumn(key: 'BagNo', label: 'Bag number', sort: 'BagNo', mono: true),
            new GridColumn(key: 'Amount', label: 'Amount', format: 'money', sort: 'Amount', total: true),
            new GridColumn(key: 'Status', label: 'Collected', sort: 'Status', filter: 'set', options: self::STATUSES,
                title: 'In the safe until the security company collects it — PumpIT\'s CollectionId is still 0'),
            new GridColumn(key: 'ReasonForManualBag', label: 'Why the manual safe', sort: 'ReasonForManualBag', wide: true),
            new GridColumn(key: 'LimitReason', label: 'Reason over R2,000', sort: 'LimitReason', wide: true,
                title: 'Required above cash.dropsafe.reason_threshold (BRN_DropSafe.ReasonIfAmountMoreThanR2000)'),
            new GridColumn(key: 'CashierName', label: 'Cashier', sort: 'CashierName'),
            new GridColumn(key: 'ManagerName', label: 'Manager', sort: 'ManagerName',
                title: 'The manager who witnessed the drop'),
            new GridColumn(key: 'DBagNo', label: 'Collected in', sort: 'DBagNo', mono: true,
                title: 'The security company\'s bag — the reference the bank statement quotes'),
            new GridColumn(key: 'CollectionDate', label: 'Collected on', format: 'date', sort: 'CollectionDate'),
            new GridColumn(key: 'Banked', label: 'Cashup', sort: 'Banked', filter: 'set', options: self::BANKED,
                title: 'Whether the bag is on a cashup\'s cash-bags leg (BRN_DropSafe.IsSelectedForDailyBanking)'),
            new GridColumn(key: 'NeedsReason', label: 'Needs a reason', format: 'bool', visible: false, filter: 'set',
                options: self::REASON, title: 'Over the threshold with no reason given'),
            new GridColumn(key: 'RefNoFaultReported', label: 'Fault reference', visible: false, mono: true),
            new GridColumn(key: 'CollectionId', label: 'Collection', format: 'number', sort: 'CollectionId', visible: false, mono: true),
            new GridColumn(key: 'CashierCode', label: 'Cashier code', visible: false, mono: true),
            new GridColumn(key: 'ManagerCode', label: 'Manager code', visible: false, mono: true),
            new GridColumn(key: 'CapturedIn', label: 'Written by', sort: 'CapturedIn', visible: false, filter: 'set',
                options: self::SOURCES, title: 'Agora where the bag as it stands was written by Agora; PumpIT otherwise'),
            new GridColumn(key: 'CapturedAt', label: 'Captured', format: 'datetime', visible: false, filter: 'none'),
            new GridColumn(key: 'AgeDays', label: 'Age (days)', format: 'number', sort: 'AgeDays', visible: false),
        ];
    }

    public function source(): GridSource
    {
        $allowed = $this->context->allowed();

        return new ProcedureSource(
            'agora.usp_Cash_GridDropSafe',
            acceptsFilters: true,
            extra: [
                'AllowedBranchIds' => $allowed === [] ? null : implode(',', $allowed),
                'Threshold' => (float) config('cash.dropsafe.reason_threshold', 2000.00),
            ],
        );
    }

    public function cellsPartial(): string
    {
        return 'cash::grid._dropsafe-cells';
    }

    public function dateRange(): bool
    {
        return true;
    }

    /** The bag's site on the Drop safe screen — onto the bag itself while it can still be corrected. */
    public function rowActions(object $row): array
    {
        $open = ($row->Status ?? '') === 'In the safe' && ($row->Banked ?? '') !== 'On a cashup';

        return [[
            'label' => $open ? 'Open the bag' : 'Drop safe',
            'url' => route('app.cash.dropsafe', array_filter([
                'branch_id' => $row->BranchId,
                'bag' => $open ? $row->BagNo : null,
            ])),
        ]];
    }
}
