<?php

namespace Modules\Recon\Services;

use App\Grid\GridColumn;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

/**
 * The bank recon pivot (16 Nov 2026): every site down the side, the five
 * areas (or the days) across, and in each cell what is still outstanding.
 *
 * Pure arithmetic over ReconDashboardService::figures() and ::feeds() — no
 * database and no route(). Every link comes from the caller's closure,
 * `fn (array $query): string`, in which the query wins over the page's scope
 * and a null drops the key. So this class decides everything about a cell —
 * its figure, colour, glyph, link, tooltip — and <x-recon::cell> only writes
 * it down.
 *
 * THE ARITHMETIC, per site × classified area (one place: measures()):
 *
 *   B bank, P PumpIT, T the PumpIT rows in transit (dated on or after the
 *   area's TransitFrom: the latest statement line less the lag, written in
 *   the procedure), P′ = P − T.
 *
 *     short = max(0, P′ − B)        Not in bank
 *     over  = max(0, B − P)         Not declared
 *     both  = min(B, P)             On both sides
 *     wait  = P − both − short      in transit, not yet in the bank
 *
 *   so B = both + over and P = both + short + wait, per cell, to the cent,
 *   whatever the signs. The asymmetry is deliberate: transit only excuses
 *   PumpIT money. Bank lines that already arrived for transit-window deposits
 *   would otherwise read "Not declared" at every active site every morning.
 *   Amounts are held in whole cents here, so every total ties exactly.
 *
 * THE TOLERANCE, max(R100, 1% of the larger side), only colours and labels;
 * no sum ever passes through it.
 *
 * STATUS per area, first match wins: Clear (no rows) → In transit (no bank
 * rows, every PumpIT row in transit) → Bank feed (no bank line ever, deposits
 * waiting) → Not in bank → Not declared → To match (something current beyond
 * transit) → Backlog. A site takes its worst area, by STATUSES' priority.
 *
 * GLYPHS where a side has nothing outstanding: ✓ used and clear, `stopped`,
 * `never`, `·` neither side ever used, `—` the feed history could not be read.
 * An unknown is never drawn as ✓.
 */
final class ReconPivot
{
    /** The classified areas, in the order the screen shows them. */
    public const AREAS = ['ABSA', 'FNB', 'CashMachine', 'CashBags', 'SmartATM'];

    public const SIDES = ['Bank', 'PumpIT'];

    /** Short labels for headings and tooltips. */
    public const LABELS = [
        'ABSA' => 'ABSA',
        'FNB' => 'FNB',
        'CashMachine' => 'Cash machine',
        'CashBags' => 'Cash bags',
        'SmartATM' => 'Smart ATM',
    ];

    /** Every status, in the order a site takes its worst one; the chip tone each wears. */
    public const STATUSES = [
        'Not in bank' => ['tone' => 'crit', 'priority' => 1],
        'Not declared' => ['tone' => 'warn', 'priority' => 2],
        'Bank feed' => ['tone' => 'serious', 'priority' => 3],
        'To match' => ['tone' => 'neutral', 'priority' => 4],
        'Backlog' => ['tone' => 'neutral', 'priority' => 5],
        'In transit' => ['tone' => 'neutral', 'priority' => 6],
        'Clear' => ['tone' => 'good', 'priority' => 7],
    ];

    /** The Status heading's data-tt-pill-order. */
    public const PILL_ORDER = 'Not in bank|Not declared|Bank feed|To match|Backlog|In transit|Clear';

    public const LENSES = ['amount', 'count', 'difference'];

    /** What a side with nothing outstanding shows, by state (the span's rp-{state} class). */
    public const GLYPHS = ['clear' => '✓', 'stopped' => 'stopped', 'never' => 'never', 'unused' => '·', 'unknown' => '—'];

    public const PROCEDURES = 'agora.usp_Recon_DashboardOutstanding · agora.usp_Recon_DashboardFeeds';

    /** The accessible name of a `·` cell: the key's own words, with no tooltip to repeat them. */
    public const UNUSED = 'neither side ever used';

    /** Recon config's `dashboard` block, for a caller that passes none. */
    private const DEFAULTS = ['tolerance_rand' => 100, 'tolerance_pct' => 1, 'statements_behind_days' => 2];

    /** The procedure's lags, used only when the figures carry no coverage row for an area. */
    private const LAGS = ['ABSA' => 3, 'FNB' => 3, 'CashMachine' => 3, 'CashBags' => 7, 'SmartATM' => 3];

    /** Manual match lists this many rows a side (usp_Recon_GetSides @MaxRows). */
    private const MANUAL_ROWS = 500;

    private string $asAt;

    private ?string $from;

    private ?string $to;

    /** The window's last day: To, or today. */
    private string $end;

    /** asAt − 30: the first day "current" covers. */
    private string $recentFrom;

    private string $grain;

    /** @var array<string, mixed> */
    private array $settings;

    /** @var list<int> */
    private array $selected;

    private bool $single;

    private bool $feedsKnown;

    /** @var array<int, array{id: int, name: string, status: ?string, trading: ?bool, active: ?bool, admin: ?bool}> */
    private array $sites = [];

    /** @var array<int, array<string, array<string, array<string, mixed>>>> site → area → side → set-1 row, normalised */
    private array $out = [];

    /** @var array<int, array<string, mixed>> site → its Unclassified bank row, normalised */
    private array $loose = [];

    /** @var array<string, array{lag: int, last: ?string, from: string}> */
    private array $coverage = [];

    /** @var list<array{site: int, area: string, side: string, start: string, rows: int, amount: int, transitRows: int, transitAmount: int}> */
    private array $periods = [];

    /** @var array<int, array<string, array<string, array{first: ?string, last: ?string, rows: int}>>> */
    private array $feedSides = [];

    /** @var array<int, array<string, int>> */
    private array $rules = [];

    /** @var array<int, true> the sites the feed history covers */
    private array $covered = [];

    /** @var array<int, array<string, array<string, mixed>>> site → area → {bank, pumpit, m, f, status} */
    private array $facts = [];

    /** @var array<int, array<string, int>> site → the five areas' measures added up */
    private array $agg = [];

    /** @var array<int, string> */
    private array $siteStatus = [];

    /** @var list<int> the body rows, in server order */
    private array $rowIds = [];

    /** @var list<int> the sites with only Unclassified lines, sharing one row */
    private array $entityIds = [];

    /**
     * @param  array<string, mixed>  $figures  ReconDashboardService::figures()
     * @param  array<string, mixed>|null  $feeds  ReconDashboardService::feeds(); null when it could not be read
     * @param  array<int, int|string>  $selected  the sites picked; empty is every granted site
     * @param  array<string, mixed>  $settings  Recon config's `dashboard` block; empty reads it
     */
    public function __construct(
        array $figures,
        ?array $feeds,
        array $selected,
        ?string $from,
        ?string $to,
        string $asAt,
        array $settings = [],
    ) {
        $this->asAt = self::day($asAt) ?? $asAt;
        $this->from = self::day($from);
        $this->to = self::day($to);
        $this->end = $this->to ?? $this->asAt;
        $this->recentFrom = self::shift($this->asAt, -30);
        $this->grain = (string) ($figures['grain'] ?? 'month');
        $this->settings = $settings + (array) config('recon.dashboard', []) + self::DEFAULTS;
        $this->selected = array_values(array_unique(array_filter(array_map('intval', $selected), fn (int $id) => $id > 0)));

        $this->readSites(self::list($figures['sites'] ?? []), self::list($figures['rows'] ?? []));
        $this->readOutstanding(self::list($figures['rows'] ?? []));
        $this->readCoverage(self::list($figures['coverage'] ?? []));
        $this->readPeriods(self::list($figures['periods'] ?? []));
        $this->readFeeds($feeds);
        $this->decide();
    }

    /* ================================================================ public */

    /**
     * The six tiles, each `{key, label, value, note, tone, stripe, query, href}`
     * for <x-kpi>. They add up: Bank = On both sides + Not declared, and
     * PumpIT = On both sides + Not in bank + in transit, not yet in the bank
     * (tile 2's note).
     *
     * Tiles 3 to 5 open the Difference lens on the whole scope, whose totals
     * row carries the same three figures. Not on a Status pill: Status is a
     * site's WORST area judged on the last 30 days, and the tile is every
     * site's one-sided money, so the pill's sites do not add up to the tile
     * (23 Sep 2026: R5.17m on the tile, R1.00m at the one "Not in bank" site).
     *
     * @return list<array{key: string, label: string, value: string, note: string, tone: string, stripe: ?string, query: array<string, mixed>, href: ?string}>
     */
    public function kpis(?Closure $link = null): array
    {
        $t = $this->sums();
        $scope = $this->selected === [] ? null : $this->selected;
        $difference = ['view' => 'pivot', 'cols' => null, 'lens' => 'difference', 'status' => null,
            'side' => null, 'area' => null, 'branches' => $scope];

        $tiles = [
            ['key' => 'bank', 'label' => 'Bank lines outstanding', 'value' => Format::n($t['Bn']),
                'note' => self::rk($t['B']).($t['oldestB'] !== null ? ' · oldest '.self::date($t['oldestB'], 'j M Y') : ''),
                'tone' => 'neutral', 'stripe' => null, 'query' => $this->items(['branches' => $scope, 'side' => 'Bank'])],
            ['key' => 'pumpit', 'label' => 'PumpIT deposits outstanding', 'value' => Format::n($t['Pn']),
                'note' => self::rk($t['P']).' · '.self::rk($t['wait']).' in transit, not yet in the bank',
                'tone' => 'neutral', 'stripe' => null, 'query' => $this->items(['branches' => $scope, 'side' => 'PumpIT'])],
            ['key' => 'short', 'label' => 'Not in bank', 'value' => self::rk($t['short']),
                'note' => self::rk($t['short30']).' in the last 30 days',
                'tone' => $t['short'] !== 0 ? 'crit' : 'neutral', 'stripe' => $t['short'] !== 0 ? 'crit' : null,
                'query' => $difference],
            ['key' => 'over', 'label' => 'Not declared', 'value' => self::rk($t['over']),
                'note' => self::rk($t['over30']).' in the last 30 days',
                'tone' => $t['over'] !== 0 ? 'warn' : 'neutral', 'stripe' => $t['over'] !== 0 ? 'warn' : null,
                'query' => $difference],
            ['key' => 'both', 'label' => 'On both sides', 'value' => self::rk($t['both']),
                'note' => 'each side · probably the same money, not yet matched',
                'tone' => 'neutral', 'stripe' => null, 'query' => $difference],
            ['key' => 'unclassified', 'label' => 'Unclassified bank lines', 'value' => Format::n($t['Un']),
                'note' => 'in '.self::rk($t['Uin']).' · out '.self::rk($t['U'] - $t['Uin']).' · in no total',
                'tone' => 'neutral', 'stripe' => null,
                'query' => $this->items(['branches' => $scope, 'area' => 'Unclassified'])],
        ];

        return array_map(fn (array $tile) => $tile + ['href' => $this->href($link, $tile['query'])], $tiles);
    }

    /**
     * The scope's sums in rand (counts as integers), classified unless named:
     * what the tiles, the totals rows and the info button's identity read.
     *
     * @return array{bank_rows: int, bank_amount: float, bank_oldest: ?string, pumpit_rows: int, pumpit_amount: float,
     *     pumpit_oldest: ?string, short: float, short30: float, over: float, over30: float, both: float, wait: float,
     *     unclassified_rows: int, unclassified_amount: float, unclassified_in: float, unclassified_out: float}
     */
    public function totals(): array
    {
        $t = $this->sums();

        return [
            'bank_rows' => $t['Bn'],
            'bank_amount' => $t['B'] / 100,
            'bank_oldest' => $t['oldestB'],
            'pumpit_rows' => $t['Pn'],
            'pumpit_amount' => $t['P'] / 100,
            'pumpit_oldest' => $t['oldestP'],
            'short' => $t['short'] / 100,
            'short30' => $t['short30'] / 100,
            'over' => $t['over'] / 100,
            'over30' => $t['over30'] / 100,
            'both' => $t['both'] / 100,
            'wait' => $t['wait'] / 100,
            'unclassified_rows' => $t['Un'],
            'unclassified_amount' => $t['U'] / 100,
            'unclassified_in' => $t['Uin'] / 100,
            'unclassified_out' => ($t['U'] - $t['Uin']) / 100,
        ];
    }

