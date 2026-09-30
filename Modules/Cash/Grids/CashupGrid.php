<?php

namespace Modules\Cash\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;

/**
 * Cash → Cashups: the manager's view (T037).
 *
 * Every cashup in the period at the chosen sites, with its Z-read, what it
 * actually totals, the variance, the cash bags, the cards and the pump
 * shortages, whether the Z-read agrees, and where the cashup stands. It is the
 * legacy "Daily Banking - Managers View" and the mockup's Cashups screen in
 * one grid, and its queues are one parameter (`show`) carried in the URL:
 *
 *   all       every cashup
 *   open      not signed off yet — Open, or Confirmed and waiting on a close
 *   disagree  the Z-read does not agree with the cashup
 *
 * The site and the period are the grid's own (the branches selector and the
 * date range), because this is a head-office result set across sites
 * (feature-rules §3.3). An empty selection is the sites the person may see,
 * passed to the procedure as @AllowedBranchIds, never "every site in the
 * estate".
 *
 * Variance, Status and Z-read are coloured, and nothing else: a variance by
 * how far it is from nought, a status by whether it still needs someone.
 *
 * A row opens underneath itself onto the cashup — its legs in brief, what was
 * confirmed, and the press that acts on it — with a link to Daily Banking for
 * the whole of it.
 */
class CashupGrid extends GridDefinition
{
    public const STATUSES = ['Open', 'Confirmed', 'Closed', 'Day balanced'];

    public const CHECKS = ['Agrees', 'No Z-read', 'Z-read differs', 'Other cashier'];

    public function __construct(protected BranchContext $context) {}

    public function key(): string
    {
        return 'app.cash.cashups';
    }

    public function title(): string
    {
        return 'Cashups';
    }

    public function blurb(): ?string
    {
        return 'Each cashup with its Z-read, what was actually declared and the variance between them. '
            .'Open a row for its legs and to confirm or close it.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName', filter: 'text'),
            new GridColumn(key: 'TransactionDate', label: 'Date', format: 'date', sort: 'TransactionDate'),
            new GridColumn(key: 'ShiftDescription', label: 'Shift', sort: 'ShiftDescription'),
            new GridColumn(key: 'TillDescription', label: 'Till', sort: 'TillNo'),
            new GridColumn(key: 'EmployeeName', label: 'Cashier', sort: 'EmployeeName', wide: true),
            new GridColumn(key: 'ZRead', label: 'Z read', format: 'money', sort: 'ZRead', total: true,
                title: 'The Z-read the cashup is balanced against (BRN_DailyBanking.ClosingBalance)'),
            new GridColumn(key: 'ActualTotal', label: 'Actual total', format: 'money', sort: 'ActualTotal', total: true,
                title: 'Every leg the legacy Daily Banking grid adds up — cash bags, cards, devices, accounts, expenses, pump shortages and the rest'),
            new GridColumn(key: 'Variance', label: 'Variance', format: 'money', sort: 'Variance', total: true,
                title: 'Actual total less the Z read — exactly PumpIT\'s own EmployeeAmount'),
            new GridColumn(key: 'CashBags', label: 'Cash bags', format: 'money', sort: 'CashBags', total: true),
            new GridColumn(key: 'Cards', label: 'Cards', format: 'money', sort: 'Cards', total: true,
                title: 'FNB and ABSA card legs'),
            new GridColumn(key: 'StaffShorts', label: 'Pump shortages', format: 'money', sort: 'StaffShorts', total: true),
            new GridColumn(key: 'ZreadCheck', label: 'Z-read', sort: 'ZreadCheck', filter: 'set', options: self::CHECKS,
                title: 'Whether the Z-read allocated to this till and shift agrees with the cashup'),
            new GridColumn(key: 'Status', label: 'Cashup', sort: 'Status', filter: 'set', options: self::STATUSES,
                title: 'Open · Confirmed (waiting on a manager) · Closed · Day balanced (closed off in PumpIT)'),
            new GridColumn(key: 'EmployeeCode', label: 'Cashier code', mono: true, visible: false, filter: 'none'),
            new GridColumn(key: 'DayEndNo', label: 'Day-end no', mono: true, visible: false),
            new GridColumn(key: 'Debtors', label: 'Accounts', format: 'money', visible: false, filter: 'none'),
            new GridColumn(key: 'CashDevices', label: 'Cash devices', format: 'money', visible: false, filter: 'none'),
            new GridColumn(key: 'SmartATM', label: 'Smart ATM', format: 'money', visible: false, filter: 'none'),
            new GridColumn(key: 'DirectDeposits', label: 'Direct deposits', format: 'money', visible: false, filter: 'none'),
            new GridColumn(key: 'Expenses', label: 'Expenses', format: 'money', visible: false, filter: 'none'),
            new GridColumn(key: 'Suppliers', label: 'Suppliers', format: 'money', visible: false, filter: 'none'),
            new GridColumn(key: 'ZreadSales', label: 'Z-read sales', format: 'money', visible: false, filter: 'none',
                title: 'The sales on the Z-read(s) allocated to this till and shift (agora.vw_CashZread)'),
            new GridColumn(key: 'StoredVariance', label: 'Stored variance', format: 'money', visible: false, filter: 'none',
                title: 'What PumpIT last stored as EmployeeAmount — differs from Variance when a leg moved after the save'),
            new GridColumn(key: 'ConfirmedByName', label: 'Confirmed by', visible: false, filter: 'none'),
            new GridColumn(key: 'ConfirmNote', label: 'Why', wide: true, visible: false, filter: 'none'),
            new GridColumn(key: 'ClosedByName', label: 'Closed by', visible: false, filter: 'none'),
            new GridColumn(key: 'AgeDays', label: 'Age (days)', format: 'number', sort: 'AgeDays', visible: false),
        ];
    }

    public function source(): GridSource
    {
        $request = request();
        $show = (string) $request->query('show', 'all');
        $over = $request->query('variance_over');
        $allowed = $this->context->allowed();

        return new ProcedureSource(
            'agora.usp_Cash_GridCashups',
            acceptsFilters: true,
            extra: [
                'Show' => in_array($show, ['open', 'disagree'], true) ? $show : 'all',
                'VarianceOver' => is_numeric($over) && (float) $over >= 0 ? (float) $over : null,
                'AllowedBranchIds' => $allowed === [] ? null : implode(',', $allowed),
            ],
        );
    }

    public function cellsPartial(): string
    {
        return 'cash::grid._cashup-cells';
    }

    public function dateRange(): bool
    {
        return true;
    }

    public function detailMode(): string
    {
        return 'expand';
    }

    public function detailUrl(object $row): ?string
    {
        return route('app.cash.cashups.row', [
            'branch_id' => $row->BranchId,
            'date' => substr((string) $row->TransactionDate, 0, 10),
            'till' => $row->TillNo,
            'shift' => $row->ShiftNo,
        ]);
    }
}
