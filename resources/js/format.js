/**
 * How Agora writes numbers. The JavaScript half of a matched pair.
 *
 * `app/Support/Format.php` is the other half. The two must agree character
 * for character, because a figure in a Blade-rendered table and the same
 * figure in a chart label sit on the same screen — a reader who catches them
 * disagreeing stops trusting both.
 *
 * The format is written out here rather than handed to a locale, because the
 * locales do not agree with each other. Asked for en-ZA and 1234567.891,
 * JavaScript's Intl returns "1 234 567,89" and PHP's intl returns
 * "1,234,567.89" — a different group separator AND a different decimal
 * separator. Using the locale on both sides would have produced exactly the
 * inconsistency this file exists to prevent, and only on screens that mix
 * server- and client-rendered figures, which is the worst place to find it.
 *
 * A plain space groups thousands and a full stop separates decimals. The space
 * is U+0020, not a non-breaking space: a figure copied out of a grid has to
 * paste into Excel as a number, and a non-breaking space stops that. Numeric
 * cells get `white-space: nowrap` in CSS instead.
 *
 * tests/e2e/format.spec.js asserts these against the PHP output rendered on
 * the same page, so the pair cannot drift apart unnoticed.
 */

export const NOTHING = '—';

const isNumber = (v) => v !== null && v !== undefined && v !== '' && !Number.isNaN(Number(v));

/** Fixed decimals, then a space every three digits before the point. */
function fixed(value, dp) {
    const s = Math.abs(Number(value)).toFixed(dp);
    const [whole, fraction] = s.split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

    return fraction ? `${grouped}.${fraction}` : grouped;
}

/** A plain number: `1 234 567.89`. */
export function n(value, dp = 0) {
    if (!isNumber(value)) return NOTHING;

    return (Number(value) < 0 ? '-' : '') + fixed(value, dp);
}

/** Money: `R1 234.50`. */
export function R(value, dp = 2) {
    if (!isNumber(value)) return NOTHING;

    return (Number(value) < 0 ? '-R' : 'R') + fixed(value, dp);
}

/** Money, shortened for a KPI tile: `R2.21m`, `R92k`, `R848`. */
export function Rk(value) {
    if (!isNumber(value)) return NOTHING;

    const v = Number(value);
    const a = Math.abs(v);
    const sign = v < 0 ? '-R' : 'R';

    if (a >= 1e9) return sign + fixed(a / 1e9, 2) + 'bn';
    if (a >= 1e6) return sign + fixed(a / 1e6, 2) + 'm';
    if (a >= 1e3) return sign + fixed(a / 1e3, 0) + 'k';

    return sign + fixed(a, 0);
}

/** Volume, shortened: `1.24m L`, `847k L`, `312 L`. */
export function Lk(value) {
    if (!isNumber(value)) return NOTHING;

    const a = Math.abs(Number(value));

    if (a >= 1e6) return fixed(a / 1e6, 2) + 'm L';
    if (a >= 1e3) return fixed(a / 1e3, 0) + 'k L';

    return fixed(a, 0) + ' L';
}

/** Litres in full, to the millilitre: `12 480.500 L`. */
export function litres(value, dp = 3) {
    return isNumber(value) ? n(value, dp) + ' L' : NOTHING;
}

/** A percentage: `12.4%`. */
export function pct(value, dp = 1) {
    return isNumber(value) ? n(value, dp) + '%' : NOTHING;
}

/** Cents per litre, to four places: `175.2500 c/ℓ`. */
export function cpl(value, dp = 4) {
    return isNumber(value) ? n(value, dp) + ' c/ℓ' : NOTHING;
}

/**
 * A movement, with its direction: `▲ +4.2%`.
 *
 * Under 0.05 reads as flat and carries no arrow, so a rounding wobble does not
 * present itself as a trend.
 */
export function delta(value, suffix = '%', dp = 1) {
    if (!isNumber(value)) return NOTHING;

    const v = Number(value);
    if (Math.abs(v) < 0.05) return n(v, dp) + suffix;

    return `${v > 0 ? '▲' : '▼'} ${v > 0 ? '+' : ''}${n(v, dp)}${suffix}`;
}

/** Which way is good. Only the caller knows whether a fall is the good news. */
export function deltaTone(value, invert = false) {
    if (!isNumber(value) || Math.abs(Number(value)) < 0.05) return 'flat';

    const up = Number(value) > 0;

    return (invert ? !up : up) ? 'up' : 'dn';
}

/** The change from one figure to another, as a percentage. Null with no base. */
export function variance(now, before) {
    if (!isNumber(now) || !isNumber(before) || Number(before) === 0) return null;

    return ((Number(now) - Number(before)) / Math.abs(Number(before))) * 100;
}

export default { NOTHING, n, R, Rk, Lk, litres, pct, cpl, delta, deltaTone, variance };
