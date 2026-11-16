<?php

namespace Modules\Recon\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\Export\CsvWriter;
use App\Grid\Export\GridExportTooLarge;
use App\Grid\Export\GridExtract;
use App\Grid\Export\XlsxWriter;
use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Core\Models\Branch;
use Modules\Core\Models\User;
use Modules\Core\Services\MenuAccessService;
use Modules\Recon\Grids\ReconOutstandingGrid;
use Modules\Recon\Grids\ReconPivotSheet;
use Modules\Recon\Services\ReconDashboardService;
use Modules\Recon\Services\ReconPivot;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bank recon dashboard: what is still outstanding, everywhere.
 *
 * Ryan, 13 Oct 2026: "a link under the bank recon that leads to a controller
 * that per branch shows what is outstanding … a summary view and a per branch
 * view … a dropdown default on all and can select multiple branches". And the
 * reason it has to be careful: "i think it will be a lot, excel struggles".
 *
 * FOUR VIEWS OF ONE SCOPE. Sites (default: every site the person is granted)
 * and an optional date window, chosen once:
 *
 *   pivot    every site down the side, the five areas (or the days) across,
 *            what is outstanding in each cell (16 Nov 2026, the customer's
 *            ask; the default, so the menu link lands on it)
 *   summary  the totals, the ageing, and one row per site
 *   sites    each site's areas, bank against PumpIT, with ageing, and a Work
 *            link into the recon centre per area
 *   items    the rows themselves, a page at a time (ReconOutstandingGrid)
 *
 * The first three read ONE procedure call that adds everything up on the
 * server, kept a few minutes per scope (ReconDashboardService::figures()), so
 * switching between them, between the pivot's lenses or to an export runs
 * nothing on PumpIT. The pivot also reads the feed history, a second scan kept
 * an hour. The items view is a grid procedure that pages. No view loads the
 * rows it does not show.
 *
 * Everything a figure means — its colour, its glyph, its link — is decided in
 * ReconPivot; this coerces the parameters, reads, and hands the answer over.
 */
class ReconDashboardController extends Controller
{
    public const VIEWS = ['pivot' => 'Pivot', 'summary' => 'Summary', 'sites' => 'Per site', 'items' => 'Items'];

    /** The pivot's columns: the five areas, or the dates (days, weeks or months). */
    public const COLS = ['areas' => 'Areas', 'dates' => 'Dates'];

    /** Its lenses. Difference is the areas' alone: a period's two sides never line up. */
    public const LENSES = ['amount' => 'Amount', 'count' => 'Count', 'difference' => 'Difference'];

    public function __construct(
        protected ReconDashboardService $service,
        protected GridRegistry $registry,
        protected GridService $grids,
    ) {}

