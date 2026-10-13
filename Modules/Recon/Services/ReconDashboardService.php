<?php

namespace Modules\Recon\Services;

use App\Support\ProcedureService;
use Illuminate\Support\Collection;

/**
 * The bank recon dashboard's figures: what is still outstanding, per site,
 * per area, per side, aged (Ryan, 13 Oct 2026).
 *
 * One procedure call, agora.usp_Recon_DashboardOutstanding, which adds the
 * rows up on the server — the page never holds a row, only the few hundred
 * figures it shows. Everything here is arithmetic over that one result set, so
 * the summary, the per-site view and the totals cannot disagree.
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

    public function __construct(protected ProcedureService $procedures) {}

    /**
     * @param  array<int, int>  $branchIds  the selection; empty is every site in the grant
     * @param  array<int, int>  $allowed  the grant; empty is the whole estate
     * @return Collection<int, object> one row per site × area × side
     */
    public function outstanding(array $branchIds, array $allowed, ?string $from, ?string $to, string $asAt): Collection
    {
        return $this->procedures->call('usp_Recon_DashboardOutstanding', [
            'BranchIds' => $branchIds === [] ? null : implode(',', $branchIds),
            'AllowedBranchIds' => $allowed === [] ? null : implode(',', $allowed),
            'DateFrom' => $from,
            'DateTo' => $to,
            'AsAt' => $asAt,
        ]);
    }

    /**
     * The figures the page draws, from the procedure's rows.
     *
     * @param  Collection<int, object>  $rows
     * @return array{
     *     sides: array<string, array{rows: int, amount: float, oldest: ?string}>,
     *     unclassified: array{rows: int, amount: float, oldest: ?string},
     *     areas: array<string, array<string, array{rows: int, amount: float}>>,
     *     ageing: array<string, array<string, array{rows: int, amount: float}>>,
     *     sites: array<int, array{id: int, name: string, rows: int, amount: float, oldest: ?string,
     *         cells: array<string, array<string, object>>}>,
     * }
     */
    public function shape(Collection $rows): array
    {
        $sides = [];
        $areas = [];
        $ageing = [];

        /*
         * The headline figures leave the Unclassified bank lines out, and show
         * them on their own. On live (13 Oct 2026) they are 86,586 lines back
         * to 2015 — debit orders and fees as well as deposits — against 49,360
         * classified lines outstanding; mixed in, they would turn the bank
         * total negative and make every other number unreadable.
         */
        $classified = $rows->where('ReconArea', '!=', 'Unclassified');
        $loose = $rows->where('ReconArea', 'Unclassified');
        $unclassified = [
            'rows' => (int) $loose->sum('Rows'),
            'amount' => (float) $loose->sum(fn ($r) => (float) $r->Amount),
            'oldest' => $loose->min('OldestDate') ? substr((string) $loose->min('OldestDate'), 0, 10) : null,
        ];

        foreach (self::SIDES as $side) {
            $mine = $classified->where('Side', $side);
            $sides[$side] = [
                'rows' => (int) $mine->sum('Rows'),
                'amount' => (float) $mine->sum(fn ($r) => (float) $r->Amount),
                'oldest' => $mine->min('OldestDate') ? substr((string) $mine->min('OldestDate'), 0, 10) : null,
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
            ->map(fn (Collection $site) => [
                'id' => (int) $site->first()->BranchId,
                'name' => (string) $site->first()->BranchName,
                'rows' => (int) $site->sum('Rows'),
                'amount' => (float) $site->sum(fn ($r) => (float) $r->Amount),
                'oldest' => substr((string) $site->min('OldestDate'), 0, 10),
                // [area][side] => the procedure's row, for the per-site table.
                'cells' => $site->groupBy('ReconArea')
                    ->map(fn (Collection $area) => $area->keyBy('Side')->all())
                    ->all(),
            ])
            ->sortBy('name')
            ->values()
            ->all();

        return ['sides' => $sides, 'unclassified' => $unclassified, 'areas' => $areas, 'ageing' => $ageing, 'sites' => $sites];
    }
}
