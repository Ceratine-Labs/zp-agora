<?php

namespace App\Support;

/**
 * How Agora writes numbers. The PHP half of a matched pair.
 *
 * `resources/js/format.js` is the other half, and the two must agree
 * character for character: a figure in a Blade-rendered table and the same
 * figure in a chart label sit on the same screen, and a reader who spots them
 * disagreeing stops trusting both.
 *
 * The format is stated here rather than delegated to a locale, because the
 * locales do not agree. Asked for en-ZA and 1234567.891, PHP's intl returns
 * "1,234,567.89" and JavaScript's Intl returns "1 234 567,89" — different
 * group separator AND different decimal separator. Using the locale on both
 * sides would have produced exactly the inconsistency this class exists to
 * prevent, and only on screens that mix server- and client-rendered figures.
 *
 * So: a plain space groups thousands, a full stop separates decimals.
 * The space is U+0020 rather than a non-breaking space on purpose — a figure
 * copied out of a grid and pasted into Excel has to arrive as a number, and a
 * non-breaking space stops that. Numeric cells carry `white-space: nowrap` in
 * CSS instead, which solves the wrapping without touching the value.
 *
 * Every function returns an em dash for null or a non-number. A missing figure
 * is missing; rendering it as R0.00 states something untrue.
 */
class Format
{
    public const NOTHING = '—';

    /** A plain number: `1 234 567.89`. */
    public static function n(int|float|string|null $value, int $dp = 0): string
    {
        if (! self::isNumber($value)) {
            return self::NOTHING;
        }

        return number_format((float) $value, $dp, '.', ' ');
    }

    /** Money: `R1 234.50`. No space after the R — that is how the estate writes it. */
    public static function r(int|float|string|null $value, int $dp = 2): string
    {
        if (! self::isNumber($value)) {
            return self::NOTHING;
        }

        $number = self::n(abs((float) $value), $dp);

        return ((float) $value < 0 ? '-R' : 'R').$number;
    }

    /**
     * Money, shortened for a KPI tile: `R2.21m`, `R92k`, `R848`.
     *
     * Thresholds and precision match the mockup exactly (two decimals for
     * millions and billions, none for thousands), because a tile that reads
     * differently from the design is a defect even when the arithmetic is
     * right.
     */
    public static function rk(int|float|string|null $value): string
    {
        if (! self::isNumber($value)) {
            return self::NOTHING;
        }

        $number = (float) $value;
        $magnitude = abs($number);
        $sign = $number < 0 ? '-R' : 'R';

        return match (true) {
            $magnitude >= 1_000_000_000 => $sign.number_format($magnitude / 1_000_000_000, 2, '.', ' ').'bn',
            $magnitude >= 1_000_000 => $sign.number_format($magnitude / 1_000_000, 2, '.', ' ').'m',
            $magnitude >= 1_000 => $sign.number_format($magnitude / 1_000, 0, '.', ' ').'k',
            default => $sign.number_format($magnitude, 0, '.', ' '),
        };
    }

    /** Volume, shortened: `1.24m L`, `847k L`, `312 L`. */
    public static function lk(int|float|string|null $value): string
    {
        if (! self::isNumber($value)) {
            return self::NOTHING;
        }

        $magnitude = abs((float) $value);

        return match (true) {
            $magnitude >= 1_000_000 => number_format($magnitude / 1_000_000, 2, '.', ' ').'m L',
            $magnitude >= 1_000 => self::n($magnitude / 1_000, 0).'k L',
            default => self::n($magnitude).' L',
        };
    }

    /** Litres in full, to the millilitre: `12 480.500 L`. */
    public static function litres(int|float|string|null $value, int $dp = 3): string
    {
        return self::isNumber($value) ? self::n($value, $dp).' L' : self::NOTHING;
    }

    /** A percentage: `12.4%`. */
    public static function pct(int|float|string|null $value, int $dp = 1): string
    {
        return self::isNumber($value) ? self::n($value, $dp).'%' : self::NOTHING;
    }

    /** Cents per litre, to four places: `175.2500 c/ℓ`. */
    public static function cpl(int|float|string|null $value, int $dp = 4): string
    {
        return self::isNumber($value) ? self::n($value, $dp).' c/ℓ' : self::NOTHING;
    }

    /**
     * A movement, with its direction: `▲ +4.2%`.
     *
     * Anything under 0.05 reads as flat and carries no arrow, so a rounding
     * wobble does not present itself as a trend.
     */
    public static function delta(int|float|string|null $value, string $suffix = '%', int $dp = 1): string
    {
        if (! self::isNumber($value)) {
            return self::NOTHING;
        }

        $number = (float) $value;

        if (abs($number) < 0.05) {
            return self::n($number, $dp).$suffix;
        }

        $arrow = $number > 0 ? '▲' : '▼';
        $sign = $number > 0 ? '+' : '';

        return $arrow.' '.$sign.self::n($number, $dp).$suffix;
    }

    /**
     * Which way is good.
     *
     * Returned separately from the text because for a cost or a variance a
     * fall is the good news, and only the caller knows which it is looking at.
     */
    public static function deltaTone(int|float|string|null $value, bool $invert = false): string
    {
        if (! self::isNumber($value) || abs((float) $value) < 0.05) {
            return 'flat';
        }

        $up = (float) $value > 0;

        return ($invert ? ! $up : $up) ? 'up' : 'dn';
    }

    /** The change from one figure to another, as a percentage. Null when there is no base to compare to. */
    public static function variance(int|float|null $now, int|float|null $before): ?float
    {
        if (! self::isNumber($now) || ! self::isNumber($before) || (float) $before == 0.0) {
            return null;
        }

        return (((float) $now - (float) $before) / abs((float) $before)) * 100;
    }

    private static function isNumber(mixed $value): bool
    {
        return $value !== null && $value !== '' && is_numeric($value);
    }
}