    /**
     * Sites × areas. `amount` (the default) and `count` show each side of each
     * area; `difference` shows bank less PumpIT per area and the exact sums on
     * the right.
     *
     * Columns are `{key, kind: site|status|figure, label, title, class, noFilter,
     * pills, export, format}`; each row is `{id, name, href, sort, chips[],
     * status{label, tone, priority, tip}, cells{key → cell}}`; `entities` and
     * `total` are the two tfoot rows (`{label, tip, ids?, cells}`), both null
     * when one site is in scope.
     *
     * @return array<string, mixed>
     */
    public function areas(string $lens = 'amount', ?Closure $link = null): array
    {
        $lens = in_array($lens, self::LENSES, true) ? $lens : 'amount';
        $columns = $lens === 'difference' ? $this->differenceColumns() : $this->areaColumns($lens);

        $rows = [];
        foreach ($this->rowIds as $id) {
            $rows[] = $this->siteRow($id, $link) + [
                'cells' => $lens === 'difference' ? $this->differenceCells($id, $link) : $this->areaCells($id, $lens, $link),
            ];
        }

        return [
            'cols' => 'areas',
            'lens' => $lens,
            'columns' => $columns,
            'rows' => $rows,
            'entities' => $this->single ? null : $this->entityRow($columns, $lens, $link),
            'total' => $this->single ? null : ($lens === 'difference' ? $this->differenceTotal($link) : $this->areaTotal($lens, $link)),
            'count' => count($rows),
            'single' => $this->single,
            'subtitle' => $this->concentration(),
        ];
    }

    /**
     * Sites × periods, one side and one area (or all five) at a time. Days for a
     * window of 31 days or fewer, ISO weeks to 92, months (with earlier years
     * folded into one column each) beyond, or with no From. Same row shape as
     * areas(); the tfoot has the totals row only.
     *
     * @return array<string, mixed>
     */
    public function dates(string $lens = 'amount', string $side = 'PumpIT', ?string $area = null, ?Closure $link = null): array
    {
        $lens = $lens === 'count' ? 'count' : 'amount';
        $side = $side === 'Bank' ? 'Bank' : 'PumpIT';
        $area = in_array($area, self::AREAS, true) ? $area : null;
        $areas = $area === null ? self::AREAS : [$area];

        $mine = [];
        foreach ($this->periods as $p) {
            if ($p['side'] === $side && in_array($p['area'], $areas, true)) {
                $mine[$p['site']][] = $p;
            }
        }

        $layout = $this->periodLayout($mine);
        // Transit is PumpIT's alone: a bank line is never "in transit", so on
        // the Bank side no heading is dashed and none says so.
        $transit = $side === 'PumpIT' ? $this->transitFrom($areas) : null;
        $transitTitle = $this->transitTitle($areas);
        $format = $lens === 'count' ? 'number' : 'money';

        $columns = $this->leadColumns();
        foreach ($layout['columns'] as $c) {
            $isTransit = $transit !== null && $c['p2'] >= $transit;
            $columns[] = [
                'key' => $c['key'], 'kind' => 'figure', 'label' => $c['label'],
                'title' => $isTransit ? ($c['title'] === null ? $transitTitle : $c['title'].' · '.$transitTitle) : $c['title'],
                'class' => 'num'.($isTransit ? ' rp-transit-col' : ''), 'noFilter' => true, 'pills' => false,
                'transit' => $isTransit, 'export' => $c['iso'], 'format' => $format,
            ];
        }
        $columns[] = ['key' => 'Total', 'kind' => 'figure', 'label' => 'Total', 'title' => 'Every period in the window',
            'class' => 'num grp', 'noFilter' => false, 'pills' => false, 'transit' => false, 'export' => 'Total', 'format' => $format];

        $rows = [];
        $sums = [];
        foreach ($this->rowIds as $id) {
            $agg = $this->bucketPeriods($mine[$id] ?? [], $layout);
            $sums[$id] = $agg;
            $rows[] = $this->siteRow($id, $link) + ['cells' => $this->dateCells($id, $agg, $layout['columns'], $lens, $side, $area, $link)];
        }

        return [
            'cols' => 'dates',
            'lens' => $lens,
            'side' => $side,
            'area' => $area,
            'scheme' => $layout['scheme'],
            'columns' => $columns,
            'rows' => $rows,
            'entities' => null,
            'total' => $this->single ? null : $this->dateTotal($sums, $layout['columns'], $lens, $side, $area, $link),
            'count' => count($rows),
            'single' => $this->single,
            'subtitle' => $this->concentration(),
        ];
    }

    /**
     * The table on screen, or the long form, for GridExtract::fromRows():
     * `{title, columns: GridColumn[], rows}`. Money as rand, counts as numbers,
     * Over 90 as a percentage; ✓ is 0, and ·, never, stopped and — are empty.
     *
     * @return array{title: string, columns: list<GridColumn>, rows: Collection<int, object>}
     */
    public function sheet(string $kind = 'table', string $cols = 'areas', string $lens = 'amount', string $side = 'PumpIT', ?string $area = null): array
    {
        if ($kind === 'long') {
            return $this->longSheet();
        }

        $view = $cols === 'dates' ? $this->dates($lens, $side, $area) : $this->areas($lens);
        $columns = [new GridColumn('site', 'Site'), new GridColumn('site_status', 'Site status'), new GridColumn('status', 'Status')];
        $keys = [];

        foreach ($view['columns'] as $c) {
            if ($c['kind'] === 'figure') {
                $keys[$c['key']] = ['key' => self::exportKey($c['key']), 'format' => $c['format']];
                $columns[] = new GridColumn(self::exportKey($c['key']), (string) $c['export'], $c['format']);
            }
        }

        $line = function (string $site, ?string $siteStatus, ?string $status, array $cells) use ($keys): object {
            $row = ['site' => $site, 'site_status' => $siteStatus, 'status' => $status];
            foreach ($keys as $key => $column) {
                $row[$column['key']] = self::exportValue($cells[$key]['sort'] ?? '', $column['format']);
            }

            return (object) $row;
        };

        $rows = [];
        foreach ($view['rows'] as $row) {
            $rows[] = $line($row['name'], $this->siteStatusLabel($row['id']), $row['status']['label'], $row['cells']);
        }
        if ($view['entities'] !== null) {
            $rows[] = $line($view['entities']['label'], null, null, $view['entities']['cells']);
        }
        if ($view['total'] !== null) {
            $rows[] = $line('All sites in scope', null, null, $view['total']['cells']);
        }

        $title = $cols === 'dates'
            ? 'Recon pivot dates '.($view['side'] === 'Bank' ? 'bank' : 'PumpIT').($view['area'] ? ' '.self::LABELS[$view['area']] : '').' '.$view['lens']
            : 'Recon pivot areas '.$view['lens'];

        return ['title' => $title, 'columns' => $columns, 'rows' => self::objects($rows)];
    }

    /**
     * The page's notices, each `{tone, title, lines[]}` for <x-notice>, plain
     * text: statements that are late (warn), and feed history that could not
     * be read (info). A refusal is the controller's, not this.
     *
     * @return list<array{tone: string, title: ?string, lines: list<string>}>
     */
    public function notices(): array
    {
        $notices = [];
        $behind = max(0, (int) $this->settings['statements_behind_days']);
        $cut = self::shift($this->asAt, -$behind);
        $lines = [];

        foreach (self::AREAS as $area) {
            $c = $this->coverage[$area] ?? null;
            if ($c === null) {
                continue;
            }
            $label = self::LABELS[$area];
            if ($c['last'] === null) {
                $lines[] = "No {$label} statement lines are loaded: {$label} deposits from ".self::date($c['from'], 'j M').' count as in transit until they arrive.';
            } elseif ($c['last'] < $cut) {
                $days = self::daysBetween($c['last'], $this->asAt);
                $lines[] = "{$label} statements are loaded to ".self::date($c['last'], 'j M').' ('.$days.' '.($days === 1 ? 'day' : 'days').' behind): '
                    ."{$label} deposits from ".self::date($c['from'], 'j M').' count as in transit until they arrive.';
            }
        }

        if ($lines !== []) {
            $notices[] = ['tone' => 'warn', 'title' => 'Bank statements are late', 'lines' => $lines];
        }

        if (! $this->feedsKnown) {
            $notices[] = ['tone' => 'info', 'title' => null, 'lines' => ['Feed history could not be read; cells with nothing outstanding show —.']];
        }

        return $notices;
    }

    /**
     * For the info button: the transit rule with today's dates, and the tile
     * identity with today's figures, as plain sentences.
     *
     * @return array{transit: list<string>, identity: list<string>}
     */
    public function info(): array
    {
        $transit = [];
        foreach (self::AREAS as $area) {
            $c = $this->coverage[$area] ?? null;
            if ($c !== null) {
                $transit[] = self::LABELS[$area].': '.($c['last'] ? 'statements loaded to '.self::date($c['last'], 'j M') : 'no statement lines loaded yet')
                    .' · deposits dated from '.self::date($c['from'], 'j M').' are in transit ('.$c['lag'].' days)';
            }
        }

        $t = $this->sums();

        return [
            'transit' => $transit,
            'identity' => [
                'Bank '.self::r($t['B']).' = on both sides '.self::r($t['both']).' + not declared '.self::r($t['over']),
                'PumpIT '.self::r($t['P']).' = on both sides '.self::r($t['both']).' + not in bank '.self::r($t['short'])
                    .' + in transit, not yet in the bank '.self::r($t['wait']),
            ],
        ];
    }

    /**
     * Where Per site's Work link opens the recon centre for one site × area
     * (§4): `{from, to, tab, window, title}`, or null when nothing in it can be
     * worked (nothing outstanding, or Unclassified).
     *
     *   current  something 30 days old or newer, beyond transit: the last 30
     *            days, narrowed to the last 7 when either side has more than
     *            500 rows in them (manual match lists 500 a side)
     *   oldest   only older items: the oldest month, from the older side's
     *            oldest date
     *
     * `tab` is `suggest` where the area has Suggestions (FNB); otherwise the
     * centre opens on Manual match, because `from` is given.
     *
     * @param  array<string, mixed>|object|null  $bankRow  the set-1 row, bank side
     * @param  array<string, mixed>|object|null  $pumpitRow  the set-1 row, PumpIT side
     * @return array{from: string, to: string, tab: ?string, window: string, title: string}|null
     */
    public function workWindow(array|object|null $bankRow, array|object|null $pumpitRow, ?string $from = null, ?string $to = null): ?array
    {
        $area = (string) (((array) ($bankRow ?? $pumpitRow ?? []))['ReconArea'] ?? '');
        if (! in_array($area, self::AREAS, true)) {
            return null;
        }

        $bank = $bankRow === null ? null : self::outstandingRow((array) $bankRow, 'Bank');
        $pumpit = $pumpitRow === null ? null : self::outstandingRow((array) $pumpitRow, 'PumpIT');
        if ((int) ($bank['rows'] ?? 0) + (int) ($pumpit['rows'] ?? 0) <= 0) {
            return null;
        }

        $from = self::day($from);
        $end = self::day($to) ?? $this->asAt;
        $m = self::measures($bank, $pumpit, $this->settings);
        $tab = config('recon.areas.'.$area.'.suggest') ? 'suggest' : null;
        $screen = $tab === 'suggest' ? 'Suggestions' : 'Manual match';

        if ($m['B30n'] > 0 || $m['P30n'] > $m['Tn']) {
            $busy = $m['B30n'] > self::MANUAL_ROWS || $m['P30n'] > self::MANUAL_ROWS;
            $start = max($from ?? '', self::shift($this->asAt, $busy ? -6 : -30));

            return [
                'from' => $start, 'to' => $end, 'tab' => $tab, 'window' => $busy ? 'week' : 'month',
                'title' => "Opens {$screen} on the last ".($busy ? '7' : '30').' days, '.self::span($start, $end)
                    .($busy ? ': more than 500 rows a side in 30 days, and manual match lists 500.' : '.'),
            ];
        }

        $oldest = self::earliest($bank['oldest'] ?? null, $pumpit['oldest'] ?? null) ?? $end;
        $stop = min(self::shift($oldest, 30), $end);

        return [
            'from' => $oldest, 'to' => $stop, 'tab' => $tab, 'window' => 'oldest',
            'title' => "Opens {$screen} on the oldest month, ".self::span($oldest, $stop).': nothing here is current.',
        ];
    }

