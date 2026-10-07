<?php

namespace Modules\StockRecon\Services;

use Illuminate\Support\Carbon;

/**
 * The period on the stock recon form: a from date, and the week to date from it.
 *
 * ZRP, 7 Oct 2026: "week range, but to be able to select a from date, and it
 * auto calculates what the week to date would be". The person picks any first
 * day; the last is six days later — a week — or today, if that week is not
 * over yet. It is still ONE week at a time (Ryan, 7 Oct 2026: "only let the
 * users pick one week at a time") and the end is still never chosen: the form
 * shows it, and the request works it out again (BalancePreviewRequest::to()),
 * so no post can ask for more than a week.
 *
 * Until then the form offered a month and the Monday–Sunday weeks inside it,
 * cut at the month's edge (ZRP, 4 Oct 2026). A week from a chosen day has no
 * month edge to cut at, so that cut went with the picker.
 */
class ReconPeriods
{
    /** The days in a week, the first one counted. */
    public const DAYS = 7;

    /**
     * The week to date from `from`: six days later, or today if that is
     * sooner. Never before `from` — a from date of today is a one-day week.
     */
    public static function weekTo(Carbon $from, Carbon $today): Carbon
    {
        $from = $from->copy()->startOfDay();
        $today = $today->copy()->startOfDay();
        $end = $from->copy()->addDays(self::DAYS - 1);

        if ($end->gt($today)) {
            $end = $today->gte($from) ? $today : $from;
        }

        return $end;
    }

    /**
     * The default: the Monday of the last full Monday–Sunday week that ended
     * before today — the week the client's pack would review on Tuesday — and
     * its Sunday.
     *
     * @return array{from: string, to: string}
     */
    public static function lastFullWeek(Carbon $today): array
    {
        $monday = $today->copy()->startOfDay()->subWeek()->startOfWeek(Carbon::MONDAY);

        return ['from' => $monday->toDateString(), 'to' => self::weekTo($monday, $today)->toDateString()];
    }
}
