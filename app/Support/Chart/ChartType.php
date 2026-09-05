<?php

namespace App\Support\Chart;

/**
 * The chart types, as a closed set.
 *
 * An enum rather than a list of strings because the type decides four separate
 * things — the row shape, the payload's extra keys, the table twin's columns,
 * and whether a caption is compulsory — and every one of those is a `match`. A
 * string cannot make those exhaustive, so adding a seventh type would compile
 * and then quietly fall through to a default somewhere. This way it does not
 * compile until all four have been answered.
 *
 * `sparkline` and `mini-bar` are deliberately absent: they are hand-rolled SVG
 * and never reach ApexCharts.
 */
enum ChartType: string
{
    /** A day per column, with an optional moving average over it. */
    case DailyBars = 'daily-bars';

    /** One or more series over time, optionally against a reference. */
    case Line = 'line';

    /** Part of a whole, at a glance. Six slices at the very most. */
    case Donut = 'donut';

    /** Several measures per category — last year, budget, actual. */
    case Mix = 'mix';

    /** A walk from one total to another. ApexCharts 7's native waterfall. */
    case Bridge = 'bridge';

    /** Everything against one baseline, both ways off a centre line. */
    case Diverging = 'diverging';

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