    /** "Top 5 hold 68% of the amount" — the pivot card's subtitle; null for five sites or fewer. */
    public function concentration(): ?string
    {
        if (count($this->rowIds) <= 5) {
            return null;
        }

        $gross = array_map(fn (int $id) => max(0, $this->agg[$id]['B'] + $this->agg[$id]['P']), $this->rowIds);
        rsort($gross);
        $total = array_sum($gross);

        return $total > 0 ? 'Top 5 hold '.Format::pct(100 * array_sum(array_slice($gross, 0, 5)) / $total, 0).' of the amount' : null;
    }

    /** The status of one site, or of one of its areas; null for a site out of scope. */
    public function statusOf(int $site, ?string $area = null): ?string
    {
        if ($area === null) {
            return $this->siteStatus[$site] ?? null;
        }

        $status = $this->facts[$site][$area]['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    public function feedsKnown(): bool
    {
        return $this->feedsKnown;
    }

    /**
     * One cell, as <x-recon::cell> writes it. `text` is the figure or glyph,
     * already formatted; `sort` its data-sort (raw, 2 dp; empty sorts last);
     * `tone` washes it in <x-tone-value>; `state` puts it in span.rp-{state};
     * `tip` is HTML built from e()-escaped parts; `aria` is plain text.
     *
     * A link's accessible name starts with what it shows when the sentence
     * does not already say it (WCAG 2.5.3, label in name): someone who says
     * "R71.10m" to voice control has to hit the link that reads R71.10m,
     * whose sentence spells the figure out as R71 095 649.38.
     *
     * @return array{text: string, sort: string, tone: ?string, state: ?string, class: string, href: ?string, tip: ?string, aria: ?string}
     */
    public static function cell(
        string $text = '',
        string $sort = '',
        ?string $tone = null,
        ?string $state = null,
        string $class = 'num',
        ?string $href = null,
        ?string $tip = null,
        ?string $aria = null,
    ): array {
        if ($href !== null && $aria !== null && $text !== '' && ! str_contains($aria, $text)) {
            $aria = $text.' — '.$aria;
        }

        return ['text' => $text, 'sort' => $sort, 'tone' => $tone, 'state' => $state, 'class' => $class,
            'href' => $href, 'tip' => $tip, 'aria' => $aria];
    }

    /**
     * One area's status from its measures and its feed facts, first match wins
     * (§1.4): Clear → In transit → Bank feed → Not in bank → Not declared →
     * To match → Backlog. An unknown feed fact (null) is never a match.
     *
     * @param  array<string, int|float>  $m  measures()
     * @param  array<string, mixed>  $f  {Bank: {never, stopped}, PumpIT: {never, stopped}}
     */
    public static function status(array $m, array $f): string
    {
        $is = fn (string $side, string $fact) => (($f[$side][$fact] ?? null) === true);
        $beyond = $m['P'] - $m['T'];

        return match (true) {
            $m['Bn'] <= 0 && $m['Pn'] <= 0 => 'Clear',
            $m['Bn'] <= 0 && $m['Tn'] >= $m['Pn'] => 'In transit',
            $is('Bank', 'never') && $beyond > 0 => 'Bank feed',
            $m['short30'] > $m['tol30'] || ($is('Bank', 'stopped') && $beyond > 0) => 'Not in bank',
            $m['over30'] > $m['tol30'] || (($is('PumpIT', 'stopped') || $is('PumpIT', 'never')) && $m['B'] > 0) => 'Not declared',
            $m['B30n'] > 0 || $m['P30n'] > $m['Tn'] => 'To match',
            default => 'Backlog',
        };
    }

    /**
     * The arithmetic of one site × area, in cents (§1.4). Rows are normalised
     * set-1 rows (outstandingRow()); either may be null.
     *
     * @param  array<string, mixed>|null  $bank
     * @param  array<string, mixed>|null  $pumpit
     * @param  array<string, mixed>  $settings
     * @return array<string, int|float>
     */
    public static function measures(?array $bank, ?array $pumpit, array $settings = []): array
    {
        $B = (int) ($bank['amount'] ?? 0);
        $P = (int) ($pumpit['amount'] ?? 0);
        $T = (int) ($pumpit['transitAmount'] ?? 0);
        // Money on both sides is never negative, and a net debit stays on its
        // own side: a bank reversal lands in over (not declared), a negative
        // deposit in short (not in bank), and wait is never below zero or above
        // the transit. Both identities hold by construction (B = both + over,
        // P = both + short + wait); with B, P and T at zero or above this is
        // §1.4's arithmetic exactly (live, 16 Nov 2026: 3 negative outstanding
        // bank lines, -R10 447.19).
        $both = max(0, min($B, $P));
        $wait = max(0, min($T, $P - $both));
        $short = $P - $both - $wait;
        $B30 = self::bucket($bank, ['Age7', 'Age30'], 'amount');
        $P30 = self::bucket($pumpit, ['Age7', 'Age30'], 'amount');

        return [
            'B' => $B,
            'Bn' => (int) ($bank['rows'] ?? 0),
            'P' => $P,
            'Pn' => (int) ($pumpit['rows'] ?? 0),
            'T' => $T,
            'Tn' => (int) ($pumpit['transitRows'] ?? 0),
            'short' => $short,
            'over' => $B - $both,
            'both' => $both,
            'wait' => $wait,
            'B30' => $B30,
            'B30n' => self::bucket($bank, ['Age7', 'Age30'], 'rows'),
            'P30' => $P30,
            'P30n' => self::bucket($pumpit, ['Age7', 'Age30'], 'rows'),
            'short30' => max(0, max(0, $P30 - $T) - max(0, $B30)),
            'over30' => max(0, max(0, $B30) - max(0, $P30)),
            'B90' => self::bucket($bank, ['Age90'], 'amount'),
            'P90' => self::bucket($pumpit, ['Age90'], 'amount'),
            'Bold' => self::bucket($bank, ['Age365', 'AgeOver'], 'amount'),
            'Boldn' => self::bucket($bank, ['Age365', 'AgeOver'], 'rows'),
            'Pold' => self::bucket($pumpit, ['Age365', 'AgeOver'], 'amount'),
            'Poldn' => self::bucket($pumpit, ['Age365', 'AgeOver'], 'rows'),
            'tol' => self::tolerance($B, $P, $settings),
            'tol30' => self::tolerance($B30, $P30, $settings),
        ];
    }

    /**
     * One set-1 row as this class reads it: amounts in cents, dates as Y-m-d,
     * transit forced to 0 on anything but PumpIT.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    public static function outstandingRow(array $r, string $side): array
    {
        $age = [];
        foreach (array_keys(ReconDashboardService::BUCKETS) as $prefix) {
            $age[$prefix] = ['rows' => (int) ($r[$prefix.'Rows'] ?? 0), 'amount' => self::cents($r[$prefix.'Amount'] ?? 0)];
        }

        return [
            'rows' => (int) ($r['Rows'] ?? 0),
            'amount' => self::cents($r['Amount'] ?? 0),
            'oldest' => self::day($r['OldestDate'] ?? null),
            'newest' => self::day($r['NewestDate'] ?? null),
            'age' => $age,
            'transitRows' => $side === 'PumpIT' ? (int) ($r['TransitRows'] ?? 0) : 0,
            'transitAmount' => $side === 'PumpIT' ? self::cents($r['TransitAmount'] ?? 0) : 0,
            'creditRows' => (int) ($r['CreditRows'] ?? 0),
            'creditAmount' => self::cents($r['CreditAmount'] ?? 0),
        ];
    }

    /* =============================================================== reading */

    /**
     * @param  list<array<string, mixed>>  $sites
     * @param  list<array<string, mixed>>  $rows
     */
    private function readSites(array $sites, array $rows): void
    {
        foreach ($sites as $s) {
            $id = (int) ($s['BranchId'] ?? 0);
            if ($id > 0) {
                $this->sites[$id] = [
                    'id' => $id,
                    'name' => (string) ($s['BranchName'] ?? $s['Name'] ?? 'Site '.$id),
                    'status' => isset($s['Status']) ? (string) $s['Status'] : null,
                    'trading' => self::flag($s['IsTrading'] ?? null),
                    'active' => self::flag($s['IsActive'] ?? null),
                    'admin' => self::flag($s['IsAdminEntity'] ?? null),
                ];
            }
        }

        // A site set 1 names that set 2 does not (an older procedure, a race):
        // still a site, its flags unknown.
        foreach ($rows as $r) {
            $id = (int) ($r['BranchId'] ?? 0);
            if ($id > 0 && ! isset($this->sites[$id])) {
                $this->sites[$id] = ['id' => $id, 'name' => (string) ($r['BranchName'] ?? 'Site '.$id),
                    'status' => null, 'trading' => null, 'active' => null, 'admin' => null];
            }
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function readOutstanding(array $rows): void
    {
        foreach ($rows as $r) {
            $id = (int) ($r['BranchId'] ?? 0);
            $area = (string) ($r['ReconArea'] ?? '');
            $side = (string) ($r['Side'] ?? '');

            if ($id <= 0 || ! in_array($side, self::SIDES, true)) {
                continue;
            }
            if ($area === 'Unclassified') {
                if ($side === 'Bank') {
                    $this->loose[$id] = self::outstandingRow($r, 'Bank');
                }

                continue;
            }
            if (in_array($area, self::AREAS, true)) {
                $this->out[$id][$area][$side] = self::outstandingRow($r, $side);
            }
        }
    }

    /** @param  list<array<string, mixed>>  $coverage */
    private function readCoverage(array $coverage): void
    {
        foreach ($coverage as $c) {
            $area = (string) ($c['ReconArea'] ?? '');
            if (! in_array($area, self::AREAS, true)) {
                continue;
            }
            $lag = (int) ($c['LagDays'] ?? self::LAGS[$area]);
            $last = self::day($c['LastLineDate'] ?? null);
            $this->coverage[$area] = [
                'lag' => $lag,
                'last' => $last,
                'from' => self::day($c['TransitFrom'] ?? null) ?? self::shift($last ?? self::shift($this->asAt, -1), 1 - $lag),
            ];
        }
    }

    /** @param  list<array<string, mixed>>  $periods */
    private function readPeriods(array $periods): void
    {
        foreach ($periods as $p) {
            $id = (int) ($p['BranchId'] ?? 0);
            $area = (string) ($p['ReconArea'] ?? '');
            $side = (string) ($p['Side'] ?? '');
            $start = self::day($p['PeriodStart'] ?? null);

            if ($id > 0 && $start !== null && in_array($area, self::AREAS, true) && in_array($side, self::SIDES, true)) {
                $this->periods[] = [
                    'site' => $id, 'area' => $area, 'side' => $side, 'start' => $start,
                    'rows' => (int) ($p['Rows'] ?? 0),
                    'amount' => self::cents($p['Amount'] ?? 0),
                    'transitRows' => $side === 'PumpIT' ? (int) ($p['TransitRows'] ?? 0) : 0,
                    'transitAmount' => $side === 'PumpIT' ? self::cents($p['TransitAmount'] ?? 0) : 0,
                ];
            }
        }
    }

    /** @param  array<string, mixed>|null  $feeds */
    private function readFeeds(?array $feeds): void
    {
        $this->feedsKnown = $feeds !== null;

        if ($feeds === null) {
            return;
        }

        foreach (self::list($feeds['sides'] ?? []) as $f) {
            $id = (int) ($f['BranchId'] ?? 0);
            $area = (string) ($f['ReconArea'] ?? '');
            $side = (string) ($f['Side'] ?? '');
            if ($id > 0 && in_array($area, self::AREAS, true) && in_array($side, self::SIDES, true)) {
                $this->feedSides[$id][$area][$side] = [
                    'first' => self::day($f['FirstDate'] ?? null),
                    'last' => self::day($f['LastDate'] ?? null),
                    'rows' => (int) ($f['Rows'] ?? 0),
                ];
                $this->covered[$id] = true;
            }
        }

        foreach (self::list($feeds['rules'] ?? []) as $r) {
            $id = (int) ($r['BranchId'] ?? 0);
            $area = (string) ($r['ReconArea'] ?? '');
            if ($id > 0 && in_array($area, self::AREAS, true)) {
                $this->rules[$id][$area] = (int) ($r['Rules'] ?? 0);
                $this->covered[$id] = true;
            }
        }
    }

    /** Facts and statuses for every site × area; which sites are rows, and their order (§1.5). */
    private function decide(): void
    {
        foreach (array_keys($this->sites) as $id) {
            $agg = [];
            $worst = 'Clear';

            foreach (self::AREAS as $area) {
                $bank = $this->out[$id][$area]['Bank'] ?? null;
                $pumpit = $this->out[$id][$area]['PumpIT'] ?? null;
                $m = self::measures($bank, $pumpit, $this->settings);
                $f = $this->feedFacts($id, $area, $bank, $pumpit);
                $status = self::status($m, $f);
                $this->facts[$id][$area] = ['bank' => $bank, 'pumpit' => $pumpit, 'm' => $m, 'f' => $f, 'status' => $status];

                foreach ($m as $key => $value) {
                    if (is_int($value)) {
                        $agg[$key] = ($agg[$key] ?? 0) + $value;
                    }
                }
                if (self::STATUSES[$status]['priority'] < self::STATUSES[$worst]['priority']) {
                    $worst = $status;
                }
            }

            $this->agg[$id] = $agg;
            $this->siteStatus[$id] = $worst;
        }

        $picked = array_flip($this->selected);
        $this->single = count($this->selected) === 1 || count($this->sites) === 1;

        foreach ($this->sites as $id => $site) {
            $classified = isset($this->out[$id]);
            $trading = $site['trading'] === true && $site['active'] === true;

            if ($classified || $trading || isset($picked[$id]) || $this->single) {
                $this->rowIds[] = $id;
            } elseif ((int) ($this->loose[$id]['rows'] ?? 0) > 0) {
                $this->entityIds[] = $id;
            }
        }

        // Status priority, then the current one-sided money, then the gross,
        // then the name.
        usort($this->rowIds, function (int $a, int $b): int {
            $x = $this->agg[$a];
            $y = $this->agg[$b];

            return [self::STATUSES[$this->siteStatus[$a]]['priority'], $y['short30'] + $y['over30'], $y['B'] + $y['P']]
                <=> [self::STATUSES[$this->siteStatus[$b]]['priority'], $x['short30'] + $x['over30'], $x['B'] + $x['P']]
                ?: strnatcasecmp($this->sites[$a]['name'], $this->sites[$b]['name'])
                ?: $a <=> $b;
        });
    }

    /**
     * What the feed history says about one site × area (§1.4): per side used,
     * never, stopped (null where unknown), plus unused and the rule count.
     *
     * @param  array<string, mixed>|null  $bank
     * @param  array<string, mixed>|null  $pumpit
     * @return array<string, mixed>
     */
    private function feedFacts(int $id, string $area, ?array $bank, ?array $pumpit): array
    {
        $known = $this->feedsKnown && isset($this->covered[$id]);
        $rows = ['Bank' => $bank, 'PumpIT' => $pumpit];
        $lag = $this->coverage[$area]['lag'] ?? self::LAGS[$area];
        $lastLine = $this->coverage[$area]['last'] ?? null;
        $expected = [
            'Bank' => $lastLine === null ? null : self::shift($lastLine, -$lag - 1),
            'PumpIT' => self::shift($this->asAt, -$lag - 1),
        ];

        $used = [];
        $last = [];
        $first = [];
        foreach (self::SIDES as $side) {
            $feed = $this->feedSides[$id][$area][$side] ?? null;
            $has = (int) ($rows[$side]['rows'] ?? 0) > 0;
            $used[$side] = $has ? true : ($known ? (int) ($feed['rows'] ?? 0) > 0 : null);
            $last[$side] = self::latest($feed['last'] ?? null, $rows[$side]['newest'] ?? null);
            $first[$side] = self::earliest($feed['first'] ?? null, $rows[$side]['oldest'] ?? null);
        }

        $facts = [];
        foreach (self::SIDES as $side) {
            $other = $side === 'Bank' ? 'PumpIT' : 'Bank';
            $never = match (true) {
                $used[$side] === true => false,
                $used[$side] === null || $used[$other] === null => null,
                default => $used[$other] === true,
            };
            $stopped = $known
                ? $used[$side] === true && $used[$other] === true
                    && $expected[$side] !== null && $expected[$other] !== null
                    && $last[$side] !== null && $last[$other] !== null
                    && $last[$side] < $expected[$side] && $last[$other] >= $expected[$other]
                : null;
            $facts[$side] = ['used' => $used[$side], 'never' => $never, 'stopped' => $stopped,
                'first' => $first[$side], 'last' => $last[$side]];
        }

        $facts['unused'] = $known
            ? $used['Bank'] === false && $used['PumpIT'] === false
            : ($used['Bank'] === true || $used['PumpIT'] === true ? false : null);
        $facts['rules'] = $known ? ($this->rules[$id][$area] ?? 0) : null;

        return $facts;
    }

    /* ================================================================ columns */

    /** @return list<array<string, mixed>> */
    private function leadColumns(): array
    {
        return [
            ['key' => 'site', 'kind' => 'site', 'label' => 'Site', 'title' => null, 'class' => '', 'noFilter' => false, 'pills' => false],
            ['key' => 'status', 'kind' => 'status', 'label' => 'Status', 'title' => 'The worst of the site\'s areas', 'class' => '',
                'noFilter' => false, 'pills' => true],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function areaColumns(string $lens): array
    {
        $columns = $this->leadColumns();
        $money = $lens === 'count' ? 'number' : 'money';
        $what = $lens === 'count' ? 'items' : 'outstanding';

        foreach (self::AREAS as $area) {
            foreach (self::SIDES as $side) {
                $columns[] = [
                    'key' => $area.'.'.$side, 'kind' => 'figure', 'label' => self::LABELS[$area].' '.self::sideWord($side),
                    'title' => self::areaLabel($area).': '.self::sideWord($side).' '.$what,
                    'class' => $side === 'Bank' ? 'num grp' : 'num', 'noFilter' => true, 'pills' => false,
                    'export' => self::LABELS[$area].' '.self::sideWord($side), 'format' => $money,
                ];
            }
        }

        $columns[] = ['key' => 'AllBank', 'kind' => 'figure', 'label' => 'All bank', 'title' => 'Bank '.$what.', every area',
            'class' => 'num grp', 'noFilter' => true, 'pills' => false, 'export' => 'All bank', 'format' => $money];
        $columns[] = ['key' => 'AllPumpIT', 'kind' => 'figure', 'label' => 'All PumpIT', 'title' => 'PumpIT '.$what.', every area',
            'class' => 'num', 'noFilter' => true, 'pills' => false, 'export' => 'All PumpIT', 'format' => $money];
        $columns[] = ['key' => 'Over90', 'kind' => 'figure', 'label' => 'Over 90 d',
            'title' => $lens === 'count' ? 'Share of the items dated more than 90 days ago' : 'Share of the amount dated more than 90 days ago',
            'class' => 'num', 'noFilter' => true, 'pills' => false, 'export' => 'Over 90 days (%)', 'format' => 'pct'];
        $columns[] = ['key' => 'Unclassified', 'kind' => 'figure', 'label' => 'Unclassified',
            'title' => 'bank lines at this site no area can match · in no total',
            'class' => 'num grp', 'noFilter' => true, 'pills' => false, 'export' => 'Unclassified bank lines (in no total)', 'format' => 'number'];

        return $columns;
    }

    /** @return list<array<string, mixed>> */
    private function differenceColumns(): array
    {
        $columns = $this->leadColumns();

        foreach (self::AREAS as $i => $area) {
            $columns[] = ['key' => $area, 'kind' => 'figure', 'label' => self::LABELS[$area],
                'title' => self::areaLabel($area).': bank less PumpIT beyond transit. + not declared, - not in bank',
                'class' => $i === 0 ? 'num grp' : 'num', 'noFilter' => true, 'pills' => false,
                'export' => self::LABELS[$area].': bank less PumpIT', 'format' => 'money'];
        }

        $block = [
            'NotInBank' => ['Not in bank', 'PumpIT money beyond transit with no bank line, netted per area'],
            'NotInBank30' => ['Not in bank, last 30 days', 'The same, dated in the last 30 days'],
            'NotDeclared' => ['Not declared', 'Bank money with no PumpIT deposit, netted per area'],
            'Both' => ['On both sides', 'Outstanding on each side: probably the same money, not yet matched'],
            'Transit' => ['In transit', 'In transit, not yet in the bank: deposits dated in the transit window that no bank line covers yet. '
                .'A figure links to its deposits when they are in one area; each area\'s window starts on its own date'],
            'Credits' => ['Unclassified credits', 'Money in on bank lines no area can match · in no total'],
        ];
        foreach ($block as $key => [$label, $title]) {
            $columns[] = ['key' => $key, 'kind' => 'figure', 'label' => $label, 'title' => $title,
                'class' => in_array($key, ['NotInBank', 'Credits'], true) ? 'num grp' : 'num', 'noFilter' => true, 'pills' => false,
                'export' => $label, 'format' => 'money'];
        }

        return $columns;
    }

    /* ================================================================== rows */

    /**
     * The site cell and the status cell of one row. A chip's and a status's
     * `detail` is its tooltip's lines as plain text, written beside it for a
     * screen reader: the tooltip box is aria-hidden, and these lines are said
     * nowhere else on the page.
     *
     * @return array<string, mixed>
     */
    private function siteRow(int $id, ?Closure $link): array
    {
        $site = $this->sites[$id];
        $status = $this->siteStatus[$id];

        $lines = [];
        foreach (self::AREAS as $area) {
            $s = $this->facts[$id][$area]['status'];
            if ($s !== 'Clear') {
                $lines[] = self::LABELS[$area].': '.$s;
            }
        }
        $lines = $lines === [] ? ['Nothing outstanding in this window.'] : $lines;

        return [
            'id' => $id,
            'name' => $site['name'],
            'href' => $this->href($link, ['view' => 'sites', 'branches' => [$id], 'cols' => null, 'lens' => null,
                'side' => null, 'area' => null, 'status' => null]),
            'sort' => $site['name'],
            'chips' => $this->chips($id),
            'status' => [
                'label' => $status,
                'tone' => self::STATUSES[$status]['tone'],
                'priority' => self::STATUSES[$status]['priority'],
                'tip' => self::tip($site['name'].' · '.$status, $lines),
                'detail' => implode('; ', $lines),
            ],
        ];
    }

    /** @return list<array{label: string, tone: string, tip: string, detail: string}> */
    private function chips(int $id): array
    {
        $site = $this->sites[$id];
        $chips = [];
        $chip = fn (string $label, array $lines): array => ['label' => $label, 'tone' => 'neutral',
            'tip' => self::tip($site['name'], $lines), 'detail' => implode(' ', array_filter($lines))];

        if ($site['trading'] === false) {
            $b30 = $this->agg[$id]['B30'];
            $chips[] = $chip('not trading', [
                'Branch status: '.($site['status'] ?? 'not trading').'. Pivot status: '.$this->siteStatus[$id].'.',
                $b30 > 0 ? 'The bank received '.self::r($b30).' in the last 30 days: whose money is this?' : null,
            ]);
        }

        $manual = $this->manualAreas($id);
        if ($manual !== []) {
            $which = count($manual) === count(self::AREAS)
                ? 'No extraction rule in any area'
                : 'No extraction rule for '.self::listing(array_map(fn (string $a) => self::LABELS[$a], $manual));
            $chips[] = $chip('manual only', [$which.': automatic recon refuses (NO_CRITERIA); match by hand, 500 rows a side.']);
        }

        if ($site['admin'] === true) {
            $chips[] = $chip('admin entity', ['An administrative entity, not a trading site.']);
        }

        return $chips;
    }

    /**
     * The areas a site can only match by hand: no extraction rule (Rules = 0),
     * among the areas it has ever used — a channel the site never touches is
     * not news. A site that has used none (Bullion: Unclassified lines only)
     * names every area without a rule. Empty when the history is unknown.
     *
     * @return list<string>
     */
    private function manualAreas(int $id): array
    {
        $none = array_values(array_filter(self::AREAS, fn (string $area) => ($this->facts[$id][$area]['f']['rules'] ?? null) === 0));
        $used = array_values(array_filter($none, fn (string $area) => $this->facts[$id][$area]['f']['unused'] === false));

        return $used !== [] ? $used : $none;
    }

    /* ======================================================= areas: cells */

    /** @return array<string, array<string, mixed>> */
    private function areaCells(int $id, string $lens, ?Closure $link): array
    {
        $cells = [];
        foreach (self::AREAS as $area) {
            foreach (self::SIDES as $side) {
                $cells[$area.'.'.$side] = $this->sideCell($id, $area, $side, $lens, $link);
            }
        }

        $a = $this->agg[$id];
        $name = $this->sites[$id]['name'];
        $count = $lens === 'count';

        foreach (self::SIDES as $side) {
            $n = $side === 'Bank' ? $a['Bn'] : $a['Pn'];
            $amount = $side === 'Bank' ? $a['B'] : $a['P'];
            $cells['All'.$side] = self::cell(
                text: $count ? Format::n($n) : self::rk($amount),
                sort: $count ? (string) $n : self::money($amount),
                class: $side === 'Bank' ? 'num grp' : 'num',
                href: $n > 0 ? $this->href($link, $this->items(['branches' => [$id], 'side' => $side])) : null,
                tip: self::tip($name.' · every area · '.self::sideWord($side), [self::r($amount).' in '.self::rows($n, $side)]),
                aria: $name.', every area, '.self::sideWord($side).': '.self::r($amount).' in '.self::rows($n, $side).'.',
            );
        }

        $cells['Over90'] = $this->over90Cell($name, $a, $count, [$id], $link);
        $cells['Unclassified'] = $this->looseCell($name, [$id], $link, false, [$id]);

        return $cells;
    }

    /** @return array<string, mixed> */
    private function sideCell(int $id, string $area, string $side, string $lens, ?Closure $link): array
    {
        $fact = $this->facts[$id][$area];
        $row = $side === 'Bank' ? $fact['bank'] : $fact['pumpit'];
        $m = $fact['m'];
        $f = $fact['f'];
        $class = $side === 'Bank' ? 'num grp' : 'num';
        $name = $this->sites[$id]['name'];
        $head = $name.' · '.self::LABELS[$area].' · '.self::sideWord($side);

        if ($row === null || (int) $row['rows'] <= 0) {
            $glyph = self::glyph($f, $side);

            // `·` says exactly what the key says, so it carries no tooltip to
            // repeat it and is no tab stop: on a default page that was 182
            // focusable spans and a quarter of the page's bytes.
            if ($glyph === 'unused') {
                return self::cell(text: self::GLYPHS[$glyph], state: $glyph, class: $class, aria: self::UNUSED);
            }

            $lines = $this->glyphLines($id, $area, $side, $glyph);

            return self::cell(
                text: self::GLYPHS[$glyph],
                sort: $glyph === 'clear' ? '0' : '',
                state: $glyph,
                class: $class,
                tip: self::tip($head, $lines),
                aria: $head.': '.implode(' ', array_filter($lines)),
            );
        }

        $count = $lens === 'count';
        $transitOnly = $side === 'PumpIT' && $row['transitRows'] >= $row['rows'];
        $tone = null;
        if (! $count && ! $transitOnly) {
            $tone = match ($side) {
                'Bank' => $m['over'] > $m['tol'] ? 'warn' : null,
                default => $m['short'] > $m['tol'] ? 'crit' : null,
            };
        }

        // The wash is never the only sign (house rule: never colour alone):
        // the sentence names the gap it stands for, and a stopped side.
        $gap = match ($tone) {
            'crit' => ' Not in bank '.self::r((int) $m['short']).'.',
            'warn' => ' Not declared '.self::r((int) $m['over']).'.',
            default => '',
        };
        $aria = $name.', '.self::LABELS[$area].', '.self::sideWord($side).': '.self::r($row['amount']).' in '.self::rows($row['rows'], $side)
            .($row['transitRows'] > 0 ? ', '.self::r($row['transitAmount']).' in transit' : '')
            .($row['oldest'] ? ', oldest '.self::date($row['oldest'], 'j M Y') : '').'.'
            .$gap.($f[$side]['stopped'] === true ? ' '.$this->feedLine($f).'.' : '');

        return self::cell(
            text: ($transitOnly ? '~' : '').($count ? Format::n($row['rows']) : self::rk($row['amount'])),
            sort: $count ? (string) $row['rows'] : self::money($row['amount']),
            tone: $tone,
            state: $transitOnly ? 'transit' : null,
            class: $class.($f[$side]['stopped'] === true ? ' rp-flag' : ''),
            href: $this->href($link, $this->items(['branches' => [$id], 'area' => $area, 'side' => $side])),
            tip: self::tip($head, $this->sideLines($id, $area, $side)),
            aria: $aria,
        );
    }

    /**
     * A side with rows, in at most five lines under the heading: the figure,
     * its ages (unless a feed line needs the room), transit, the other side,
     * the gap.
     *
     * @return list<string>
     */
    private function sideLines(int $id, string $area, string $side): array
    {
        $fact = $this->facts[$id][$area];
        $m = $fact['m'];
        $f = $fact['f'];
        $row = $side === 'Bank' ? $fact['bank'] : $fact['pumpit'];
        $c = $this->coverage[$area] ?? null;
        $mine = $side === 'Bank' ? 'B' : 'P';

        $figure = self::r($row['amount']).' in '.self::rows($row['rows'], $side).($row['oldest'] ? ' · oldest '.self::date($row['oldest'], 'j M Y') : '');
        $ages = '0–30 d '.self::rk((int) $m[$mine.'30']).' · 31–90 d '.self::rk((int) $m[$mine.'90']).' · over 90 d '.self::rk((int) $m[$mine.'old']);
        $feed = $this->feedLine($f);

        if ($side === 'PumpIT') {
            $transit = $row['transitRows'] > 0
                ? 'In transit '.self::r($row['transitAmount']).($c ? ' dated from '.self::date($c['from'], 'j M')
                    .' (statements to '.($c['last'] ? self::date($c['last'], 'j M') : 'none loaded').')' : '')
                : null;
            $other = match (true) {
                $m['Bn'] > 0 => 'Bank side '.self::r((int) $m['B']).' in '.self::rows((int) $m['Bn'], 'Bank'),
                $f['Bank']['never'] === true => 'No bank line has ever been classified here for '.self::LABELS[$area],
                $f['Bank']['used'] === null => 'Bank side: nothing outstanding (feed history unknown)',
                default => 'Bank side: nothing outstanding',
            };
            $gap = $m['short'] > 0 ? 'Not in bank '.self::r((int) $m['short']).' (PumpIT beyond transit less bank)' : null;
            $lines = [$figure, $ages, $transit, $other, $gap, $feed];
        } else {
            $other = match (true) {
                $m['Pn'] > 0 => 'PumpIT side '.self::r((int) $m['P']).' in '.self::rows((int) $m['Pn'], 'PumpIT')
                    .($m['T'] !== 0 ? ', '.self::r((int) $m['T']).' in transit' : ''),
                $f['PumpIT']['never'] === true => 'No deposit has ever been declared here for '.self::LABELS[$area],
                $f['PumpIT']['used'] === null => 'PumpIT side: nothing outstanding (feed history unknown)',
                default => 'PumpIT side: nothing outstanding',
            };
            $gap = $m['over'] > 0 ? 'Not declared '.self::r((int) $m['over']).' (bank less all PumpIT)' : null;
            $lines = [$figure, $ages, $other, $gap, $feed];
        }

        $lines = array_values(array_filter($lines, fn (?string $line) => $line !== null));

        return count($lines) > 5 ? array_values(array_filter($lines, fn (string $line) => $line !== $ages)) : $lines;
    }

    /** @return list<string|null> */
    private function glyphLines(int $id, string $area, string $side, string $glyph): array
    {
        $fact = $this->facts[$id][$area];
        $f = $fact['f'];
        $m = $fact['m'];
        $name = $this->sites[$id]['name'];
        $label = self::LABELS[$area];
        $last = $f[$side]['last'] ?? null;
        $loose = (int) ($this->loose[$id]['rows'] ?? 0);

        return match ($glyph) {
            'clear' => ['Nothing outstanding in this window.', $last ? 'Last '.self::noun($side).' dated '.self::date($last, 'j M Y').'.' : null],
            'stopped' => ['Nothing outstanding on this side.', $this->feedLine($f)],
            'never' => $side === 'Bank'
                ? ['No bank line has ever been classified to '.$name.' for '.$label.'; '
                    .($m['Pn'] > 0 ? self::r((int) $m['P']).' of deposits are waiting.' : 'no deposit is outstanding either.'),
                    $loose > 0 && $m['Pn'] > 0 ? $name.' has '.Format::n($loose).' unclassified bank '.($loose === 1 ? 'line' : 'lines').'; they may be these.' : null]
                : ['No deposit has ever been declared at '.$name.' for '.$label.'; '
                    .($m['Bn'] > 0 ? self::r((int) $m['B']).' of bank lines are waiting.' : 'no bank line is outstanding either.')],
            default => ['Nothing outstanding on this side in this window.',
                'The feed history could not be read, so this cannot say whether it is clear or was never used.'],
        };
    }

    /**
     * "PumpIT: nothing since 10 Nov 2026 while bank lines continue", for a stopped side.
     *
     * @param  array<string, mixed>  $f
     */
    private function feedLine(array $f): ?string
    {
        foreach (self::SIDES as $side) {
            if ($f[$side]['stopped'] === true) {
                return $side.': nothing since '.self::date($f[$side]['last'], 'j M Y').' while '
                    .($side === 'Bank' ? 'deposits' : 'bank lines').' continue';
            }
        }

        return null;
    }

    /**
     * @param  array<string, int>  $a  a site's (or the scope's) summed measures
     * @param  list<int>|null  $branches  the link's sites; null is every granted site
     * @return array<string, mixed>
     */
    private function over90Cell(string $name, array $a, bool $count, ?array $branches, ?Closure $link): array
    {
        $old = $count ? $a['Boldn'] + $a['Poldn'] : $a['Bold'] + $a['Pold'];
        $all = $count ? $a['Bn'] + $a['Pn'] : $a['B'] + $a['P'];

        // Nothing outstanding: a dash, named, and no tooltip or tab stop to say so twice.
        if ($a['Bn'] + $a['Pn'] <= 0 || $all === 0) {
            return self::cell(text: Format::NOTHING, sort: '', aria: 'nothing outstanding');
        }

        $pct = 100 * $old / $all;
        $cut = min($this->end, self::shift($this->asAt, -91));
        $what = $count ? Format::n($old).' of '.Format::n($all).' items' : self::r($old).' of '.self::r($all);

        return self::cell(
            text: Format::pct($pct, 0),
            sort: number_format($pct, 2, '.', ''),
            href: $old !== 0 ? $this->href($link, $this->items(['branches' => $branches, 'to' => $cut])) : null,
            tip: self::tip($name.' · over 90 days', [$what.' dated on or before '.self::date($cut, 'j M Y').'.']),
            aria: $name.', over 90 days: '.Format::pct($pct, 0).', '.$what.'.',
        );
    }

    /**
     * The Unclassified lines of some sites, as lines (Amount and Count) or as
     * credits (Difference). Never in any other total.
     *
     * @param  list<int>  $ids  the sites whose lines are added up
     * @param  list<int>|null  $branches  the link's sites; null is every granted site
     * @return array<string, mixed>
     */
    private function looseCell(string $name, array $ids, ?Closure $link, bool $credits, ?array $branches): array
    {
        $rows = 0;
        $amount = 0;
        $in = 0;
        $inRows = 0;
        foreach ($ids as $id) {
            $rows += (int) ($this->loose[$id]['rows'] ?? 0);
            $amount += (int) ($this->loose[$id]['amount'] ?? 0);
            $in += (int) ($this->loose[$id]['creditAmount'] ?? 0);
            $inRows += (int) ($this->loose[$id]['creditRows'] ?? 0);
        }

        $class = 'num grp';
        $head = $name.' · unclassified bank lines';
        if ($rows <= 0) {
            return self::cell(text: Format::NOTHING, sort: '', class: $class, aria: 'no unclassified lines');
        }

        $lines = [
            self::rows($rows, 'Bank').' · in '.self::r($in).' ('.Format::n($inRows).' '.($inRows === 1 ? 'credit' : 'credits').') · out '.self::r($amount - $in),
            'No area can match these; they are in no total.',
        ];

        return self::cell(
            text: $credits ? self::rk($in) : Format::n($rows),
            sort: $credits ? self::money($in) : (string) $rows,
            class: $class,
            href: $this->href($link, $this->items(['branches' => $branches, 'area' => 'Unclassified'])),
            tip: self::tip($head, $lines),
            aria: $name.', unclassified bank lines: '.implode(' ', $lines),
        );
    }

    /* ================================================== difference: cells */

    /** @return array<string, array<string, mixed>> */
    private function differenceCells(int $id, ?Closure $link): array
    {
        $cells = [];
        foreach (self::AREAS as $i => $area) {
            $cells[$area] = $this->differenceCell($id, $area, $i === 0 ? 'num grp' : 'num', $link);
        }

        return $cells + $this->blockCells($this->sites[$id]['name'], $this->agg[$id], [$id], [$id], [$id], $link);
    }

    /** @return array<string, mixed> */
    private function differenceCell(int $id, string $area, string $class, ?Closure $link): array
    {
        $fact = $this->facts[$id][$area];
        $m = $fact['m'];
        $f = $fact['f'];
        $name = $this->sites[$id]['name'];
        $head = $name.' · '.self::LABELS[$area].' · bank less PumpIT';

        if ($m['Bn'] <= 0 && $m['Pn'] <= 0) {
            $glyph = self::pairGlyph($f);
            if ($glyph === 'unused') {
                return self::cell(text: self::GLYPHS[$glyph], state: $glyph, class: $class, aria: self::UNUSED);
            }
            $lines = match ($glyph) {
                'unknown' => ['Nothing outstanding in this window; the feed history could not be read.'],
                'clear' => ['Nothing outstanding on either side in this window.'],
                default => ['Nothing outstanding in this window.', $this->feedLine($f)
                    ?? ($f['Bank']['never'] === true ? 'No bank line has ever been classified here.' : 'No deposit has ever been declared here.')],
            };

            return self::cell(text: self::GLYPHS[$glyph], sort: $glyph === 'clear' ? '0' : '', state: $glyph, class: $class,
                tip: self::tip($head, $lines), aria: $head.': '.implode(' ', $lines));
        }

        $diff = (int) ($m['over'] - $m['short']);
        $transitOnly = $m['Bn'] <= 0 && $m['Tn'] >= $m['Pn'];
        $c = $this->coverage[$area] ?? null;

        // `wait`, not T: the part of the transit money no bank line covers yet,
        // named as such, because the PumpIT cell's tooltip calls all of T "In
        // transit" and the two differ whenever bank lines arrived early.
        $lines = [
            'Bank '.self::r((int) $m['B']).' ('.self::rows((int) $m['Bn'], 'Bank').') · PumpIT '.self::r((int) $m['P']).' ('.Format::n($m['Pn']).')',
            $m['wait'] !== 0 ? 'In transit, not yet in the bank '.self::r((int) $m['wait'])
                .($m['T'] !== $m['wait'] ? ' of '.self::r((int) $m['T']) : '')
                .($c ? ' dated from '.self::date($c['from'], 'j M') : '') : null,
            match (true) {
                $m['short'] > 0 && $m['over'] > 0 => 'Not in bank '.self::r((int) $m['short']).' · not declared '.self::r((int) $m['over']),
                $m['short'] > 0 => 'Not in bank '.self::r((int) $m['short']),
                $m['over'] > 0 => 'Not declared '.self::r((int) $m['over']),
                default => 'Bank and PumpIT agree beyond transit',
            },
            $this->feedLine($f),
            'On both sides '.self::r((int) $m['both']),
            'Last 30 days: not in bank '.self::r((int) $m['short30']).' · not declared '.self::r((int) $m['over30']),
        ];
        $lines = array_slice(array_values(array_filter($lines, fn (?string $line) => $line !== null)), 0, 5);

        $text = $transitOnly ? '~'.self::rk((int) $m['P']) : ($diff > 0 ? '+'.self::rk($diff) : self::rk($diff));
        $tone = $transitOnly ? null : ($m['short'] > $m['tol'] ? 'crit' : ($m['over'] > $m['tol'] ? 'warn' : null));
        $flag = ($m['Bn'] > 0 && $f['Bank']['stopped'] === true) || ($m['Pn'] > 0 && $f['PumpIT']['stopped'] === true);

        return self::cell(
            text: $text,
            sort: self::money($transitOnly ? 0 : $diff),
            tone: $tone,
            state: $transitOnly ? 'transit' : null,
            class: $class.($flag ? ' rp-flag' : ''),
            href: $this->href($link, $this->items(['branches' => [$id], 'area' => $area])),
            tip: self::tip($head, $lines),
            aria: $head.': '.$text.'. '.implode('. ', $lines).'.',
        );
    }

    /**
     * The right-hand block: exact sums, linked to the items behind them.
     *
     * @param  array<string, int>  $a  the summed measures
     * @param  list<int>  $ids  the sites whose measures $a adds up
     * @param  list<int>  $looseIds  the sites whose Unclassified credits are added up
     * @param  list<int>|null  $branches  the links' sites; null is every granted site
     * @return array<string, array<string, mixed>>
     */
    private function blockCells(string $name, array $a, array $ids, array $looseIds, ?array $branches, ?Closure $link): array
    {
        $sum = function (string $key, string $label, int $cents, ?string $tone, array $query, string $what) use ($name, $branches, $link): array {
            return self::cell(
                text: self::rk($cents),
                sort: self::money($cents),
                tone: $tone !== null && $cents >= 10000 ? $tone : null,
                class: in_array($key, ['NotInBank'], true) ? 'num grp' : 'num',
                href: $cents !== 0 ? $this->href($link, $this->items(['branches' => $branches] + $query)) : null,
                tip: self::tip($name.' · '.mb_strtolower($label), [self::r($cents).': '.$what]),
                aria: $name.', '.mb_strtolower($label).': '.self::r($cents).'.',
            );
        };

        $from30 = max($this->from ?? '', $this->recentFrom);

        return [
            'NotInBank' => $sum('NotInBank', 'Not in bank', $a['short'], 'crit', [],
                'PumpIT beyond transit less bank, netted per area. The link opens every item outstanding.'),
            'NotInBank30' => $sum('NotInBank30', 'Not in bank, last 30 days', $a['short30'], null, ['from' => $from30],
                'the same, dated from '.self::date($from30, 'j M').'. The link opens every item since then.'),
            'NotDeclared' => $sum('NotDeclared', 'Not declared', $a['over'], 'warn', [],
                'bank less all PumpIT, netted per area. The link opens every item outstanding.'),
            'Both' => $sum('Both', 'On both sides', $a['both'], null, [],
                'outstanding on each side, probably the same money, not yet matched.'),
            'Transit' => $this->transitCell($name, $a['wait'], $ids, $branches, $link),
            'Credits' => $this->looseCell($name, $looseIds, $link, true, $branches),
        ];
    }

    /**
     * In transit, not yet in the bank: Σwait over these sites. Its link opens
     * the deposits in the transit window — R T, of which R wait has no bank
     * line yet — and it can do that for ONE area only, because each area's
     * window starts on its own date. One `from` across areas opened every
     * deposit since the earliest of them (16 Nov 2026: 10 374 deposits,
     * R133.5m, behind a R4.00m figure). So it links when one area holds all
     * of the figure; when several do, it has no link, and the tooltip gives
     * every area with deposits in transit either way.
     *
     * @param  list<int>  $ids  the sites whose measures are added up
     * @param  list<int>|null  $branches  the link's sites; null is every granted site
     * @return array<string, mixed>
     */
    private function transitCell(string $name, int $wait, array $ids, ?array $branches, ?Closure $link): array
    {
        $areas = [];
        foreach (self::AREAS as $area) {
            $sum = ['T' => 0, 'Tn' => 0, 'wait' => 0];
            foreach ($ids as $id) {
                $m = $this->facts[$id][$area]['m'] ?? null;
                if ($m !== null) {
                    $sum['T'] += (int) $m['T'];
                    $sum['Tn'] += (int) $m['Tn'];
                    $sum['wait'] += (int) $m['wait'];
                }
            }
            if ($sum['Tn'] > 0 && isset($this->coverage[$area])) {
                $areas[$area] = $sum + ['from' => max($this->from ?? '', $this->coverage[$area]['from'])];
            }
        }

        $lines = [];
        foreach ($areas as $area => $x) {
            $lines[] = self::LABELS[$area].': '.self::r($x['wait']).' not yet in the bank, of '.self::r($x['T'])
                .' in transit from '.self::date($x['from'], 'j M');
        }

        $href = null;
        $holding = array_filter($areas, fn (array $x) => $x['wait'] !== 0);
        if (count($holding) === 1) {
            $area = (string) array_key_first($holding);
            $x = $holding[$area];
            $lines[] = 'The link opens the '.Format::n($x['Tn']).' '.self::LABELS[$area].' '.($x['Tn'] === 1 ? 'deposit' : 'deposits')
                .' in transit, '.self::r($x['T']).'.';
            $href = $this->href($link, $this->items(['branches' => $branches, 'side' => 'PumpIT', 'area' => $area, 'from' => $x['from']]));
        }

        $head = $name.' · in transit, not yet in the bank';

        return self::cell(
            text: self::rk($wait),
            sort: self::money($wait),
            href: $href,
            tip: self::tip($head, $lines === [] ? ['Nothing in transit in this window.'] : $lines),
            aria: $name.', in transit, not yet in the bank: '.self::r($wait).'.'.($lines === [] ? '' : ' '.implode('. ', $lines).'.'),
        );
    }

    /* ================================================================ tfoot */

    /**
     * Row A: the entities with only Unclassified lines, all in one row.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, mixed>|null
     */
    private function entityRow(array $columns, string $lens, ?Closure $link): ?array
    {
        if ($this->entityIds === []) {
            return null;
        }

        $n = count($this->entityIds);
        $label = $n.' other '.($n === 1 ? 'entity' : 'entities').' · unclassified lines only';
        $names = array_map(fn (int $id) => $this->sites[$id]['name'], $this->entityIds);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        $cells = [];
        foreach ($columns as $c) {
            if ($c['kind'] === 'figure') {
                $cells[$c['key']] = self::cell(class: str_contains($c['class'], 'grp') ? 'num grp' : 'num');
            }
        }

        $key = $lens === 'difference' ? 'Credits' : 'Unclassified';
        $cells[$key] = $this->looseCell($label, $this->entityIds, $link, $lens === 'difference', $this->entityIds);

        return ['label' => $label, 'tip' => self::tip($label, [self::listing($names).'.']), 'detail' => self::listing($names),
            'ids' => $this->entityIds, 'cells' => $cells];
    }

    /** @return array<string, mixed> Row B for Amount and Count */
    private function areaTotal(string $lens, ?Closure $link): array
    {
        $count = $lens === 'count';
        $scope = $this->selected === [] ? null : $this->selected;
        $label = 'All sites in scope ('.count($this->rowIds).' '.(count($this->rowIds) === 1 ? 'site' : 'sites').')';
        $sites = count($this->rowIds);
        $cells = [];

        foreach (self::AREAS as $area) {
            foreach (self::SIDES as $side) {
                $n = 0;
                $amount = 0;
                $k = 0;
                foreach ($this->rowIds as $id) {
                    $row = $this->facts[$id][$area][$side === 'Bank' ? 'bank' : 'pumpit'];
                    $n += (int) ($row['rows'] ?? 0);
                    $amount += (int) ($row['amount'] ?? 0);
                    $k += (int) ($row['rows'] ?? 0) > 0 ? 1 : 0;
                }
                $head = 'All sites in scope · '.self::LABELS[$area].' · '.self::sideWord($side);
                $cells[$area.'.'.$side] = self::cell(
                    text: $count ? Format::n($n) : self::rk($amount),
                    sort: $count ? (string) $n : self::money($amount),
                    class: $side === 'Bank' ? 'num grp' : 'num',
                    href: $n > 0 ? $this->href($link, $this->items(['branches' => $scope, 'area' => $area, 'side' => $side])) : null,
                    tip: self::tip($head, [self::r($amount).' in '.self::rows($n, $side), Format::n($k).' of '.Format::n($sites).' sites']),
                    aria: $head.': '.self::r($amount).' in '.self::rows($n, $side).'.',
                );
            }
        }

        $a = $this->sumOf($this->rowIds);
        foreach (self::SIDES as $side) {
            $n = $side === 'Bank' ? $a['Bn'] : $a['Pn'];
            $amount = $side === 'Bank' ? $a['B'] : $a['P'];
            $head = 'All sites in scope · every area · '.self::sideWord($side);
            $cells['All'.$side] = self::cell(
                text: $count ? Format::n($n) : self::rk($amount),
                sort: $count ? (string) $n : self::money($amount),
                class: $side === 'Bank' ? 'num grp' : 'num',
                href: $n > 0 ? $this->href($link, $this->items(['branches' => $scope, 'side' => $side])) : null,
                tip: self::tip($head, [self::r($amount).' in '.self::rows($n, $side)]),
                aria: $head.': '.self::r($amount).' in '.self::rows($n, $side).'.',
            );
        }

        $cells['Over90'] = $this->over90Cell('All sites in scope', $a, $count, $scope, $link);
        $cells['Unclassified'] = $this->looseCell('All sites in scope', array_merge($this->rowIds, $this->entityIds), $link, false, $scope);

        return ['label' => $label, 'cells' => $cells];
    }

    /** @return array<string, mixed> Row B for Difference */
    private function differenceTotal(?Closure $link): array
    {
        $scope = $this->selected === [] ? null : $this->selected;
        $label = 'All sites in scope ('.count($this->rowIds).' '.(count($this->rowIds) === 1 ? 'site' : 'sites').')';
        $cells = [];

        foreach (self::AREAS as $i => $area) {
            $diff = 0;
            $n = 0;
            foreach ($this->rowIds as $id) {
                $m = $this->facts[$id][$area]['m'];
                $diff += (int) ($m['over'] - $m['short']);
                $n += (int) $m['Bn'] + (int) $m['Pn'];
            }
            $head = 'All sites in scope · '.self::LABELS[$area].' · bank less PumpIT';
            $text = $diff > 0 ? '+'.self::rk($diff) : self::rk($diff);
            $cells[$area] = self::cell(
                text: $text,
                sort: self::money($diff),
                class: $i === 0 ? 'num grp' : 'num',
                href: $n > 0 ? $this->href($link, $this->items(['branches' => $scope, 'area' => $area])) : null,
                tip: self::tip($head, [self::r($diff).': not declared less not in bank, added up over the sites.']),
                aria: $head.': '.$text.'.',
            );
        }

        $cells += $this->blockCells('All sites in scope', $this->sumOf($this->rowIds), $this->rowIds,
            array_merge($this->rowIds, $this->entityIds), $scope, $link);

        return ['label' => $label, 'cells' => $cells];
    }

    /* ================================================================ dates */

    /**
     * The period columns for the window (§1.9), each `{key, label, title, long,
     * p1, p2, iso}`: p1..p2 the dates it covers, clipped to the window.
     *
     * @param  array<int, list<array<string, mixed>>>  $mine
     * @return array{scheme: string, firstMonth: ?string, columns: list<array<string, mixed>>}
     */
    private function periodLayout(array $mine): array
    {
        $from = $this->from;
        $end = $this->end;
        $scheme = 'month';

        if ($from !== null) {
            $span = self::daysBetween($from, $end) + 1;
            $scheme = $span <= 31 ? 'day' : ($span <= 92 ? 'week' : 'month');
        }
        // Months cannot be split into days or weeks.
        if ($scheme !== 'month' && $this->grain !== 'day') {
            $scheme = 'month';
        }

        $columns = [];
        $firstMonth = null;

        if ($scheme === 'day' && $from !== null) {
            for ($d = CarbonImmutable::parse($from); $d->format('Y-m-d') <= $end; $d = $d->addDay()) {
                $day = $d->format('Y-m-d');
                $columns[] = ['key' => 'd:'.$day, 'label' => $d->format('D j'), 'title' => $d->format('l j F Y'),
                    'long' => $d->format('l j F Y'), 'p1' => $day, 'p2' => $day, 'iso' => $day];
            }
        } elseif ($scheme === 'week' && $from !== null) {
            for ($w = CarbonImmutable::parse($from)->startOfWeek(CarbonImmutable::MONDAY); $w->format('Y-m-d') <= $end; $w = $w->addWeek()) {
                $p1 = max($w->format('Y-m-d'), $from);
                $p2 = min($w->addDays(6)->format('Y-m-d'), $end);
                $columns[] = ['key' => 'w:'.$w->format('Y-m-d'), 'label' => 'w/c '.$w->format('j M'), 'title' => self::span($p1, $p2),
                    'long' => 'week of '.self::span($p1, $p2), 'p1' => $p1, 'p2' => $p2, 'iso' => $w->format('Y-m-d')];
            }
        } else {
            $last = CarbonImmutable::parse($end)->startOfMonth();
            $first = $last->subMonthsNoOverflow(11);
            if ($from !== null && CarbonImmutable::parse($from)->startOfMonth() > $first) {
                $first = CarbonImmutable::parse($from)->startOfMonth();
            }
            $firstMonth = $first->format('Y-m-d');

            // Earlier months fold into one column per calendar year, only for
            // years that hold something.
            $years = [];
            foreach ($mine as $periods) {
                foreach ($periods as $p) {
                    if ($p['start'] < $firstMonth && $p['rows'] !== 0) {
                        $years[(int) substr($p['start'], 0, 4)] = true;
                    }
                }
            }
            ksort($years);
            foreach (array_keys($years) as $year) {
                $p1 = max(sprintf('%04d-01-01', $year), $from ?? '');
                $p2 = min(sprintf('%04d-12-31', $year), $first->subDay()->format('Y-m-d'));
                $label = self::yearLabel($p1, $p2);
                $columns[] = ['key' => 'y:'.$year, 'label' => $label, 'title' => null, 'long' => $label,
                    'p1' => $p1, 'p2' => $p2, 'iso' => (string) $year];
            }

            for ($m = $first; $m <= $last; $m = $m->addMonthNoOverflow()) {
                $p1 = max($m->format('Y-m-d'), $from ?? '');
                $p2 = min($m->endOfMonth()->format('Y-m-d'), $end);
                $columns[] = ['key' => 'm:'.$m->format('Y-m'), 'label' => $m->format('M y'), 'title' => null,
                    'long' => $m->format('F Y'), 'p1' => $p1, 'p2' => $p2, 'iso' => $m->format('Y-m')];
            }
        }

        return ['scheme' => $scheme, 'firstMonth' => $firstMonth, 'columns' => $columns];
    }

    /**
     * One site's periods added into the layout's columns; `*` is every period.
     *
     * `recentRows` / `recentTransitRows` count only the periods of set 4 that
     * START in the last 30 days, so a column is marked recent from the dates
     * its rows carry, not from the column reaching into the 30 days: a week
     * or month that only overlaps them held 34-day-old deposits under "Dated
     * in the last 30 days". Exact for days and weeks (set 4 is days up to 92
     * days); a month counts only when it starts inside the 30 days.
     *
     * @param  list<array<string, mixed>>  $periods
     * @param  array{scheme: string, firstMonth: ?string, columns: list<array<string, mixed>>}  $layout
     * @return array<string, array{rows: int, amount: int, transitRows: int, transitAmount: int, recentRows: int, recentTransitRows: int}>
     */
    private function bucketPeriods(array $periods, array $layout): array
    {
        $none = ['rows' => 0, 'amount' => 0, 'transitRows' => 0, 'transitAmount' => 0, 'recentRows' => 0, 'recentTransitRows' => 0];
        $agg = ['*' => $none];

        foreach ($periods as $p) {
            $start = (string) $p['start'];
            $key = match ($layout['scheme']) {
                'day' => 'd:'.$start,
                'week' => 'w:'.CarbonImmutable::parse($start)->startOfWeek(CarbonImmutable::MONDAY)->format('Y-m-d'),
                default => $layout['firstMonth'] !== null && $start < $layout['firstMonth'] ? 'y:'.substr($start, 0, 4) : 'm:'.substr($start, 0, 7),
            };
            $recent = $start >= $this->recentFrom;

            foreach ([$key, '*'] as $k) {
                $agg[$k] ??= $none;
                $agg[$k]['rows'] += (int) $p['rows'];
                $agg[$k]['amount'] += (int) $p['amount'];
                $agg[$k]['transitRows'] += (int) $p['transitRows'];
                $agg[$k]['transitAmount'] += (int) $p['transitAmount'];
                if ($recent) {
                    $agg[$k]['recentRows'] += (int) $p['rows'];
                    $agg[$k]['recentTransitRows'] += (int) $p['transitRows'];
                }
            }
        }

        return $agg;
    }

    /**
     * @param  array<string, array{rows: int, amount: int, transitRows: int, transitAmount: int, recentRows: int, recentTransitRows: int}>  $agg
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, array<string, mixed>>
     */
    private function dateCells(int $id, array $agg, array $columns, string $lens, string $side, ?string $area, ?Closure $link): array
    {
        $name = $this->sites[$id]['name'];
        $count = $lens === 'count';
        $what = self::sideWord($side).($area ? ' · '.self::LABELS[$area] : '');
        $cells = [];

        foreach ($columns as $c) {
            $a = $agg[$c['key']] ?? null;
            if ($a === null || $a['rows'] === 0) {
                $cells[$c['key']] = self::cell();

                continue;
            }

            $transitOnly = $side === 'PumpIT' && $a['transitRows'] >= $a['rows'];
            $recent = ! $transitOnly && $a['recentRows'] > $a['recentTransitRows'];
            $head = $name.' · '.$c['long'].' · '.$what;
            $lines = [
                self::r($a['amount']).' in '.self::rows($a['rows'], $side),
                $a['transitRows'] > 0 ? 'In transit '.self::r($a['transitAmount']).' ('.Format::n($a['transitRows']).')' : null,
                $recent ? 'Dated in the last 30 days, beyond transit' : null,
            ];

            $cells[$c['key']] = self::cell(
                text: ($transitOnly ? '~' : '').($count ? Format::n($a['rows']) : self::rk($a['amount'])),
                sort: $count ? (string) $a['rows'] : self::money($a['amount']),
                state: $transitOnly ? 'transit' : ($recent ? 'recent' : null),
                href: $this->href($link, $this->items(['branches' => [$id], 'side' => $side, 'area' => $area,
                    'from' => $c['p1'], 'to' => $c['p2']])),
                tip: self::tip($head, $lines),
                aria: $head.': '.implode(', ', array_filter($lines)).'.',
            );
        }

        $all = $agg['*'];
        $cells['Total'] = self::cell(
            text: $count ? Format::n($all['rows']) : self::rk($all['amount']),
            sort: $count ? (string) $all['rows'] : self::money($all['amount']),
            class: 'num grp',
            href: $all['rows'] > 0 ? $this->href($link, $this->items(['branches' => [$id], 'side' => $side, 'area' => $area])) : null,
            tip: self::tip($name.' · every period · '.$what, [self::r($all['amount']).' in '.self::rows($all['rows'], $side)]),
            aria: $name.', every period, '.$what.': '.self::r($all['amount']).' in '.self::rows($all['rows'], $side).'.',
        );

        return $cells;
    }

    /**
     * @param  array<int, array<string, array{rows: int, amount: int, transitRows: int, transitAmount: int, recentRows: int, recentTransitRows: int}>>  $sums
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, mixed>
     */
    private function dateTotal(array $sums, array $columns, string $lens, string $side, ?string $area, ?Closure $link): array
    {
        $count = $lens === 'count';
        $scope = $this->selected === [] ? null : $this->selected;
        $sites = count($this->rowIds);
        $what = self::sideWord($side).($area ? ' · '.self::LABELS[$area] : '');
        $cells = [];

        foreach ([...$columns, ['key' => '*', 'long' => 'every period', 'p1' => null, 'p2' => null]] as $c) {
            $rows = 0;
            $amount = 0;
            $k = 0;
            foreach ($sums as $agg) {
                $rows += $agg[$c['key']]['rows'] ?? 0;
                $amount += $agg[$c['key']]['amount'] ?? 0;
                $k += ($agg[$c['key']]['rows'] ?? 0) !== 0 ? 1 : 0;
            }
            $head = 'All sites in scope · '.$c['long'].' · '.$what;
            $total = $c['key'] === '*';
            $cells[$total ? 'Total' : $c['key']] = self::cell(
                text: $count ? Format::n($rows) : self::rk($amount),
                sort: $count ? (string) $rows : self::money($amount),
                class: $total ? 'num grp' : 'num',
                href: $rows > 0 ? $this->href($link, $this->items(['branches' => $scope, 'side' => $side, 'area' => $area]
                    + ($total ? [] : ['from' => $c['p1'], 'to' => $c['p2']]))) : null,
                tip: self::tip($head, [
                    self::r($amount).' in '.self::rows($rows, $side),
                    Format::n($k).' of '.Format::n($sites).' sites have '.self::sideWord($side).' outstanding dated in this period',
                ]),
                aria: $head.': '.self::r($amount).' in '.self::rows($rows, $side).'.',
            );
        }

        return ['label' => 'All sites in scope ('.$sites.' '.($sites === 1 ? 'site' : 'sites').')', 'cells' => $cells];
    }

    /**
     * The earliest TransitFrom of these areas.
     *
     * @param  list<string>  $areas
     */
    private function transitFrom(array $areas): ?string
    {
        $dates = array_filter(array_map(fn (string $a) => $this->coverage[$a]['from'] ?? null, $areas));

        return $dates === [] ? null : min($dates);
    }

    /**
     * "in transit from 13 Nov (ABSA, FNB, Cash machine, Smart ATM) · 9 Nov (Cash bags)"
     *
     * @param  list<string>  $areas
     */
    private function transitTitle(array $areas): string
    {
        $groups = [];
        foreach ($areas as $area) {
            $from = $this->coverage[$area]['from'] ?? null;
            if ($from !== null) {
                $groups[$from][] = self::LABELS[$area];
            }
        }
        krsort($groups);

        return $groups === [] ? '' : 'in transit from '.implode(' · ', array_map(
            fn (string $date, array $labels) => self::date($date, 'j M').' ('.implode(', ', $labels).')',
            array_keys($groups),
            $groups,
        ));
    }

    /* ============================================================ the export */

    /** @return array{title: string, columns: list<GridColumn>, rows: Collection<int, object>} */
    private function longSheet(): array
    {
        $columns = [
            new GridColumn('site_id', 'Site ID', 'number'),
            new GridColumn('site', 'Site'),
            new GridColumn('site_status', 'Site status'),
            new GridColumn('area', 'Area'),
            new GridColumn('side', 'Side'),
            new GridColumn('items', 'Items', 'number'),
            new GridColumn('amount', 'Amount', 'money'),
            new GridColumn('transit_items', 'In transit items', 'number'),
            new GridColumn('transit_amount', 'In transit amount', 'money'),
        ];
        foreach (ReconDashboardService::BUCKETS as $prefix => $label) {
            $columns[] = new GridColumn('age_'.strtolower($prefix), 'Amount '.mb_strtolower($label), 'money');
        }
        array_push($columns,
            new GridColumn('oldest', 'Oldest', 'date'),
            new GridColumn('newest', 'Newest', 'date'),
            new GridColumn('first', 'First row ever', 'date'),
            new GridColumn('last', 'Last row ever', 'date'),
            new GridColumn('rules', 'Auto rules'),
            new GridColumn('status', 'Status'),
        );

        $order = array_merge($this->rowIds, $this->entityIds,
            array_values(array_diff(array_keys($this->sites), $this->rowIds, $this->entityIds)));
        $rows = [];

        foreach ($order as $id) {
            foreach ([...self::AREAS, 'Unclassified'] as $area) {
                foreach (self::SIDES as $side) {
                    $row = $area === 'Unclassified'
                        ? ($side === 'Bank' ? ($this->loose[$id] ?? null) : null)
                        : ($this->out[$id][$area][$side] ?? null);
                    if ($row === null) {
                        continue;
                    }

                    $f = $area === 'Unclassified' ? null : $this->facts[$id][$area]['f'];
                    $line = [
                        'site_id' => $id,
                        'site' => $this->sites[$id]['name'],
                        'site_status' => $this->siteStatusLabel($id),
                        'area' => self::LABELS[$area] ?? $area,
                        'side' => $side,
                        'items' => $row['rows'],
                        'amount' => $row['amount'] / 100,
                        'transit_items' => $row['transitRows'],
                        'transit_amount' => $row['transitAmount'] / 100,
                    ];
                    foreach (array_keys(ReconDashboardService::BUCKETS) as $prefix) {
                        $line['age_'.strtolower($prefix)] = $row['age'][$prefix]['amount'] / 100;
                    }
                    $rules = $f['rules'] ?? null;
                    $rows[] = (object) ($line + [
                        'oldest' => $row['oldest'],
                        'newest' => $row['newest'],
                        'first' => $f[$side]['first'] ?? null,
                        'last' => $f[$side]['last'] ?? null,
                        'rules' => $rules === null ? null : ($rules > 0 ? 'Yes' : 'No'),
                        'status' => $area === 'Unclassified' ? null : $this->facts[$id][$area]['status'],
                    ]);
                }
            }
        }

        return ['title' => 'Recon pivot long form', 'columns' => $columns, 'rows' => self::objects($rows)];
    }

    /** Trading, Mothballed or Non-trading, plus " · manual only". */
    private function siteStatusLabel(int $id): string
    {
        $site = $this->sites[$id];
        $label = $site['status'] ?? ($site['trading'] === false ? 'Non-trading' : 'Trading');

        return $label.($this->manualAreas($id) !== [] ? ' · manual only' : '');
    }

    private static function exportKey(string $key): string
    {
        return 'c_'.trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $key), '_');
    }

    private static function exportValue(string $sort, string $format): int|float|null
    {
        if ($sort === '' || ! is_numeric($sort)) {
            return null;
        }

        return $format === 'number' ? (int) $sort : round((float) $sort, 2);
    }

    /* ============================================================== helpers */

    /**
     * The items view, with every filter it reads said explicitly, so nothing
     * leaks in from the page's own query.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function items(array $query): array
    {
        return ['view' => 'items'] + $query + ['area' => null, 'side' => null, 'cols' => null, 'lens' => null, 'status' => null];
    }

    /** @param  array<string, mixed>  $query */
    private function href(?Closure $link, array $query): ?string
    {
        return $link === null ? null : (string) $link($query + ['fresh' => null]);
    }

    /**
     * Every site's measures added up: the scope's sums.
     *
     * @return array<string, mixed>
     */
    private function sums(): array
    {
        $a = $this->sumOf(array_keys($this->sites));
        $oldest = ['Bank' => null, 'PumpIT' => null];
        foreach ($this->out as $areas) {
            foreach ($areas as $sides) {
                foreach ($sides as $side => $row) {
                    $oldest[$side] = self::earliest($oldest[$side], $row['oldest']);
                }
            }
        }

        $U = 0;
        $Un = 0;
        $Uin = 0;
        foreach ($this->loose as $row) {
            $U += (int) $row['amount'];
            $Un += (int) $row['rows'];
            $Uin += (int) $row['creditAmount'];
        }

        return $a + ['oldestB' => $oldest['Bank'], 'oldestP' => $oldest['PumpIT'], 'U' => $U, 'Un' => $Un, 'Uin' => $Uin];
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, int>
     */
    private function sumOf(array $ids): array
    {
        $sum = [];
        foreach (['B', 'Bn', 'P', 'Pn', 'T', 'Tn', 'short', 'over', 'both', 'wait', 'B30', 'P30', 'short30', 'over30',
            'Bold', 'Boldn', 'Pold', 'Poldn'] as $key) {
            $sum[$key] = 0;
            foreach ($ids as $id) {
                $sum[$key] += (int) ($this->agg[$id][$key] ?? 0);
            }
        }

        return $sum;
    }

    /** @param  array<string, mixed>  $f */
    private static function glyph(array $f, string $side): string
    {
        return match (true) {
            $f['unused'] === true => 'unused',
            $f[$side]['used'] === null => 'unknown',
            $f[$side]['never'] === true => 'never',
            $f[$side]['stopped'] === true => 'stopped',
            $f[$side]['used'] === true => 'clear',
            default => 'unknown',
        };
    }

    /**
     * The glyph of a Difference cell, where neither side has rows.
     *
     * @param  array<string, mixed>  $f
     */
    private static function pairGlyph(array $f): string
    {
        return match (true) {
            $f['unused'] === true => 'unused',
            $f['Bank']['used'] === null || $f['PumpIT']['used'] === null => 'unknown',
            $f['Bank']['never'] === true || $f['PumpIT']['never'] === true => 'never',
            $f['Bank']['stopped'] === true || $f['PumpIT']['stopped'] === true => 'stopped',
            default => 'clear',
        };
    }

    /**
     * HTML for data-tip: the heading in bold, then at most five lines, every
     * part escaped. The page writes it into an attribute, which escapes it
     * once more; tip.js reads it back as HTML.
     *
     * @param  array<int, string|null>  $lines
     */
    private static function tip(string $head, array $lines): string
    {
        $lines = array_slice(array_values(array_filter($lines, fn (?string $line) => $line !== null && $line !== '')), 0, 5);

        return '<b>'.e($head).'</b>'.implode('', array_map(fn (string $line) => '<br>'.e($line), $lines));
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @param  list<string>  $buckets
     */
    private static function bucket(?array $row, array $buckets, string $what): int
    {
        $sum = 0;
        foreach ($buckets as $bucket) {
            $sum += (int) ($row['age'][$bucket][$what] ?? 0);
        }

        return $sum;
    }

    /** @param  array<string, mixed>  $settings */
    private static function tolerance(int $a, int $b, array $settings): float
    {
        $settings += self::DEFAULTS;

        return max((float) $settings['tolerance_rand'] * 100, (float) $settings['tolerance_pct'] / 100 * max(abs($a), abs($b)));
    }

    private static function areaLabel(string $area): string
    {
        return (string) (config('recon.areas.'.$area.'.label') ?? self::LABELS[$area]);
    }

    private static function sideWord(string $side): string
    {
        return $side === 'Bank' ? 'bank' : 'PumpIT';
    }

    private static function noun(string $side): string
    {
        return $side === 'Bank' ? 'bank line' : 'deposit';
    }

    /** "1 line", "412 deposits". */
    private static function rows(int $n, string $side): string
    {
        return Format::n($n).' '.($side === 'Bank' ? ($n === 1 ? 'line' : 'lines') : ($n === 1 ? 'deposit' : 'deposits'));
    }

    /** @param  list<string>  $items */
    private static function listing(array $items): string
    {
        return count($items) <= 1 ? implode('', $items) : implode(', ', array_slice($items, 0, -1)).' and '.end($items);
    }

    private static function cents(mixed $value): int
    {
        return is_numeric($value) ? (int) round((float) $value * 100) : 0;
    }

    /** Raw rand, two places, for data-sort and the export. */
    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private static function rk(int $cents): string
    {
        return Format::rk($cents / 100);
    }

    private static function r(int $cents): string
    {
        return Format::r($cents / 100);
    }

    private static function flag(mixed $value): ?bool
    {
        return $value === null || $value === '' ? null : (bool) (int) $value;
    }

    private static function day(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value, 0, 10);
    }

    private static function date(?string $day, string $pattern): string
    {
        return $day === null ? Format::NOTHING : CarbonImmutable::parse($day)->format($pattern);
    }

    /** "3 Nov – 9 Nov 2026", or one date. */
    private static function span(string $from, string $to): string
    {
        if ($from === $to) {
            return self::date($from, 'j M Y');
        }

        return self::date($from, substr($from, 0, 4) === substr($to, 0, 4) ? 'j M' : 'j M Y').' – '.self::date($to, 'j M Y');
    }

    /** "2024", "Jan–Nov 2025", "Mar 2025". */
    private static function yearLabel(string $p1, string $p2): string
    {
        $a = CarbonImmutable::parse($p1);
        $b = CarbonImmutable::parse($p2);

        return match (true) {
            $a->month === 1 && $b->month === 12 => (string) $a->year,
            $a->month === $b->month => $a->format('M Y'),
            default => $a->format('M').'–'.$b->format('M Y'),
        };
    }

    private static function shift(string $day, int $days): string
    {
        return CarbonImmutable::parse($day)->addDays($days)->format('Y-m-d');
    }

    private static function daysBetween(string $from, string $to): int
    {
        return (int) round(CarbonImmutable::parse($from)->startOfDay()->diffInDays(CarbonImmutable::parse($to)->startOfDay()));
    }

    private static function earliest(?string $a, ?string $b): ?string
    {
        return $a === null ? $b : ($b === null ? $a : min($a, $b));
    }

    private static function latest(?string $a, ?string $b): ?string
    {
        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }

    /**
     * @param  list<object>  $rows
     * @return Collection<int, object>
     */
    private static function objects(array $rows): Collection
    {
        return new Collection($rows);
    }

    /**
     * A result set as a list of arrays, whether it arrived as arrays (the
     * cache) or objects (a direct call).
     *
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $set): array
    {
        if ($set instanceof Collection) {
            $set = $set->all();
        }

        return is_array($set) ? array_values(array_map(fn ($row) => (array) $row, array_filter($set, fn ($row) => is_array($row) || is_object($row)))) : [];
    }
}
