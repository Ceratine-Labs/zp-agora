<?php

namespace Tests\Feature\Charts;

use App\Support\Chart\Sparkline;
use Tests\TestCase;

/**
 * The sparkline is fully server-rendered SVG, which means its geometry is
 * testable exactly — the numbers in the `d` attribute are the drawing.
 *
 * The four cases here are the four that break a hand-rolled sparkline, and
 * three of them break it by dividing by zero:
 *
 *   empty        nothing to draw at all
 *   single       the x step divides by (n − 1)
 *   flat         the y scale divides by (max − min)
 *   negative     only survives if the baseline is the series minimum
 *
 * A fifth is here because it is the one a real fuel dataset produces: a day
 * nobody read the meter.
 *
 * Read-only. No database, no writes, nothing to clean up.
 */
class SparklineTest extends TestCase
{
    private const W = 240.0;

    private const H = 30.0;

    // ------------------------------------------------------------ geometry

    public function test_an_empty_series_draws_nothing(): void
    {
        $this->assertNull(Sparkline::draw([]));
        // Not the same thing as a series of nulls, and it must not be:
        // a month of missing readings is still nothing to draw.
        $this->assertNull(Sparkline::draw([null, null, null]));
        $this->assertNull(Sparkline::draw(['', 'n/a']));
    }

    public function test_a_single_point_is_a_dot_in_the_middle_rather_than_a_division_by_zero(): void
    {
        $drawn = Sparkline::draw([141000], self::W, self::H);

        $this->assertNotNull($drawn);
        $this->assertSame(self::W / 2, $drawn['last']['x'], 'One reading has no left or right; it belongs in the middle.');
        $this->assertSame(self::H / 2, $drawn['last']['y']);
        $this->assertSame('M120 15', $drawn['line']);
        $this->assertTrue($drawn['flat']);
        // One point has no span, so there is no area to wash.
        $this->assertNull($drawn['area']);
    }

    public function test_a_flat_series_runs_through_the_centre_and_not_along_the_floor(): void
    {
        $drawn = Sparkline::draw(array_fill(0, 12, 141000), self::W, self::H);

        $this->assertNotNull($drawn);
        $this->assertTrue($drawn['flat']);

        // Every y is the vertical centre. The usual `range || 1` guard puts the
        // line at h - pad, which reads as a collapse to zero — the exact
        // opposite of what a flat month means.
        preg_match_all('/[ML]([\d.]+) ([\d.]+)/', $drawn['line'], $matches);
        $this->assertCount(12, $matches[2]);
        foreach ($matches[2] as $y) {
            $this->assertSame('15', $y);
        }

        // …and it still spans the full width, so it reads as twelve days flat
        // rather than as one reading.
        $this->assertSame(2.0, (float) $matches[1][0]);
        $this->assertSame(238.0, (float) $matches[1][11]);
    }

    public function test_a_series_containing_a_negative_keeps_its_shape(): void
    {
        $drawn = Sparkline::draw([4, 9, -3, 6, -8, 2, 11, -1, 7, 3], self::W, self::H);

        $this->assertNotNull($drawn);
        $this->assertFalse($drawn['flat']);
        $this->assertSame(-8.0, $drawn['min']);
        $this->assertSame(11.0, $drawn['max']);

        preg_match_all('/[ML]([\d.]+) ([\d.-]+)/', $drawn['line'], $matches);
        $ys = array_map('floatval', $matches[2]);

        // The baseline is the series minimum, not zero: the lowest reading sits
        // on the floor and the highest on the ceiling, and nothing leaves the
        // box. A sparkline is a shape; it has no zero line to hang off.
        $this->assertSame(self::H - 2.0, max($ys), 'The minimum reading should sit on the floor of the box.');
        $this->assertSame(2.0, min($ys), 'The maximum reading should sit on the ceiling of the box.');
        $this->assertGreaterThanOrEqual(0.0, min($ys));
        $this->assertLessThanOrEqual(self::H, max($ys));
    }

    public function test_a_gap_breaks_the_line_instead_of_inventing_a_reading_across_it(): void
    {
        $drawn = Sparkline::draw([120, 131, 128, null, null, 139, 144], self::W, self::H);

        $this->assertNotNull($drawn);
        $this->assertSame(7, $drawn['slots']);
        $this->assertSame(5, $drawn['readings']);

        // Two subpaths — one before the gap and one after — so no segment is
        // drawn over the days nobody read.
        $this->assertSame(2, substr_count($drawn['line'], 'M'));

        // And no wash: an area under a broken line has no honest boundary.
        $this->assertNull($drawn['area']);
    }

    public function test_a_complete_series_gets_its_area_wash_and_the_x_positions_are_evenly_spread(): void
    {
        $drawn = Sparkline::draw([1, 2, 3, 4, 5], 100.0, 20.0);

        $this->assertNotNull($drawn);
        $this->assertNotNull($drawn['area']);
        $this->assertStringEndsWith('Z', $drawn['area']);

        preg_match_all('/[ML]([\d.]+) ([\d.-]+)/', $drawn['line'], $matches);
        $this->assertSame(['2', '26', '50', '74', '98'], $matches[1]);
    }

    // ----------------------------------------------------------- rendering

    public function test_the_component_renders_svg_with_no_library_and_no_colour_of_its_own(): void
    {
        $svg = (string) $this->blade(
            '<x-sparkline :values="$values" tone="s1" label="Litres per day" />',
            ['values' => [4, 9, 3, 6, 8, 2, 11]]
        );

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('class="spark spark-s1"', $svg);
        $this->assertStringContainsString('aria-label="Litres per day"', $svg);
        $this->assertStringContainsString('vector-effect="non-scaling-stroke"', $svg);
        $this->assertStringContainsString('spark-area', $svg);
        $this->assertStringContainsString('spark-end', $svg);

        // The rule this whole lane turns on: the mark carries no colour value.
        // Its colour comes from `currentColor` and the .spark-s1 class, both of
        // which resolve to a token in CSS.
        $this->assertDoesNotMatchRegularExpression('/#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})\b/i', $svg);
        $this->assertStringNotContainsString('rgb(', $svg);
    }

    public function test_the_component_renders_an_em_dash_for_a_missing_trend_and_not_a_flat_line(): void
    {
        $rendered = (string) $this->blade('<x-sparkline :values="$values" />', ['values' => []]);

        $this->assertStringContainsString('—', $rendered);
        $this->assertStringNotContainsString('<svg', $rendered);
        $this->assertStringContainsString('spark-none', $rendered);
    }

    public function test_an_unlabelled_sparkline_is_hidden_from_a_screen_reader_rather_than_announced_as_nothing(): void
    {
        // Inside a KPI card the figure beside it already says what it is; an
        // unlabelled "graphic" announced with no name is noise.
        $svg = (string) $this->blade('<x-sparkline :values="$values" />', ['values' => [1, 2, 3]]);

        $this->assertStringContainsString('aria-hidden="true"', $svg);
        $this->assertStringContainsString('role="presentation"', $svg);
    }

    public function test_the_fill_can_be_switched_off_for_a_dense_row(): void
    {
        $svg = (string) $this->blade('<x-sparkline :values="$values" :fill="false" />', ['values' => [1, 2, 3]]);

        $this->assertStringNotContainsString('spark-area', $svg);
        $this->assertStringContainsString('spark-line', $svg);
    }
}
