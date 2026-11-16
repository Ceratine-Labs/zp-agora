<?php

namespace Modules\Recon\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use Illuminate\Support\Carbon;

/**
 * The outstanding rows behind the bank recon dashboard — the Items tab.
 *
 * Ryan, 13 Oct 2026: "i think it will be a lot, excel struggles with it". So
 * the rows stay on the server and come a page at a time out of
 * agora.usp_Recon_GridOutstanding, filtered, sorted and exported there; the
 * dashboard above it adds the same rows up with agora.usp_Recon_DashboardOutstanding.
 *
 * SITES AND DATES ARE THE DASHBOARD'S. The key has no `:qualifier`, so this
 * grid reads the same `branches[]`, `from` and `to` the dashboard's scope form
 * sends, and the two can never be looking at different sites. The grid does
 * not draw its own site picker (the page passes no branches) and its scope
 * form carries the selection through when somebody searches.
 *
 * The area and side come off the request as `area` and `side`, set by the
 * links on the pivot, the summary and the per-site tables.
 *
 * A Reference opens Trace (rowUrl()), the screen that finds a value's other
 * half across both sides and every area.
 */
class ReconOutstandingGrid extends GridDefinition
{
    /** The values `side` may take; anything else is both sides. */
    public const SIDES = ['Bank', 'PumpIT'];

    public function key(): string
    {
        return 'app.recon.outstanding';
    }

    /** It sits on the recon dashboard, whose permission guards its extract. */
    public function screenRoute(): ?string
    {
        return 'app.recon.dashboard';
    }

    public function title(): string
    {
        return 'Outstanding items';
    }

    public function blurb(): ?string
    {
        return 'Every bank line and PumpIT deposit still unreconciled, oldest first. '
            .'Bank lines show their statement line number as the reference.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName'),
            new GridColumn(key: 'ReconArea', label: 'Area', sort: 'ReconArea'),
            new GridColumn(key: 'Side', label: 'Side', sort: 'Side'),
            new GridColumn(key: 'TxnDate', label: 'Date', format: 'date', sort: 'TxnDate'),
            new GridColumn(key: 'AgeDays', label: 'Age (days)', format: 'number', sort: 'AgeDays'),
            // Opens Trace on the value (rowUrl()), where it is long enough to trace.
            new GridColumn(key: 'Reference', label: 'Reference', sort: 'Reference', mono: true, link: true),
            new GridColumn(key: 'Detail', label: 'Detail', sort: 'Detail', wide: true),
            new GridColumn(key: 'Amount', label: 'Amount', format: 'money', sort: 'Amount', total: true),
        ];
    }

    /** @return array<string, GridFilter> */
    public function filters(): array
    {
        return [
            'BranchName' => new GridFilter(column: 'BranchName', type: 'text'),
            'ReconArea' => new GridFilter(column: 'ReconArea', type: 'set',
                options: ['ABSA', 'FNB', 'CashMachine', 'CashBags', 'SmartATM', 'Unclassified']),
            'Side' => new GridFilter(column: 'Side', type: 'set', options: self::SIDES),
            'Reference' => new GridFilter(column: 'Reference', type: 'text'),
            'Detail' => new GridFilter(column: 'Detail', type: 'text'),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource(
            'agora.usp_Recon_GridOutstanding',
            acceptsFilters: true,
            extra: [
                'ReconArea' => self::area(),
                'Side' => self::side(),
                // The grant, not the selection — see ReconRunGrid.
                'AllowedBranchIds' => ($allowed = app(BranchContext::class)->allowed()) === [] ? null : implode(',', $allowed),
                'AsAt' => BusinessDate::today()->toDateString(),
            ],
        );
    }

    public function defaultSort(): ?string
    {
        return 'TxnDate';
    }

    /**
     * A reference opens Trace (16 Nov 2026): the same value across the whole
     * ledger and the legacy estate behind it, at the row's own site, a
     * fortnight either side of its date — where its other half, or the reason
     * it has none, is found. Trace refuses a term under three characters, so
     * a shorter reference is not a link. The window ends today: a row dated in
     * the future (a To past today) keeps its own date inside it.
     */
    public function rowUrl(object $row): ?string
    {
        $reference = trim((string) ($row->Reference ?? ''));

        if (mb_strlen($reference) < 3) {
            return null;
        }

        try {
            $day = Carbon::parse(substr((string) ($row->TxnDate ?? ''), 0, 10))->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        $today = BusinessDate::today();
        $end = $day->copy()->addDays(14)->min($today->greaterThan($day) ? $today : $day);

        return route('app.recon.trace', array_filter([
            'q' => $reference,
            'branch_id' => (int) ($row->BranchId ?? 0) ?: null,
            'from' => $day->copy()->subDays(14)->toDateString(),
            'to' => $end->toDateString(),
        ], fn (mixed $value) => $value !== null));
    }

    public function branchSelector(): bool
    {
        return true;
    }

    /** The area off the request: one of the recon areas, Unclassified, or null for all. */
    public static function area(): ?string
    {
        $area = request()->query('area');

        return is_string($area) && ($area === 'Unclassified' || array_key_exists($area, (array) config('recon.areas')))
            ? $area
            : null;
    }

    /** The side off the request, or null for both. */
    public static function side(): ?string
    {
        $side = request()->query('side');

        return is_string($side) && in_array($side, self::SIDES, true) ? $side : null;
    }
}
