<?php

namespace Tests\Unit\StockRecon;

use Illuminate\Support\Carbon;
use Modules\StockRecon\Services\ReconPeriods;
use PHPUnit\Framework\TestCase;

/**
 * The month and week choices on the stock recon form (ZRP, 4 Oct 2026).
 *
 * Monday–Sunday weeks, the client's pack's, cut at the month's edge.
 */
class ReconPeriodsTest extends TestCase
{
    public function test_a_month_is_cut_into_monday_to_sunday_weeks(): void
    {
        $weeks = ReconPeriods::weeks('2026-09');

        $this->assertSame(
            [['2026-09-01', '2026-09-06'], ['2026-09-07', '2026-09-13'], ['2026-09-14', '2026-09-20'],
                ['2026-09-21', '2026-09-27'], ['2026-09-28', '2026-09-30']],
            array_map(fn ($w) => [$w['from'], $w['to']], $weeks),
        );
        $this->assertSame('Week 1 · 1–6 Sep', $weeks[0]['label']);
        $this->assertSame('Week 5 · 28–30 Sep', $weeks[4]['label']);
    }

    public function test_a_month_starting_on_sunday_has_a_one_day_first_week(): void
    {
        // 1 Nov 2026 is a Sunday.
        $weeks = ReconPeriods::weeks('2026-11');

        $this->assertSame(['2026-11-01', '2026-11-01'], [$weeks[0]['from'], $weeks[0]['to']]);
        $this->assertSame('Week 1 · 1 Nov', $weeks[0]['label']);
        $this->assertSame('2026-11-30', end($weeks)['to']);
    }

    public function test_the_default_is_the_last_full_week_before_today(): void
    {
        // Sunday 4 Oct 2026: the week ending today is not over yet.
        $this->assertSame(
            ['month' => '2026-09', 'from' => '2026-09-21', 'to' => '2026-09-27'],
            ReconPeriods::lastFullWeek(Carbon::parse('2026-10-04')),
        );

        // Monday 5 Oct: last week ran 28 Sep – 4 Oct, cut to September.
        $this->assertSame(
            ['month' => '2026-09', 'from' => '2026-09-28', 'to' => '2026-09-30'],
            ReconPeriods::lastFullWeek(Carbon::parse('2026-10-05')),
        );
    }

    public function test_the_month_list_runs_back_a_year_newest_first(): void
    {
        $months = ReconPeriods::months(Carbon::parse('2026-10-04'));

        $this->assertCount(13, $months);
        $this->assertSame('October 2026', reset($months));
        $this->assertSame('2025-10', array_key_last($months));
    }

    /** One week at a time (7 Oct 2026): the end is the start's Sunday, or its month's last day. */
    public function test_a_week_ends_on_its_sunday_or_at_the_months_edge(): void
    {
        $this->assertSame('2026-09-13', ReconPeriods::weekEnd(Carbon::parse('2026-09-07'))->toDateString());
        $this->assertSame('2026-09-13', ReconPeriods::weekEnd(Carbon::parse('2026-09-10'))->toDateString());
        $this->assertSame('2026-09-06', ReconPeriods::weekEnd(Carbon::parse('2026-09-01'))->toDateString(), 'Tuesday 1 to Sunday 6.');
        $this->assertSame('2026-09-30', ReconPeriods::weekEnd(Carbon::parse('2026-09-28'))->toDateString(), 'Cut at the month.');
    }
}
