<?php

namespace Tests\Unit\StockRecon;

use Illuminate\Support\Carbon;
use Modules\StockRecon\Services\ReconPeriods;
use PHPUnit\Framework\TestCase;

/**
 * The period on the stock recon form: a from date, and the week to date from
 * it (ZRP, 7 Oct 2026).
 */
class ReconPeriodsTest extends TestCase
{
    /** Seven days counting the first, whatever day of the week it is. */
    public function test_the_week_to_date_is_six_days_after_the_from_date(): void
    {
        $today = Carbon::parse('2026-10-07');

        $this->assertSame('2026-09-13', ReconPeriods::weekTo(Carbon::parse('2026-09-07'), $today)->toDateString(), 'Monday to Sunday.');
        $this->assertSame('2026-09-16', ReconPeriods::weekTo(Carbon::parse('2026-09-10'), $today)->toDateString(), 'Thursday to Wednesday.');
        $this->assertSame('2026-10-04', ReconPeriods::weekTo(Carbon::parse('2026-09-28'), $today)->toDateString(),
            'Across a month end: a week from a chosen day has no month edge to stop at.');
    }

    /** "Week to date": a week not over yet ends today. */
    public function test_a_week_that_is_not_over_ends_today(): void
    {
        $today = Carbon::parse('2026-10-07');

        $this->assertSame('2026-10-07', ReconPeriods::weekTo(Carbon::parse('2026-10-05'), $today)->toDateString());
        $this->assertSame('2026-10-07', ReconPeriods::weekTo(Carbon::parse('2026-10-07'), $today)->toDateString(), 'Today alone is a one-day week.');
        $this->assertSame('2026-10-07', ReconPeriods::weekTo(Carbon::parse('2026-10-01'), $today)->toDateString(), 'Exactly a week ago is a full week.');
        $this->assertSame('2026-10-09', ReconPeriods::weekTo(Carbon::parse('2026-10-09'), $today)->toDateString(),
            'Never before the from date, even one the request would refuse.');
    }

    public function test_the_default_is_the_last_full_monday_to_sunday_week(): void
    {
        // Sunday 4 Oct 2026: the week ending today is not over yet.
        $this->assertSame(['from' => '2026-09-21', 'to' => '2026-09-27'], ReconPeriods::lastFullWeek(Carbon::parse('2026-10-04')));

        // Monday 5 Oct: last week ran 28 Sep – 4 Oct, whole now.
        $this->assertSame(['from' => '2026-09-28', 'to' => '2026-10-04'], ReconPeriods::lastFullWeek(Carbon::parse('2026-10-05')));
    }
}
