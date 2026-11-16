<?php

namespace Modules\Recon\Services;

use App\Support\ProcedureService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The bank recon dashboard's figures: what is still outstanding, per site,
 * per area, per side, aged (Ryan, 13 Oct 2026).
 *
 * One procedure call, agora.usp_Recon_DashboardOutstanding, which adds the
 * rows up on the server — the page never holds a row, only the few hundred
 * figures it shows. Everything here is arithmetic over that one call, so the
 * summary, the per-site view, the pivot and the totals cannot disagree.
 *
 * CACHED (16 Nov 2026). The call scans every bank line and deposit on live
 * PumpIT under shared locks (PumpIT has no snapshot isolation), and the pivot
 * switches lens, columns, side, area and export on the same figures. So
 * figures() keeps the answer for a few minutes per scope, keyed on Agora's own
 * recon ledger as well: any commit, manual match or reversal Agora makes moves
 * MAX(Id) or MAX(ReversedAt) on agora.ReconBatch, which is a new key, so the
 * next read is fresh. ZP's own Execute in PumpIT does not touch that ledger;
 * its effect shows within the cache's minutes. The feed history (feeds()) is a
 * second, separate scan, kept an hour per grant, and a page that cannot read
 * it still draws.
 *
 * Both caches hold plain arrays: the cache store will not unserialise objects
 * (config/cache.php serializable_classes).
 */
class ReconDashboardService
{
    /** The areas in the order the screen shows them; Unclassified last. */
    public const AREAS = ['ABSA', 'FNB', 'CashMachine', 'CashBags', 'SmartATM', 'Unclassified'];

    public const SIDES = ['Bank', 'PumpIT'];

    /** The age buckets, keyed by the procedure's column prefix. */
    public const BUCKETS = [
        'Age7' => '0–7 days',
        'Age30' => '8–30 days',
        'Age90' => '31–90 days',
        'Age365' => '91–365 days',
        'AgeOver' => 'Over a year',
    ];

    /** The longest window, in days, the procedure will split into day columns. */
    public const DAY_GRAIN_DAYS = 92;

    public function __construct(protected ProcedureService $procedures) {}

    /**
     * Everything the dashboard draws, from one call: the four result sets of
     * usp_Recon_DashboardOutstanding as plain arrays.
     *
     *   rows      set 1, one per site × area × side with anything outstanding
     *   sites     set 2, every site in scope (so a clear one can still be a row)
     *   coverage  set 3, per area: LagDays, LastLineDate, TransitFrom
     *   periods   set 4, per site × area × side × day or month
     *
     * A refusal (AgoraProcException) is thrown, never cached. `fresh` drops the
     * cached copy first — the page's Refresh.
     *
     * @param  array<int, int|string>  $selected  the selection; empty is every site in the grant
     * @param  array<int, int|string>  $allowed  the grant; empty is the whole estate
     * @return array{read_at: string, grain: string,
     *     rows: list<array<string, mixed>>, sites: list<array<string, mixed>>,
     *     coverage: list<array<string, mixed>>, periods: list<array<string, mixed>>}
     */
    public function figures(array $selected, array $allowed, ?string $from, ?string $to, string $asAt, bool $fresh = false): array
    {
        $selected = self::ids($selected);
        $allowed = self::grant($allowed);
        $key = 'recon:dashboard:v1:'.sha1((string) json_encode([$selected, $allowed, $from, $to, $asAt, $this->ledger()]));

        if ($fresh) {
            Cache::forget($key);
        }

        $cached = Cache::get($key);

        if (self::isFigures($cached)) {
            /** @var array{read_at: string, grain: string, rows: list<array<string, mixed>>, sites: list<array<string, mixed>>, coverage: list<array<string, mixed>>, periods: list<array<string, mixed>>} $cached */
            return $cached;
        }

        $grain = self::grain($from, $to, $asAt);
        $sets = $this->procedures->callSets('usp_Recon_DashboardOutstanding', [
            'BranchIds' => $selected === [] ? null : implode(',', $selected),
            'AllowedBranchIds' => $allowed === [] ? null : implode(',', $allowed),
            'DateFrom' => $from,
            'DateTo' => $to,
            'AsAt' => $asAt,
            'Grain' => $grain,
        ]);

        $figures = [
            'read_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
            'grain' => $grain,
            'rows' => self::plain($sets[0] ?? null),
            'sites' => self::plain($sets[1] ?? null),
            'coverage' => self::plain($sets[2] ?? null),
            'periods' => self::plain($sets[3] ?? null),
        ];

        Cache::put($key, $figures, CarbonImmutable::now()->addMinutes(max(1, (int) config('recon.dashboard.cache_minutes', 5))));

        return $figures;
    }

