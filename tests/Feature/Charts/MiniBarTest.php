<?php

namespace Tests\Feature\Charts;

use App\Support\Chart\MiniBar;
use Tests\TestCase;

/**
 * The in-cell variance bar, which appears once per grid row and therefore has
 * to be a handful of SVG elements rather than a chart.
 *
 * Its zero is a real zero — unlike the sparkline's, whose baseline is the
 * series minimum — because two rows in the same column have to be comparable.
 * That only holds if every bar shares the COLUMN's scale, which is why `max` is
 * an argument and not something derived per row. The tests below are mostly
 * about the boundaries of that scale.
 *
 * Read-only. No database, no writes.
 */
class MiniBarTest extends TestCase
{
    private const W = 76.0;

    private const H = 12.0;

    public function test_a_missing_variance_draws_nothing(): void
    {
        $this->assertNull(MiniBar::geometry(null, 100));
        $this->assertNull(MiniBar::geometry('', 100));
        $this->assertNull(MiniBar::geometry('n/a', 100));
    }

    public function test_no_scale_means_no_bar_rather_than_a_full_one(): void
    {
        // Deriving a scale from the value would make every single bar full
        // width, which is the failure mode this guard exists for.
        $this->assertNull(MiniBar::geometry(50, 0));
        $this->assertNull(MiniBar::geometry(50, -10));
        $this->assertNull(MiniBar::geometry(50, null));
    }

    public function test_a_positive_variance_grows_right_from_the_tick(): void
    {
        $bar = MiniBar::geometry(50, 100, self::W, self::H);

        $this->assertNotNull($bar);
        $this->assertSame(38.0, $bar['zero']);
        $this->assertSame(38.0, $bar['x'], 'It starts at the tick.');
        $this->assertSame(19.0, $bar['width'], 'Half of max is half of the half-width.');
        $this->assertSame('good', $bar['tone']);
        $this->assertFalse($bar['clamped']);
    }

    public function test_a_negative_variance_grows_left_and_ends_on_the_tick(): void
    {
        $bar = MiniBar::geometry(-50, 100, self::W, self::H);

        $this->assertNotNull($bar);
        $this->assertSame(19.0, $bar['x']);
        $this->assertSame(19.0, $bar['width']);
        $this->assertSame(38.0, $bar['x'] + $bar['width'], 'A negative bar finishes exactly on the tick.');
        $this->assertSame('crit', $bar['tone']);
    }

    public function test_the_two_sides_are_the_same_length_for_the_same_magnitude(): void
    {
        // The whole reason a shared max exists: +2 and −2 have to look equal.
        $over = MiniBar::geometry(2, 100, self::W, self::H);
        $under = MiniBar::geometry(-2, 100, self::W, self::H);

        $this->assertNotNull($over);
        $this->assertNotNull($under);
        $this->assertSame($over['width'], $under['width']);
    }

    public function test_exactly_on_budget_draws_the_tick_and_no_bar(): void
    {
        $bar = MiniBar::geometry(0, 100, self::W, self::H);

        $this->assertNotNull($bar);
        $this->assertSame(0.0, $bar['width'], 'A hairline here would read as a small miss.');
        $this->assertSame('flat', $bar['tone']);
    }

    public function test_a_variance_too_small_to_see_still_gets_a_hairline(): void
    {
        // "Tiny" and "on budget" are different answers and must look different.
        $bar = MiniBar::geometry(0.0001, 100, self::W, self::H);

        $this->assertNotNull($bar);
        $this->assertSame(1.0, $bar['width']);
        $this->assertSame('good', $bar['tone']);
    }

    public function test_a_value_past_the_scale_is_clamped_and_says_so(): void
    {
        $bar = MiniBar::geometry(-300, 100, self::W, self::H);

        $this->assertNotNull($bar);
        $this->assertTrue($bar['clamped']);
        $this->assertSame(38.0, $bar['width'], 'It fills its half and no more — the bar never leaves the cell.');
        $this->assertSame(0.0, $bar['x']);

        // The row that is exactly at the column max fills its half too, but is
        // NOT clamped — so the mark distinguishes "the worst in this column"
        // from "off the end of a scale someone else set".
        $atMax = MiniBar::geometry(-100, 100, self::W, self::H);
        $this->assertNotNull($atMax);
        $this->assertFalse($atMax['clamped']);
        $this->assertSame(38.0, $atMax['width']);
    }

    public function test_the_component_renders_svg_carrying_no_colour_of_its_own(): void
    {
        $svg = (string) $this->blade(
            '<x-mini-bar :value="$v" :max="$m" label="Fuel R1.2m under budget" />',
            ['v' => -1200000, 'm' => 3000000]
        );

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('class="mini-bar tone-crit"', $svg);
        $this->assertStringContainsString('mini-zero', $svg);
        $this->assertStringContainsString('mini-fill', $svg);
        $this->assertStringContainsString('aria-label="Fuel R1.2m under budget"', $svg);

        // Semantic tone is a class, resolved to --crit in CSS. Never a value.
        $this->assertDoesNotMatchRegularExpression('/#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})\b/i', $svg);
    }

    public function test_the_component_renders_an_em_dash_when_there_is_no_variance_to_show(): void
    {
        $rendered = (string) $this->blade('<x-mini-bar :value="null" :max="100" />');

        $this->assertStringContainsString('—', $rendered);
        $this->assertStringNotContainsString('<svg', $rendered);
    }

    public function test_the_clamp_mark_appears_only_when_the_value_is_off_the_scale(): void
    {
        $off = (string) $this->blade('<x-mini-bar :value="-300" :max="100" />');
        $on = (string) $this->blade('<x-mini-bar :value="-50" :max="100" />');

        $this->assertStringContainsString('mini-clamp', $off);
        $this->assertStringContainsString('is-clamped', $off);
        $this->assertStringNotContainsString('mini-clamp', $on);
    }
}