    public function index(Request $request, BranchContext $context): View|RedirectResponse
    {
        // `view[]=…` is an array, not a view: the default, not a 500.
        $requested = $request->query('view');
        $view = is_string($requested) && array_key_exists($requested, self::VIEWS) ? $requested : 'pivot';
        $asAt = BusinessDate::today()->toDateString();
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $selected = $this->selected($request, $context);
        $params = $this->params($request);
        $link = $this->linker($selected, $from, $to);
        $keep = $this->keep($view, $params);

        $data = [
            'view' => $view,
            'views' => self::VIEWS,
            'asAt' => $asAt,
            'from' => $from,
            'to' => $to,
            'selected' => $selected,
            'branches' => $context->isBranchWorkspace() ? null : $this->pickerSites($selected),
            'area' => ReconOutstandingGrid::area(),
            'side' => ReconOutstandingGrid::side(),
            'params' => $params,
            'keep' => $keep,
            'presets' => $this->presets($view, $keep, $selected, $from, $to, $asAt),
            'link' => $link,
            'areaLabels' => collect((array) config('recon.areas'))->map(fn (array $a) => $a['label'])
                ->put('Unclassified', 'Unclassified bank lines')->all(),
            // A From after the To asks for a window nothing can fall in. Said,
            // rather than answered with an empty screen that reads as "all clear".
            'inverted' => $from !== null && $to !== null && $from > $to,
            'refusal' => null,
            'figures' => null,
            'pivot' => null,
            'table' => null,
            'controls' => [],
            'exports' => [],
            'kpis' => [],
            'notices' => [],
            'readAt' => null,
            'grid' => null,
        ];

        if ($view === 'items') {
            $data['grid'] = $this->grids->build(
                $this->registry->findOrFail('app.recon.outstanding'),
                $request,
                $request->user()?->Id,
            );
            $data['exports'] = $this->exports($selected, $from, $to, $params);

            return view('recon::dashboard', $data);
        }

        // From after To: the notice, and nothing read. Figures for a window
        // nothing can fall in are every site Clear and every tile R0 — the
        // all-clear the notice exists to prevent.
        if ($data['inverted']) {
            return view('recon::dashboard', $data);
        }

        // Refresh reads both again now, then sends the browser to the same
        // address without `fresh`: left in it, every reload, bookmark or link
        // copied from the page re-ran both scans on live PumpIT. A refusal is
        // shown here, as the page below would show it.
        if ($params['fresh']) {
            try {
                $this->service->figures($selected, $context->allowed(), $from, $to, $asAt, true);
            } catch (AgoraProcException $e) {
                $data['refusal'] = $e->getMessage();

                return view('recon::dashboard', $data);
            }
            if ($view === 'pivot') {
                $this->service->feeds($context->allowed(), $asAt, true);
            }

            return redirect()->to($request->fullUrlWithoutQuery(['fresh']));
        }

        try {
            $figures = $this->service->figures($selected, $context->allowed(), $from, $to, $asAt);
        } catch (AgoraProcException $e) {
            $data['refusal'] = $e->getMessage();

            return view('recon::dashboard', $data);
        }

        // The feed history only changes what the pivot's cells say about a
        // side with nothing outstanding — ✓, never, stopped, · — so Summary and
        // Per site do not spend the second scan on it.
        $feeds = $view === 'pivot' ? $this->service->feeds($context->allowed(), $asAt) : null;
        $pivot = new ReconPivot($figures, $feeds, $selected, $from, $to, $asAt);

        $data['figures'] = $this->service->shape(collect($figures['rows']));
        $data['pivot'] = $pivot;
        $data['kpis'] = $pivot->kpis($link);
        $data['readAt'] = $figures['read_at'];
        // notices() is the late statements (warn) and the unreadable feed
        // history (info); the second is about the pivot's glyphs, and it is
        // only true of a page that asked for the history.
        $data['notices'] = array_values(array_filter($pivot->notices(),
            fn (array $notice) => $view === 'pivot' || $notice['tone'] !== 'info'));

        if ($view === 'pivot') {
            $data['table'] = $params['cols'] === 'dates'
                ? $pivot->dates($params['lens'], $params['side'], $params['area'], $link)
                : $pivot->areas($params['lens'], $link);
            $data['controls'] = $this->controls($params, $link);
            $data['exports'] = $this->exports($selected, $from, $to, $params);
        }

        return view('recon::dashboard', $data);
    }