    /**
     * The feed history: per site × area × side, the first and last row ever and
     * how many (set 1), and per site × area the extraction rules (set 2), from
     * agora.usp_Recon_DashboardFeeds over the whole grant.
     *
     * Null when it cannot be read — anything at all, reported and never cached —
     * and the pivot then shows `—` where only the history could tell "clear"
     * from "never used". It is the second scan on live PumpIT, so it is kept an
     * hour per grant and the page never waits on it twice.
     *
     * @param  array<int, int|string>  $allowed  the grant; empty is the whole estate
     * @return array{read_at: string, sides: list<array<string, mixed>>, rules: list<array<string, mixed>>}|null
     */
    public function feeds(array $allowed, string $asAt, bool $fresh = false): ?array
    {
        $allowed = self::grant($allowed);
        $key = 'recon:dashboard:feeds:v1:'.sha1((string) json_encode([$allowed, $asAt]));

        try {
            if ($fresh) {
                Cache::forget($key);
            }

            $cached = Cache::get($key);

            if (self::isFeeds($cached)) {
                /** @var array{read_at: string, sides: list<array<string, mixed>>, rules: list<array<string, mixed>>} $cached */
                return $cached;
            }

            $sets = $this->procedures->callSets('usp_Recon_DashboardFeeds', [
                'BranchIds' => null,
                'AllowedBranchIds' => $allowed === [] ? null : implode(',', $allowed),
                'AsAt' => $asAt,
            ]);

            // Without set 2 every site would read "no rules", which is a claim,
            // not an absence. Half an answer is no answer.
            if (count($sets) < 2) {
                throw new \RuntimeException('agora.usp_Recon_DashboardFeeds returned '.count($sets).' result set(s); the dashboard needs two.');
            }

            $feeds = [
                'read_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
                'sides' => self::plain($sets[0]),
                'rules' => self::plain($sets[1]),
            ];

            Cache::put($key, $feeds, CarbonImmutable::now()->addMinutes(max(1, (int) config('recon.dashboard.feeds_cache_minutes', 60))));

            return $feeds;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Day columns when the window is short enough to be read a day at a time,
     * months otherwise — the procedure refuses days over a longer window, or
     * with no From. A From after the To asks for nothing; it gets months
     * rather than a misleading GRAIN_TOO_FINE.
     */
    public static function grain(?string $from, ?string $to, string $asAt): string
    {
        if ($from === null) {
            return 'month';
        }

        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to ?? $asAt)->startOfDay();

        return $start <= $end && $start->diffInDays($end) <= self::DAY_GRAIN_DAYS - 1 ? 'day' : 'month';
    }

    /**
     * Agora's own recon ledger, as a cache key: any commit, manual match or
     * reversal moves one of these two.
     *
     * @return array{0: mixed, 1: mixed}
     */
    protected function ledger(): array
    {
        $row = DB::connection(config('agora.connections.app'))
            ->table(config('agora.schema').'.ReconBatch')
            ->selectRaw('MAX(Id) AS LastId, MAX(ReversedAt) AS LastReversal')
            ->first();

        return [$row->LastId ?? null, $row->LastReversal ?? null];
    }

    /**
     * The figures the Summary and Per site views draw, from set 1.
     *
     * Rows may be the procedure's objects or figures()' arrays; the per-site
     * `cells` are always objects, as the views have always read them.
     *
     * Per site, `oldest` and `classified` count the classified areas only
     * (16 Nov 2026: Unclassified lines go back to 2015 and were turning every
     * site's "oldest" into 2015); `unclassified` is the site's Unclassified
     * line count, shown beside them and never inside them. `rows` and `amount`
     * still carry everything, as before.
     *
     * @param  Collection<int, mixed>  $rows
     * @return array{
     *     sides: array<string, array{rows: int, amount: float, oldest: ?string}>,
     *     unclassified: array{rows: int, amount: float, oldest: ?string},
     *     areas: array<string, array<string, array{rows: int, amount: float}>>,
     *     ageing: array<string, array<string, array{rows: int, amount: float}>>,
     *     sites: array<int, array{id: int, name: string, rows: int, amount: float, oldest: ?string,
     *         classified: int, unclassified: int, cells: array<string, array<string, object>>}>,
     * }
     */
    public function shape(Collection $rows): array
    {
        $rows = $rows->map(fn ($r) => is_array($r) ? (object) $r : $r)->filter(fn ($r) => is_object($r))->values();
        $sides = [];
        $areas = [];
        $ageing = [];

        /*
         * The headline figures leave the Unclassified bank lines out, and show
         * them on their own. On live (16 Nov 2026) they are 93,700 lines back
         * to 2015 — debit orders and fees as well as deposits — against 41,004
         * classified bank lines outstanding; mixed in, they would turn the bank
         * total negative and make every other number unreadable.
         */
        $classified = $rows->where('ReconArea', '!=', 'Unclassified');
        $loose = $rows->where('ReconArea', 'Unclassified');
        $unclassified = [
            'rows' => (int) $loose->sum('Rows'),
            'amount' => (float) $loose->sum(fn ($r) => (float) $r->Amount),
            'oldest' => self::day($loose->min('OldestDate')),
        ];

        foreach (self::SIDES as $side) {
            $mine = $classified->where('Side', $side);
            $sides[$side] = [
                'rows' => (int) $mine->sum('Rows'),
                'amount' => (float) $mine->sum(fn ($r) => (float) $r->Amount),
                'oldest' => self::day($mine->min('OldestDate')),
            ];

            foreach (self::BUCKETS as $prefix => $label) {
                $ageing[$side][$prefix] = [
                    'rows' => (int) $mine->sum($prefix.'Rows'),
                    'amount' => (float) $mine->sum(fn ($r) => (float) $r->{$prefix.'Amount'}),
                ];
            }
        }

        foreach (self::AREAS as $area) {
            foreach (self::SIDES as $side) {
                $cell = $rows->where('ReconArea', $area)->where('Side', $side);
                $areas[$area][$side] = [
                    'rows' => (int) $cell->sum('Rows'),
                    'amount' => (float) $cell->sum(fn ($r) => (float) $r->Amount),
                ];
            }
        }

        $sites = $rows->groupBy('BranchId')
            ->map(function (Collection $site) {
                $sorted = $site->where('ReconArea', '!=', 'Unclassified');

                return [
                    'id' => (int) $site->first()->BranchId,
                    'name' => (string) $site->first()->BranchName,
                    'rows' => (int) $site->sum('Rows'),
                    'amount' => (float) $site->sum(fn ($r) => (float) $r->Amount),
                    'oldest' => self::day($sorted->min('OldestDate')),
                    'classified' => (int) $sorted->sum('Rows'),
                    'unclassified' => (int) $site->where('ReconArea', 'Unclassified')->sum('Rows'),
                    // [area][side] => the procedure's row, for the per-site table.
                    'cells' => $site->groupBy('ReconArea')
                        ->map(fn (Collection $area) => $area->keyBy('Side')->all())
                        ->all(),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        return ['sides' => $sides, 'unclassified' => $unclassified, 'areas' => $areas, 'ageing' => $ageing, 'sites' => $sites];
    }

    /**
     * Sorted, unique, positive site ids: the same selection in any order is the
     * same cache entry.
     *
     * @param  array<int, int|string>  $ids
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
        sort($ids);

        return $ids;
    }

    /**
     * The grant as given, sorted and unique, so it fails CLOSED. Empty is the
     * whole estate, as BranchContext reads it, and only empty: a grant of
     * nothing but ids that are no site (0, -1) must reach the procedures as
     * those ids — which match no site — and not be filtered down to empty,
     * which both procedures read as every site. ids() is for the selection,
     * where dropping a nonsense id only widens to the grant.
     *
     * @param  array<int, int|string>  $ids
     * @return list<int>
     */
    private static function grant(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * @param  Collection<int, object>|null  $set
     * @return list<array<string, mixed>>
     */
    private static function plain(?Collection $set): array
    {
        return $set === null ? [] : array_values($set->map(fn (object $row) => (array) $row)->all());
    }

    private static function isFigures(mixed $value): bool
    {
        return is_array($value)
            && is_string($value['read_at'] ?? null)
            && is_string($value['grain'] ?? null)
            && is_array($value['rows'] ?? null)
            && is_array($value['sites'] ?? null)
            && is_array($value['coverage'] ?? null)
            && is_array($value['periods'] ?? null);
    }

    private static function isFeeds(mixed $value): bool
    {
        return is_array($value)
            && is_string($value['read_at'] ?? null)
            && is_array($value['sides'] ?? null)
            && is_array($value['rules'] ?? null);
    }

    private static function day(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : substr((string) $value, 0, 10);
    }
}
