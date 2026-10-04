<?php

namespace Modules\StockRecon\Services;

use Illuminate\Support\Carbon;

/**
 * The months and weeks the stock recon form offers instead of free dates.
 *
 * ZRP, 4 Oct 2026: pick a month, then a week or a run of weeks inside it. A
 * week is Monday to Sunday — the client's weekly pack reviews Monday–Sunday
 * weeks — and is cut at the month's edge, so "week 1" of September 2026 is
 * Tuesday 1 to Sunday 6 and the last is Monday 28 to Wednesday 30.
 *
 * The form still posts `from` and `to` as dates, so the request, the run and
 * the procedure are unchanged: this only decides which dates can be chosen.
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
