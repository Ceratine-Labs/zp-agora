<?php

namespace Modules\StockRecon\Services;

use App\Support\BranchContext;
use App\Support\BusinessDate;
use App\Support\ProcedureService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Is each day reconciled? (Ryan, 13 Nov 2026.)
 *
 * Every answer is agora.usp_StockRecon_DayStatus's — green, orange, red, grey
 * and the words under each cell are decided there. This chooses the month and
 * the sites, and shapes the rows for the calendar.
 */
class ReconDayService
{
    public const PROCEDURE = 'agora.usp_StockRecon_DayStatus';

    public const ITEMS_PROCEDURE = 'agora.usp_StockRecon_DayItems';

    /** The procedure's DayStatus, as the calendar names it. */
    public const LEGEND = [
        'done' => 'Reconciled',
        'partial' => 'Partly reconciled',
        'open' => 'Not reconciled',
        'none' => 'No counts',
        'later' => 'Not over yet',
    ];

    public function __construct(
        protected ProcedureService $procedures,
        protected BranchContext $context,
    ) {}

    /**
     * The month a request asks for ("2026-11"), or the current business month.
     */
    public static function month(?string $asked): Carbon
    {
        if (is_string($asked) && preg_match('/^\d{4}-\d{2}$/', $asked) === 1) {
            return Carbon::createFromFormat('!Y-m', $asked) ?: BusinessDate::today()->startOfMonth();
        }

        return BusinessDate::today()->startOfMonth();
    }

    /**
     * One month, the given sites: one entry per site, each with a cell per day.
     *
     * @param  array<int, int>  $branchIds
     * @return Collection<int, array{branchId: int, name: string, days: Collection<int, object>}>
     */
    public function calendar(array $branchIds, Carbon $month): Collection
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        $rows = $this->procedures->callSets(self::PROCEDURE, [
            'BranchIds' => implode(',', $branchIds),
            'AllowedBranchIds' => implode(',', $this->context->allowed()),
            'FromDate' => $from->toDateString(),
            'ToDate' => $to->toDateString(),
            'Today' => BusinessDate::today()->toDateString(),
            'ExcludedAreaGroups' => implode(',', (array) config('stockrecon.excluded_area_groups')),
        ])[0] ?? collect();

        return $rows->groupBy('BranchId')
            ->map(fn (Collection $days, $branchId) => [
                'branchId' => (int) $branchId,
                'name' => (string) $days->first()->BranchName,
                'days' => $days->values(),
            ])
            ->values();
    }

    /**
     * One site, one day: its areas, what keeps it open, and every run whose
     * window includes it.
     *
     * @return array{areas: Collection<int, object>, open: Collection<int, object>, runs: Collection<int, object>, itemStatus: Collection<string, string>, items: Collection<int|string, Collection<int, object>>}
     */
    public function day(int $branchId, string $day): array
    {
        $sets = $this->procedures->callSets(self::PROCEDURE, [
            'AllowedBranchIds' => implode(',', $this->context->allowed()),
            'Today' => BusinessDate::today()->toDateString(),
            'ExcludedAreaGroups' => implode(',', (array) config('stockrecon.excluded_area_groups')),
            'DetailBranchId' => $branchId,
            'DetailDay' => $day,
        ]);

        return [
            'areas' => $sets[0] ?? collect(),
            'open' => $sets[1] ?? collect(),
            'runs' => $sets[2] ?? collect(),
            // Each item's own done / partial / open, keyed "area:item" (15 Nov 2026).
            'itemStatus' => ($sets[3] ?? collect())
                ->mapWithKeys(fn ($s) => [$s->AreaNo.':'.$s->StockItemNo => (string) $s->ItemStatus]),
            'items' => $this->items($branchId, $day),
        ];
    }

    /**
     * Every item counted in each area that day, with what the tills sold
     * beside the POS on its recon lines (Ryan, 14 Nov 2026, for Chantal
     * Peens's "COMPUTER QTY" check), keyed by area. From
     * agora.usp_StockRecon_DayItems. The day page still answers without it,
     * so a failure reports and returns nothing.
     *
     * @return Collection<int|string, Collection<int, object>>
     */
    public function items(int $branchId, string $day): Collection
    {
        try {
            return $this->procedures->call(self::ITEMS_PROCEDURE, [
                'BranchId' => $branchId,
                'Day' => $day,
            ])->groupBy('AreaNo');
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
    }
}