    /**
     * The pivot as a file (§5): the table on screen (`kind=table`) or one row
     * per site × area × side (`kind=long`), as CSV or XLSX, from the same
     * cached figures — so normally nothing runs on PumpIT. Through
     * GridExtract::fromRows() and the grid's own writers: a byte-order mark, a
     * typed number per figure, a frozen header and an autofilter.
     *
     * NOT the browser's sort or pill filter: the file is the whole scope in
     * the server's order, and the link that offers it says so.
     */
    public function export(Request $request, BranchContext $context): Response
    {
        // The extract's own rule (AuthorizeAccessGrid): the dashboard's
        // permission is on this route, and its menu entry is checked here.
        $user = $request->user();
        abort_unless($user instanceof User && app(MenuAccessService::class)->canReachRoute($user, 'app.recon.dashboard'), 403);

        $asAt = BusinessDate::today()->toDateString();
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $selected = $this->selected($request, $context);
        $params = $this->params($request);
        $kind = $request->query('kind') === 'long' ? 'long' : 'table';

        // As on the page: a file of Clear sites for a window nothing can fall
        // in would say the opposite of what happened.
        abort_if($from !== null && $to !== null && $from > $to, 422, 'From is after To: nothing can be dated in that window.');

        try {
            $figures = $this->service->figures($selected, $context->allowed(), $from, $to, $asAt);
        } catch (AgoraProcException $e) {
            // A refusal is a message, not a 500 — as the grid extract answers.
            abort(422, $e->getMessage());
        }

        $pivot = new ReconPivot($figures, $this->service->feeds($context->allowed(), $asAt), $selected, $from, $to, $asAt);
        $sheet = $pivot->sheet($kind, $params['cols'], $params['lens'], $params['side'], $params['area']);

        try {
            $extract = GridExtract::fromRows(new ReconPivotSheet($sheet['title'], $sheet['columns']), $sheet['columns'], $sheet['rows']);
        } catch (GridExportTooLarge $e) {
            abort(413, $e->getMessage());
        }

        return $request->query('format') === 'xlsx'
            ? app(XlsxWriter::class)->download($extract)
            : app(CsvWriter::class)->stream($extract);
    }

    /**
     * The pivot's parameters, coerced (§1.1): anything else falls back to the
     * default, so a hand-edited link still opens a page.
     *
     * @return array{cols: string, lens: string, side: string, area: ?string, status: ?string, fresh: bool}
     */
    private function params(Request $request): array
    {
        $cols = (string) (is_string($request->query('cols')) ? $request->query('cols') : '');
        $cols = array_key_exists($cols, self::COLS) ? $cols : 'areas';
        $lens = (string) (is_string($request->query('lens')) ? $request->query('lens') : '');
        $lens = array_key_exists($lens, self::LENSES) && ! ($cols === 'dates' && $lens === 'difference') ? $lens : 'amount';
        $area = $request->query('area');
        $status = $request->query('status');

        return [
            'cols' => $cols,
            'lens' => $lens,
            // Side and area are the Dates view's; on Items the same two names
            // are the drill, read by ReconOutstandingGrid itself.
            'side' => $cols === 'dates' && $request->query('side') === 'Bank' ? 'Bank' : 'PumpIT',
            'area' => $cols === 'dates' && is_string($area) && in_array($area, ReconPivot::AREAS, true) ? $area : null,
            'status' => is_string($status) && array_key_exists($status, ReconPivot::STATUSES) ? $status : null,
            'fresh' => $request->query('fresh') === '1',
        ];
    }

    /**
     * The page's links (ReconPivot's contract): the query wins over the
     * scope — the sites picked and the window — and a null drops the key.
     *
     * @param  array<int, int>  $selected
     * @return Closure(array<string, mixed>): string
     */
    private function linker(array $selected, ?string $from, ?string $to): Closure
    {
        $scope = ['branches' => $selected === [] ? null : $selected, 'from' => $from, 'to' => $to];

        // Relative: a pivot carries a few hundred of these, and the host is
        // the page's own.
        return fn (array $query): string => route('app.recon.dashboard',
            array_filter($query + $scope, fn (mixed $value) => $value !== null), false);
    }

