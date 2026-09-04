<?php

namespace Tests\Unit\Support;

use App\Support\Format;
use PHPUnit\Framework\TestCase;

/**
 * The PHP half of the formatting pair.
 *
 * That the JavaScript half agrees with this one is asserted separately, and
 * against the same inputs, by tests/e2e/format.spec.js — two implementations
 * can both be self-consistent and still disagree with each other, which is the
 * failure this pair is most likely to have.
 *
 * What this file covers is being right in the first place: the thresholds, the
 * separators, and the treatment of nothing.
 */
class FormatTest extends TestCase
{
    public function test_a_number_groups_with_a_space_and_points_with_a_stop(): void
    {
        // Not the en-ZA locale, deliberately. PHP's intl renders this as
        // "1,234,567.89" and JavaScript's as "1 234 567,89"; Agora states the
        // format so the two agree.
        $this->assertSame('1 234 567.89', Format::n(1234567.891, 2));
        $this->assertSame('-42.5', Format::n(-42.5, 1));
        $this->assertSame('0', Format::n(0));
    }

    public function test_the_grouping_space_is_an_ordinary_space(): void
    {
        // A non-breaking space would stop a figure copied out of a grid from
        // pasting into a spreadsheet as a number.
        $this->assertStringContainsString(' ', Format::n(1000));
        $this->assertStringNotContainsString("\u{00A0}", Format::n(1000));
    }

    public function test_money_carries_its_sign_before_the_symbol(): void
    {
        $this->assertSame('R1 234.50', Format::r(1234.5));
        $this->assertSame('-R1 234.50', Format::r(-1234.5));
        $this->assertSame('R0.00', Format::r(0));
    }

    /**
     * The thresholds are exact, and tested on both sides of each one — a tile
     * that flips to "k" a rand early is the kind of thing nobody reports and
     * everybody notices.
     */
    public function test_short_money_switches_units_at_the_right_places(): void
    {
        $this->assertSame('R999', Format::rk(999));
        $this->assertSame('R1k', Format::rk(1000));
        $this->assertSame('R1 000k', Format::rk(999999));
        $this->assertSame('R1.00m', Format::rk(1000000));
        $this->assertSame('R2.21m', Format::rk(2208437));
        $this->assertSame('R1.50bn', Format::rk(1500000000));
        $this->assertSame('-R2.21m', Format::rk(-2208437));
    }

    public function test_volumes_read_in_the_units_the_forecourt_uses(): void
    {
        $this->assertSame('1.24m L', Format::lk(1240000));
        $this->assertSame('847k L', Format::lk(847300));
        $this->assertSame('312 L', Format::lk(312));
        // Litres in full go to the millilitre, because a dip does.
        $this->assertSame('12 480.500 L', Format::litres(12480.5));
    }

    public function test_rates_keep_four_places_because_margin_is_quoted_in_cents(): void
    {
        $this->assertSame('175.2500 c/ℓ', Format::cpl(175.25));
        $this->assertSame('12.4%', Format::pct(12.44));
        $this->assertSame('-3.06%', Format::pct(-3.06, 2));
    }

    public function test_a_movement_under_a_twentieth_of_a_point_reads_as_flat(): void
    {
        // A rounding wobble must not present itself as a trend.
        $this->assertSame('0.0%', Format::delta(0.02));
        $this->assertSame('flat', Format::deltaTone(0.02));

        $this->assertSame('▲ +4.2%', Format::delta(4.23));
        $this->assertSame('▼ -4.2%', Format::delta(-4.23));
    }

    public function test_which_direction_is_good_is_the_callers_decision(): void
    {
        // A cost going up is not good news, and only the caller knows it is
        // looking at a cost.
        $this->assertSame('up', Format::deltaTone(4.2));
        $this->assertSame('dn', Format::deltaTone(4.2, invert: true));
        $this->assertSame('dn', Format::deltaTone(-4.2));
        $this->assertSame('up', Format::deltaTone(-4.2, invert: true));
    }

    public function test_nothing_is_rendered_as_nothing(): void
    {
        // Rendering a missing figure as R0.00 states something untrue.
        foreach ([null, ''] as $nothing) {
            $this->assertSame('—', Format::r($nothing));
            $this->assertSame('—', Format::n($nothing));
            $this->assertSame('—', Format::rk($nothing));
            $this->assertSame('—', Format::pct($nothing));
            $this->assertSame('—', Format::delta($nothing));
        }
    }

    public function test_a_variance_against_no_base_is_unknown_not_zero(): void
    {
        $this->assertNull(Format::variance(100, 0));
        $this->assertNull(Format::variance(100, null));
        $this->assertEqualsWithDelta(25.0, Format::variance(125, 100), 0.0001);
        $this->assertEqualsWithDelta(-20.0, Format::variance(80, 100), 0.0001);
    }
}
