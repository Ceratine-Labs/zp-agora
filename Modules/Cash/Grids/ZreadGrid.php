<?php

namespace Modules\Cash\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;
use Modules\Cash\Services\ZreadService;
use Modules\Cash\Support\ZreadKey;
use Modules\Cash\Support\ZreadScope;

/**
 * Cash → ZREAD Allocation: one site's Z-reads over a period.
 *
 * THE SCOPE IS THE SCREEN'S, NOT THE GRID'S. The site, the period, the
 * location and "show allocated too" are chosen in the parameter block above
 * the grid, read once by ZreadScope, and handed to the procedure as named
 * arguments. The grid's own scope form keeps only the search box, and carries
 * the screen's parameters through as hidden fields because none of them is a
 * name it owns. So the grid's branch selector and date range are both off:
 * showing them would be a second, disagreeing way to say the same thing.
 *
 * ONLY THE DECISION COLUMN IS COLOURED (T036). Every other cell is the value
 * plain — a proposed person is not rendered in a brand colour, it is not
 * rendered at all until it is staged; the proposal lives in the Decision cell,
 * as an <x-proposal>, and in the row's panel with its evidence.
 *
 * A row opens underneath itself (row-detail.js) onto the proposal, the
 * evidence, the accept / set / clear forms and who normally works that
 * terminal. It is an expand rather than a route change because the list is the
 * work: the person goes down it.
 */
class ZreadGrid extends GridDefinition
{
    public function __construct(
        protected ZreadService $service,
        protected BranchContext $context,
    ) {}

    public function key(): string
    {
        return 'app.cash.zread';
    }

    public function title(): string
    {
        return 'Z-readings';
    }

    public function blurb(): ?string
    {
        return 'Each Z-read with where it stands. Open a row to see why the system proposes what it does, '
            .'and to accept it, set it by hand, or clear what is staged.';
    }

    public function columns(): array
    {
        $locations = (array) config('cash.zread.locations', []);

        return [
            new GridColumn(key: 'ZReadDate', label: 'Date', format: 'date', sort: 'ZReadDate'),
            new GridColumn(key: 'EodCounter', label: 'EOD', sort: 'EodCounter', mono: true),
            new GridColumn(key: 'Location', label: 'Loc', sort: 'Location', filter: 'set', options: $locations),
            new GridColumn(key: 'TerminalNo', label: 'Terminal', sort: 'TerminalNo', mono: true),
            new GridColumn(key: 'Sales', label: 'Sales', format: 'money', sort: 'Sales', total: true),
            new GridColumn(key: 'LogFile', label: 'Log file', sort: 'LogFile', mono: true),
            new GridColumn(key: 'PosUserId', label: 'Operator ID', sort: 'PosUserId', mono: true,
                title: 'The till operator ID the POS printed on the reading — not an employee code'),
            new GridColumn(key: 'TillDescription', label: 'Till',
                title: 'What the Z-read carries, or will carry once Update ZREAD saves what is staged'),
            new GridColumn(key: 'ShiftDescription', label: 'Shift'),
            new GridColumn(key: 'EmployeeName', label: 'Employee', sort: 'EmployeeName', wide: true),
            new GridColumn(key: 'Decision', label: 'Decision', sort: 'Decision', wide: true, filter: 'set',
                options: ['Certain', 'Likely', 'Review', 'Needs a person', 'Staged', 'Allocated'],
                title: 'Allocated, staged, or — while open — how sure the system is of its proposal'),
            new GridColumn(key: 'Evidence', label: 'Evidence', wide: true, visible: false),
            new GridColumn(key: 'ShiftEvidence', label: 'Shift evidence', wide: true, visible: false, filter: 'none'),
            new GridColumn(key: 'ShiftSource', label: 'Shift from', visible: false, filter: 'none',
                title: 'pattern: the certain allocation · operator: its shift history · sequence: the two-a-day rule'),
            new GridColumn(key: 'ProposedEmployeeName', label: 'Proposed person', visible: false, filter: 'none'),
            new GridColumn(key: 'ProposedShiftDescription', label: 'Proposed shift', visible: false, filter: 'none'),
            new GridColumn(key: 'ProposedTillDescription', label: 'Proposed till', visible: false, filter: 'none'),
            new GridColumn(key: 'EmployeeCode', label: 'Employee code', visible: false, mono: true, filter: 'none'),
            new GridColumn(key: 'ChangedInPumpIt', label: 'Changed in PumpIT', format: 'bool', visible: false, filter: 'none',
                title: 'Agora saved and wrote this allocation, and PumpIT has since been allocated differently — the later one is shown'),
            new GridColumn(key: 'AllocatedBy', label: 'Allocated in', visible: false, filter: 'none',
                title: 'agora: saved here. legacy: allocated on the PumpIT ZREAD Update screen.'),
        ];
    }

    /**
     * Keyed by column, and only the columns the procedure reads a filter for —
     * a filter it does not read would narrow nothing while looking as if it
     * had.
     *
     * @return array<string, GridFilter>
     */
    public function filters(): array
    {
        return [
            'ZReadDate' => new GridFilter(column: 'ZReadDate', type: 'date'),
            'EodCounter' => new GridFilter(column: 'EodCounter', type: 'text'),
            'Location' => new GridFilter(column: 'Location', type: 'set', options: (array) config('cash.zread.locations', [])),
            'TerminalNo' => new GridFilter(column: 'TerminalNo', type: 'text'),
            'Sales' => new GridFilter(column: 'Sales', type: 'number'),
            'LogFile' => new GridFilter(column: 'LogFile', type: 'text'),
            'PosUserId' => new GridFilter(column: 'PosUserId', type: 'text'),
            'TillDescription' => new GridFilter(column: 'TillDescription', type: 'text'),
            'ShiftDescription' => new GridFilter(column: 'ShiftDescription', type: 'text'),
            'EmployeeName' => new GridFilter(column: 'EmployeeName', type: 'text'),
            'Decision' => new GridFilter(column: 'Decision', type: 'set',
                options: ['Certain', 'Likely', 'Review', 'Needs a person', 'Staged', 'Allocated']),
            'Evidence' => new GridFilter(column: 'Evidence', type: 'text'),
        ];
    }

    public function source(): GridSource
    {
        $scope = ZreadScope::fromRequest(request(), $this->context);

        return new ProcedureSource(
            'agora.usp_Cash_GridZread',
            acceptsFilters: true,
            extra: [
                'BranchId' => $scope->branchId,
                'PeriodFrom' => $scope->from,
                'PeriodTo' => $scope->to,
                'Location' => $scope->location,
                'Show' => $scope->show,
                ...$this->service->priors(),
            ],
        );
    }

    public function cellsPartial(): string
    {
        return 'cash::grid._cells';
    }

    /** The screen's parameters carry the site; the grid's selector would be a second answer. */
    public function branchSelector(): bool
    {
        return false;
    }

    public function detailMode(): string
    {
        return 'expand';
    }

    public function detailUrl(object $row): ?string
    {
        $scope = ZreadScope::fromRequest(request(), $this->context);

        return route('app.cash.zread.row', $scope->query() + ZreadKey::fromRow($row)->query());
    }
}