    /**
     * What the scope form, its presets and "Every site" carry beside the
     * sites and the window: the pivot's columns, lens, side and area, or the
     * Items drill (area, side). Defaults are left out, so a link stays short.
     *
     * @param  array{cols: string, lens: string, side: string, area: ?string, status: ?string, fresh: bool}  $params
     * @return array<string, string>
     */
    private function keep(string $view, array $params): array
    {
        $keep = match ($view) {
            'pivot' => [
                'cols' => $params['cols'] === 'areas' ? null : $params['cols'],
                'lens' => $params['lens'] === 'amount' ? null : $params['lens'],
                'side' => $params['cols'] === 'dates' && $params['side'] === 'Bank' ? 'Bank' : null,
                'area' => $params['cols'] === 'dates' ? $params['area'] : null,
            ],
            'items' => ['area' => ReconOutstandingGrid::area(), 'side' => ReconOutstandingGrid::side()],
            default => [],
        };

        return array_filter($keep, fn (?string $value) => $value !== null);
    }

    /**
     * The window presets (§1.2): the last 7, 31 or 92 days to today, or
     * everything still outstanding. The 92 is the procedure's ceiling for day
     * columns, so each preset opens the Dates view at a grain it can draw.
     *
     * @param  array<string, string>  $keep
     * @param  array<int, int>  $selected
     * @return list<array{label: string, href: string, on: bool}>
     */
    private function presets(string $view, array $keep, array $selected, ?string $from, ?string $to, string $asAt): array
    {
        $day = fn (int $back) => Carbon::parse($asAt)->subDays($back)->toDateString();
        $presets = ['7 days' => $day(6), '31 days' => $day(30), '92 days' => $day(91), 'Everything' => null];
        $out = [];

        foreach ($presets as $label => $start) {
            $out[] = [
                'label' => $label,
                'href' => route('app.recon.dashboard', array_filter(['view' => $view] + $keep
                    + ['branches' => $selected === [] ? null : $selected, 'from' => $start], fn (mixed $v) => $v !== null)),
                'on' => $from === $start && $to === null,
            ];
        }

        return $out;
    }

    /**
     * The pivot's link chips: columns, lens, and for the Dates view the side
     * and the area. Each is a link served from the cached figures, so a
     * switch reads nothing.
     *
     * @param  array{cols: string, lens: string, side: string, area: ?string, status: ?string, fresh: bool}  $params
     * @param  Closure(array<string, mixed>): string  $link
     * @return list<array{label: string, active: string, items: list<array{key: string, label: string, href: string}>}>
     */
    private function controls(array $params, Closure $link): array
    {
        $dates = $params['cols'] === 'dates';
        $to = fn (array $change) => $link($change + [
            'view' => 'pivot',
            'cols' => $dates ? 'dates' : null,
            'lens' => $params['lens'] === 'amount' ? null : $params['lens'],
            'side' => $dates && $params['side'] === 'Bank' ? 'Bank' : null,
            'area' => $dates ? $params['area'] : null,
            'status' => null,
            'fresh' => null,
        ]);

        $controls = [
            ['label' => 'Columns', 'active' => $params['cols'], 'items' => [
                ['key' => 'areas', 'label' => 'Areas', 'href' => $to(['cols' => null, 'side' => null, 'area' => null])],
                ['key' => 'dates', 'label' => 'Dates', 'href' => $to(['cols' => 'dates',
                    'lens' => $params['lens'] === 'count' ? 'count' : null])],
            ]],
            ['label' => 'Figures', 'active' => $params['lens'], 'items' => array_values(array_filter([
                ['key' => 'amount', 'label' => 'Amount', 'href' => $to(['lens' => null])],
                ['key' => 'count', 'label' => 'Count', 'href' => $to(['lens' => 'count'])],
                $dates ? null : ['key' => 'difference', 'label' => 'Difference', 'href' => $to(['lens' => 'difference'])],
            ]))],
        ];

        if ($dates) {
            $controls[] = ['label' => 'Side', 'active' => $params['side'], 'items' => [
                ['key' => 'PumpIT', 'label' => 'PumpIT', 'href' => $to(['side' => null])],
                ['key' => 'Bank', 'label' => 'Bank', 'href' => $to(['side' => 'Bank'])],
            ]];
            $areas = [['key' => 'all', 'label' => 'All areas', 'href' => $to(['area' => null])]];
            foreach (ReconPivot::AREAS as $area) {
                $areas[] = ['key' => $area, 'label' => ReconPivot::LABELS[$area], 'href' => $to(['area' => $area])];
            }
            $controls[] = ['label' => 'Area', 'active' => $params['area'] ?? 'all', 'items' => $areas];
        }

        return $controls;
    }

