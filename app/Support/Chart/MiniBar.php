<?php

namespace App\Support\Chart;

/**
 * The geometry of an in-cell variance bar.
 *
 * `<x-mini-bar>` is the thing that sits in a grid column next to a number and
 * says, without being read, which side of budget the row is on and by roughly
 * how much. It is inline SVG with no library for the same reason as the
 * sparkline, only more so: this one appears once per ROW.
 *
 * Its zero is a real zero, unlike the sparkline's: the bar grows left or right
 * from a fixed centre tick, so two rows in the same column are comparable. That
 * only holds if every bar in the column shares one `max`, which is why `max` is
 * a required argument rather than something derived per bar — a per-row scale
 * would make the longest bar in every row look identical.
 *
 * The cases that break it:
 *
 *  - **`max` of zero or less** — no scale exists, so nothing is drawn. Deriving
 *    a scale from the value would make every bar full width.
 *  - **A null value** — a missing variance is missing. Nothing is drawn, and
 *    the component renders an em dash beside it.
 *  - **Exactly zero** — on budget. The tick alone, no bar; a 1px bar would read
 *    as a small miss.
 *  - **Beyond `max`** — clamped to the full half-width and flagged, so the
 *    component can mark it rather than silently show it as "exactly the worst
 *    in the column".
 */
final class MiniBar
{
    /**
     * @return array{
     *     zero: float,
     *     x: float,
     *     width: float,
     *     height: float,
     *     tone: string,
     *     clamped: bool
     * }|null
     */
    public static function geometry(
        mixed $value,
        mixed $max,
        float $width = 76.0,
        float $height = 12.0,
    ): ?array {
        if (! is_numeric($value) || ! is_numeric($max) || (float) $max <= 0.0) {
            return null;
        }

        $value = (float) $value;
        $max = (float) $max;

        $half = $width / 2;
        $ratio = abs($value) / $max;
        $clamped = $ratio > 1.0;
        $bar = min($ratio, 1.0) * $half;

        // A value that rounds to nothing on this scale still gets a hairline,
        // so "tiny" and "on budget" do not look the same. Exactly zero does not.
        if ($value !== 0.0) {
            $bar = max($bar, 1.0);
        }

        return [
            'zero' => round($half, 2),
            'x' => round($value < 0.0 ? $half - $bar : $half, 2),
            'width' => round($value === 0.0 ? 0.0 : $bar, 2),
            'height' => $height,
            'tone' => match (true) {
                $value > 0.0 => 'good',
                $value < 0.0 => 'crit',
                default => 'flat',
            },
            'clamped' => $clamped,
        ];
    }
}
