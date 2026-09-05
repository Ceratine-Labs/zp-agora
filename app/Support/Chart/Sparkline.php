<?php

namespace App\Support\Chart;

/**
 * The geometry of a sparkline, worked out on the server.
 *
 * `<x-sparkline>` is inline SVG with no library, and it is inline SVG on
 * purpose: it lives inside a KPI card and, one day, inside a grid cell. A
 * chart library instantiated per cell would be absurd — a 150 KB download and
 * a canvas per row to draw twelve points.
 *
 * Doing it by hand means owning the four cases that break hand-rolled charts,
 * so each is handled here explicitly rather than falling out of the arithmetic:
 *
 *  - **An empty series** — nothing to draw at all. Returns null; the component
 *    renders an em dash, because a missing trend is missing, not flat.
 *  - **A single point** — `(n - 1)` is the divisor for the x step, so the naive
 *    version divides by zero. One point is drawn as a dot in the middle.
 *  - **A flat series** — `max - min` is the divisor for y, and the naive
 *    version divides by zero too. Worse, the usual guard (`range || 1`) puts
 *    the line hard against the BOTTOM of the box, which reads as a collapse to
 *    zero. A flat series is drawn through the vertical centre.
 *  - **A series containing a negative** — falls out correctly from min/max, but
 *    only if the baseline is the series minimum rather than zero, which is what
 *    this does. A sparkline is a shape, not a bar chart; it has no zero line.
 *
 * A gap (null) in the series breaks the line rather than interpolating across
 * it, because a straight segment over a missing day is an invented reading. The
 * area wash is only drawn when the series has no gaps — an area under a broken
 * line has no honest boundary.
 */
final class Sparkline
{
    /**
     * @param  list<mixed>  $values  A reading per slot; a non-number is a gap.
     * @return array{
     *     line: string,
     *     area: string|null,
     *     last: array{x: float, y: float},
     *     flat: bool,
     *     slots: int,
     *     readings: int,
     *     min: float,
     *     max: float
     * }|null
     */
    public static function draw(array $values, float $width = 240.0, float $height = 30.0, float $pad = 2.0): ?array
    {
        $slots = count($values);

        /** @var array<int, float> $readings index => value, gaps absent */
        $readings = [];
        foreach (array_values($values) as $index => $value) {
            if (is_numeric($value)) {
                $readings[$index] = (float) $value;
            }
        }

        if ($readings === []) {
            return null;
        }

        $min = min($readings);
        $max = max($readings);
        $flat = ($max - $min) <= 0.0;

        // The single-point case: (n - 1) would be zero, so pin x to the middle.
        $span = $slots > 1 ? ($width - 2 * $pad) / ($slots - 1) : 0.0;
        $x = fn (int $i): float => $slots > 1 ? $pad + $i * $span : $width / 2;

        // The flat case: a range of zero has no scale, so sit on the centre
        // line. The usual `range || 1` guard would push it to the floor and
        // read as a fall to nothing.
        $inner = $height - 2 * $pad;
        $y = fn (float $v): float => $flat
            ? $height / 2
            : $height - $pad - (($v - $min) / ($max - $min)) * $inner;

        // A subpath per unbroken run. A reading that follows a gap starts a new
        // "M", so no segment is ever drawn across a day nobody read.
        $line = '';
        $previous = null;
        foreach (array_keys($readings) as $index) {
            $continues = $previous !== null && $index === $previous + 1;
            $line .= ($continues ? 'L' : 'M').self::c($x($index)).' '.self::c($y($readings[$index])).' ';
            $previous = $index;
        }

        $lastIndex = array_key_last($readings);

        return [
            'line' => trim($line),
            // No gaps → the wash has an honest left and right edge.
            'area' => count($readings) === $slots && $slots > 1
                ? trim($line).'L'.self::c($x($lastIndex)).' '.self::c($height).
                  ' L'.self::c($x((int) array_key_first($readings))).' '.self::c($height).' Z'
                : null,
            'last' => ['x' => round($x($lastIndex), 2), 'y' => round($y($readings[$lastIndex]), 2)],
            'flat' => $flat,
            'slots' => $slots,
            'readings' => count($readings),
            'min' => $min,
            'max' => $max,
        ];
    }

    /** Two decimals is a tenth of a pixel at this size, and keeps the markup short. */
    private static function c(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