    /**
     * The pivot's three files, for the scope and the view on screen.
     *
     * @param  array<int, int>  $selected
     * @param  array{cols: string, lens: string, side: string, area: ?string, status: ?string, fresh: bool}  $params
     * @return array{xlsx: string, csv: string, long: string}
     */
    private function exports(array $selected, ?string $from, ?string $to, array $params): array
    {
        $dates = $params['cols'] === 'dates';
        $query = fn (array $q) => route('app.recon.dashboard.export', array_filter($q + [
            'cols' => $dates ? 'dates' : null,
            'lens' => $params['lens'] === 'amount' ? null : $params['lens'],
            'side' => $dates && $params['side'] === 'Bank' ? 'Bank' : null,
            'area' => $dates ? $params['area'] : null,
            'branches' => $selected === [] ? null : $selected,
            'from' => $from,
            'to' => $to,
        ], fn (mixed $v) => $v !== null));

        return [
            'xlsx' => $query(['format' => 'xlsx', 'kind' => 'table']),
            'csv' => $query(['format' => 'csv', 'kind' => 'table']),
            'long' => $query(['format' => 'xlsx', 'kind' => 'long', 'cols' => null, 'lens' => null, 'side' => null, 'area' => null]),
        ];
    }

    /**
     * The site picker (§1.2): trading sites first, then the mothballed ones —
     * they still receive money (Ngwelezane OK's ABSA lines run to 15 Nov
     * 2026), and "every site" already counts them, so they must be pickable.
     * Not the administrative entities, which keep no day. A site already
     * picked stays in the list whatever it is, so pressing Show keeps it
     * rather than widening the scope to every site. BranchScope narrows the
     * list to the grant.
     *
     * @param  array<int, int>  $selected
     * @return Collection<int, Branch>
     */
    private function pickerSites(array $selected): Collection
    {
        return Branch::query()
            ->where(function ($query) use ($selected) {
                $query->where(function ($listed) {
                    $listed->where('IsAdminEntity', false)
                        ->where(fn ($kind) => $kind->where(fn ($trading) => $trading->where('IsTrading', true)->where('IsActive', true))
                            ->orWhere('Status', 'Mothballed'));
                });
                if ($selected !== []) {
                    $query->orWhereIn('BranchId', $selected);
                }
            })
            ->orderByDesc('IsTrading')
            ->orderBy('Name')
            ->get();
    }

    /**
     * The sites picked, checked against the grant. In the branch workspace the
     * site is pinned and the query string is ignored (feature-rules §3.3).
     *
     * @return array<int, int>
     */
    private function selected(Request $request, BranchContext $context): array
    {
        if ($context->isBranchWorkspace()) {
            return array_values(array_filter([$context->id()]));
        }

        $given = $request->query('branches');
        $ids = is_array($given) ? $given : ($given === null ? [] : explode(',', (string) $given));

        return collect($ids)
            ->reject(fn (mixed $id) => is_array($id) || is_object($id))
            ->map(fn (mixed $id) => (int) trim((string) $id))
            // A site id is a positive int: 0, a negative or one past the
            // procedure's int (which TRY_CONVERT drops) is no site at all.
            ->filter(fn (int $id) => $id > 0 && $id <= 2147483647)
            ->unique()
            ->filter(fn (int $id) => $context->maySee($id))
            ->values()
            ->all();
    }

    /**
     * A Y-m-d day from 1900 to 2099, or null. Outside that the procedures'
     * datetime arithmetic overflows — `from=0001-01-01` and `to=9999-12-31`
     * were a 500 on the page and the export — and no recon window lies there.
     */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(19|20)\d{2}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
