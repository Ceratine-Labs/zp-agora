<?php

namespace Modules\StockRecon\Services;

use Illuminate\Support\Carbon;

/**
 * The months and weeks the stock recon form offers instead of free dates.
 *
 * ZRP, 4 Oct 2026: pick a month, then a week inside it — ONE week since
 * 7 Oct 2026 (Ryan: "only let the users pick one week at a time"). A
 * week is Monday to Sunday — the client's weekly pack reviews Monday–Sunday
 * weeks — and is cut at the month's edge, so "week 1" of September 2026 is
 * Tuesday 1 to Sunday 6 and the last is Monday 28 to Wednesday 30.
 *
 * The form posts the week's first day as `from`; the request works out `to`
 * with weekEnd(), so the run and the procedure are unchanged.
 */
class ReconPeriods
{
    /**
     * This month and the twelve before it, newest first.
     *
     * @return array<string, string> 'Y-m' => 'September 2026'
     */
    public static function months(Carbon $today, int $back = 12): array
    {
        $months = [];
        $month = $today->copy()->startOfMonth();

        for ($i = 0; $i <= $back; $i++) {
            $months[$month->format('Y-m')] = $month->format('F Y');
            $month->subMonthNoOverflow();
        }

        return $months;
    }

    /**
     * The Monday–Sunday weeks of a month, cut at its first and last day.
     *
     * @return list<array{n: int, from: string, to: string, label: string}>
     */
    public static function weeks(string $month): array
    {
        $first = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $last = $first->copy()->endOfMonth()->startOfDay();

        $weeks = [];
        $start = $first->copy();
        $n = 1;

        while ($start->lte($last)) {
            $end = $start->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();

            if ($end->gt($last)) {
                $end = $last->copy();
            }

            $weeks[] = [
                'n' => $n,
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'label' => 'Week '.$n.' · '.($start->eq($end)
                    ? $start->format('j M')
                    : $start->format('j').'–'.$end->format('j M')),
            ];

            $start = $end->copy()->addDay();
            $n++;
        }

        return $weeks;
    }

    /**
     * The last day of the week a date falls in: its Sunday, or the last day of
     * its month if that comes first — the same cut weeks() makes. A preview is
     * ONE week (Ryan, 7 Oct 2026: "only let the users pick one week at a
     * time"), so the end is never chosen, only worked out from the start.
     */
    public static function weekEnd(Carbon $from): Carbon
    {
        $sunday = $from->copy()->startOfDay()->endOfWeek(Carbon::SUNDAY)->startOfDay();
        $monthEnd = $from->copy()->endOfMonth()->startOfDay();

        return $sunday->lt($monthEnd) ? $sunday : $monthEnd;
    }

    /**
     * The default: the last full Monday–Sunday week that ended before today,
     * cut to the month its Monday falls in — the week the client's pack would
     * review on Tuesday.
     *
     * @return array{month: string, from: string, to: string}
     */
    public static function lastFullWeek(Carbon $today): array
    {
        $monday = $today->copy()->startOfDay()->subWeek()->startOfWeek(Carbon::MONDAY);
        $sunday = $monday->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();
        $month = $monday->format('Y-m');

        if ($sunday->format('Y-m') !== $month) {
            $sunday = $monday->copy()->endOfMonth()->startOfDay();
        }

        return ['month' => $month, 'from' => $monday->toDateString(), 'to' => $sunday->toDateString()];
    }
}
