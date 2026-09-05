/**
 * Charts.
 *
 * ApexCharts is ~150 KB and only a handful of screens draw anything, so it is
 * fetched on demand — when a `[data-chart]` element is actually present. The
 * sign-in screen never touches it, and neither does a page whose only chart has
 * no rows, because `<x-chart>` emits an empty state and no holder at all.
 *
 * ── How a colour gets into a chart ───────────────────────────────────────────
 *
 * It does not. Not as a value, anywhere, in this file or in the payload the
 * server sends. Every colour is written as a TOKEN REFERENCE — the string
 * `"token:--s1"`, or `"token:--s1@0.55"` for a translucent variant — and
 * {@link resolveTokens} walks the whole options tree just before the chart is
 * constructed, replacing each reference with what that custom property computes
 * to on this document right now.
 *
 * Doing it as a string substitution over the finished tree, rather than at each
 * site that needs a colour, is deliberate: it means a future chart type cannot
 * quietly hard-code one, and {@link findColourLiterals} can then assert that
 * nothing did. `App\Support\Chart\ChartSpec` runs the same check in PHP and
 * throws, so the rule is enforced on both sides of the wire.
 *
 * ── Following the theme ──────────────────────────────────────────────────────
 *
 * Resolving at draw time is only half of it. A chart drawn in light and then
 * looked at in dark holds the colours it was born with — ApexCharts has no idea
 * the tokens moved. So each chart is torn down and rebuilt when the theme
 * changes: a MutationObserver on <html>'s `data-theme` for an explicit choice,
 * and a `prefers-color-scheme` listener for the "follow the system" default,
 * which stamps no attribute at all and would otherwise go unnoticed.
 *
 * Rebuilt rather than patched through `updateOptions`, because a waterfall's
 * semantic fills, an annotation's border, a per-point fill and the tooltip
 * theme all live in different corners of the option tree, and a partial merge
 * that misses one leaves a chart that is half in the other theme.
 *
 * ── Numbers ──────────────────────────────────────────────────────────────────
 *
 * Every figure on a chart — axis tick, tooltip, data label, donut centre — goes
 * through resources/js/format.js, which is the twin of App\Support\Format.
 * `Intl` is banned; the reason is written out in format.js and it is exactly
 * this case, a chart label sitting on screen next to a Blade-rendered table.
 * The payload names a format ("Lk", "cpl", …) and this file looks it up, so a
 * screen can pick a format but cannot invent one.
 */
import * as fmt from '../format';

/** The formats a chart may ask for. Anything else is a typo, and it is loud. */
const FORMATTERS = {
    n: fmt.n, R: fmt.R, Rk: fmt.Rk, Lk: fmt.Lk,
    litres: fmt.litres, pct: fmt.pct, cpl: fmt.cpl, delta: fmt.delta,
};

/** `#abc`, `#aabbcc`, `#aabbccdd` — what a smuggled colour looks like. */
const COLOUR_LITERAL = /#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})\b/i;

/** `token:--s1` or `token:--s1@0.55` */
const TOKEN_REFERENCE = /^token:(--[a-z0-9-]+)(?:@([01](?:\.\d+)?))?$/i;

/** Live charts, so a theme change can find and rebuild them. */
const drawn = new Map();

/* ------------------------------------------------------------------ tokens */

/** Read one custom property off the document as it is computed right now. */
function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

/**
 * The theme, as the chart layer needs it.
 *
 * Exported because it is genuinely useful to a screen that draws its own thing,
 * and because a screen reaching for `getComputedStyle` itself is how the next
 * hard-coded palette gets in.
 */
export function tokens() {
    const style = getComputedStyle(document.documentElement);
    const read = (name) => style.getPropertyValue(name).trim();

    return {
        series: ['--s1', '--s2', '--s3', '--s4', '--s5'].map(read),
        ink: read('--ink'),
        ink2: read('--ink-2'),
        muted: read('--muted'),
        surface: read('--surface'),
        line: read('--line'),
        grid: read('--grid'),
        axis: read('--axis'),
        good: read('--good'),
        warn: read('--warn'),
        crit: read('--crit'),
        font: read('--font-text') || 'sans-serif',
    };
}

/**
 * A colour with an alpha, derived from whatever the token currently resolves to.
 *
 * Needed because ApexCharts wants one colour string per mark and has no
 * per-point opacity; the weekend columns on a daily chart are the same token at
 * a lower alpha, not a second colour. Deriving it here rather than adding a
 * `--s1-quiet` token keeps the palette to the five the design defines.
 */
function withAlpha(value, alpha) {
    if (!value) return value;

    const hex = value.match(/^#([0-9a-f]{3,8})$/i);
    if (hex) {
        const h = hex[1].length < 6 ? hex[1].split('').map((c) => c + c).join('') : hex[1];
        const [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));

        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    const rgb = value.match(/^rgba?\(([^)]+)\)$/i);
    if (rgb) {
        const [r, g, b] = rgb[1].split(/[\s,/]+/).filter(Boolean).slice(0, 3);

        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    // A named colour or something exotic. Better a solid mark than none.
    return value;
}

/**
 * Replace every token reference in an option tree with a live colour.
 *
 * Walks arrays and plain objects; leaves functions alone, since the formatters
 * are attached after this runs.
 */
export function resolveTokens(value) {
    if (typeof value === 'string') {
        const match = value.match(TOKEN_REFERENCE);
        if (!match) return value;

        const resolved = token(match[1]);

        return match[2] === undefined ? resolved : withAlpha(resolved, Number(match[2]));
    }

    if (Array.isArray(value)) return value.map(resolveTokens);

    if (value && typeof value === 'object' && value.constructor === Object) {
        return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, resolveTokens(v)]));
    }

    return value;
}

/**
 * Every path in an option tree that holds a colour literal.
 *
 * The enforcement half of "no hex in a component", on the browser side. PHP
 * throws on the same pattern before the payload is serialised; this catches
 * anything a JS builder in this file might add afterwards, and the paths it
 * returns are stamped onto the holder so a browser test can assert on them
 * without importing anything.
 */
export function findColourLiterals(value, path = '') {
    if (typeof value === 'string') {
        return COLOUR_LITERAL.test(value) ? [`${path}=${value}`] : [];
    }

    if (Array.isArray(value)) {
        return value.flatMap((v, i) => findColourLiterals(v, `${path}[${i}]`));
    }

    if (value && typeof value === 'object' && value.constructor === Object) {
        return Object.entries(value).flatMap(([k, v]) => findColourLiterals(v, path ? `${path}.${k}` : k));
    }

    return [];
}

/* ------------------------------------------------------------- formatting */

/**
 * A formatter from the payload's `["Lk", 2]` shape.
 *
 * An unknown name is a bug in a screen, not a reason to print a raw float onto
 * a customer's chart, so it says so and falls back to the plain number format.
 */
function formatter(spec) {
    const [name, dp] = Array.isArray(spec) ? spec : [spec, null];
    const fn = FORMATTERS[name];

    if (!fn) {
        console.error(`Unknown chart number format "${name}". Known: ${Object.keys(FORMATTERS).join(', ')}.`);

        return (v) => fmt.n(v);
    }

    return dp === null || dp === undefined ? (v) => fn(v) : (v) => fn(v, dp);
}

/* --------------------------------------------------------------- defaults */

/**
 * What every Agora chart starts from.
 *
 * The chrome is deliberately recessive and the gridlines are SOLID hairlines.
 * They were dashed here until this rewrite; a dashed grid reads as a projection
 * or a threshold when it is only a grid, and the one dashed line on an Agora
 * chart should be the budget reference, which means something.
 */
export function baseOptions() {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    return {
        chart: {
            // Not a colour, so it is read rather than referenced.
            fontFamily: tokens().font,
            foreColor: 'token:--muted',
            toolbar: { show: false },
            animations: { enabled: !reduced },
            parentHeightOffset: 0,
        },
        colors: ['token:--s1', 'token:--s2', 'token:--s3', 'token:--s4', 'token:--s5'],
        grid: {
            borderColor: 'token:--grid',
            strokeDashArray: 0,
            padding: { left: 8, right: 8, top: 0, bottom: 0 },
        },
        dataLabels: { enabled: false },
        stroke: { width: 2, lineCap: 'round' },
        markers: { size: 0, strokeWidth: 2, strokeColors: 'token:--surface', hover: { sizeOffset: 4 } },
        legend: {
            position: 'top',
            horizontalAlign: 'left',
            fontSize: '11.5px',
            markers: { size: 6 },
            itemMargin: { horizontal: 10, vertical: 2 },
            labels: { colors: 'token:--ink-2' },
        },
        tooltip: { theme: isDark() ? 'dark' : 'light' },
        xaxis: {
            axisBorder: { color: 'token:--axis' },
            axisTicks: { color: 'token:--axis' },
            labels: { style: { fontSize: '10.5px' } },
        },
        yaxis: { labels: { style: { fontSize: '10.5px' } } },
        // 24px is the dataviz cap: a bar that fills its slot leaves no air, and
        // the rounded end belongs on the data end only, never at the baseline.
        plotOptions: {
            bar: { maxWidth: 24, borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'all' },
        },
        states: { hover: { filter: { type: 'lighten', value: 0.08 } } },
    };
}

function isDark() {
    const stamped = document.documentElement.getAttribute('data-theme');

    return stamped ? stamped === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
}

/* ---------------------------------------------------------------- builders */

/**
 * Daily columns with a moving average over them.
 *
 * One measure, one axis. The average rides the same scale as the bars because
 * it IS the same measure — a second axis here would be the classic dual-axis
 * lie, and the mockup does not ask for one.
 *
 * Weekends are drawn at a lower alpha of the same token rather than in a second
 * colour: they are not a second category, they are the same measure on a day
 * that trades differently, and a second hue would say otherwise.
 */
function dailyBars(spec) {
    const value = formatter(spec.format);
    const axis = formatter(spec.axisFormat);

    const columns = {
        name: spec.name,
        type: 'column',
        data: spec.rows.map((r) => ({
            x: r.label,
            y: r.value,
            fillColor: r.quiet ? 'token:--s1@0.55' : 'token:--s1',
        })),
    };

    const series = [columns];

    if (spec.average) {
        const window = spec.average;
        series.push({
            name: spec.averageName,
            type: 'line',
            data: spec.rows.map((r, i) => {
                const slice = spec.rows.slice(Math.max(0, i - window + 1), i + 1)
                    .map((s) => s.value)
                    .filter((v) => v !== null);

                return {
                    x: r.label,
                    y: slice.length ? slice.reduce((a, b) => a + b, 0) / slice.length : null,
                };
            }),
        });
    }

    return {
        chart: { type: 'line', height: spec.height, stacked: false },
        series,
        colors: ['token:--s1', 'token:--ink-2'],
        stroke: { width: [0, 2], dashArray: [0, 5], curve: 'straight', lineCap: 'round' },
        fill: { opacity: 1 },
        // The categories come off the data points' own `x`, not a parallel
        // array: the points already carry a per-point fill for the weekends,
        // and a chart that states its labels twice can state them differently.
        xaxis: {
            type: 'category',
            // A month of days will not fit as 31 labels at 375px, and Apex's
            // own rotation turns them into a fringe. Let it thin them out.
            tickAmount: Math.min(spec.rows.length, 8),
            labels: { rotate: 0, hideOverlappingLabels: true },
        },
        yaxis: { labels: { formatter: axis } },
        legend: { show: series.length > 1 },
        tooltip: {
            shared: true,
            intersect: false,
            y: { formatter: (v) => (v === null ? fmt.NOTHING : value(v)) },
        },
    };
}

/**
 * A line against a reference.
 *
 * The reference — budget margin, a threshold — is a y-axis annotation rather
 * than a second series, because it is not a reading: it does not move, it has
 * no points, and putting it in the legend as an equal would misrepresent it.
 * It is the one dashed thing on an Agora chart, and it is dashed because it
 * genuinely is a threshold.
 */
function line(spec) {
    const value = formatter(spec.format);
    const axis = formatter(spec.axisFormat);
    const labels = spec.rows[0] ? spec.rows[0].points.map((p) => p.label) : [];

    return {
        chart: { type: 'line', height: spec.height },
        series: spec.rows.map((s) => ({ name: s.name, data: s.points.map((p) => p.value) })),
        stroke: { width: 2, curve: 'straight', lineCap: 'round' },
        markers: { size: 0, strokeWidth: 2, strokeColors: 'token:--surface', hover: { size: 5 } },
        xaxis: {
            categories: labels,
            tickAmount: Math.min(labels.length, 8),
            labels: { rotate: 0, hideOverlappingLabels: true },
        },
        yaxis: { labels: { formatter: axis } },
        legend: { show: spec.rows.length > 1 },
        annotations: spec.reference ? {
            yaxis: [{
                y: spec.reference.value,
                borderColor: 'token:--s2',
                borderWidth: 2,
                strokeDashArray: 6,
                label: {
                    text: `${spec.reference.label} ${value(spec.reference.value)}`,
                    position: 'right',
                    textAnchor: 'end',
                    borderColor: 'token:--s2',
                    style: { background: 'token:--surface', color: 'token:--ink-2', fontSize: '10.5px' },
                },
            }],
        } : {},
        tooltip: { shared: true, intersect: false, y: { formatter: (v) => (v === null ? fmt.NOTHING : value(v)) } },
    };
}

/**
 * Part of a whole, at a glance.
 *
 * Capped at six slices by the dataviz rules and realistically used with two or
 * three — grade split is ULP against diesel. Past six this should be a bar, and
 * past five it would run out of series tokens.
 */
function donut(spec) {
    const value = formatter(spec.format);
    const centre = formatter(spec.centreFormat);
    const total = spec.rows.reduce((a, r) => a + (r.value ?? 0), 0);

    return {
        chart: { type: 'donut', height: spec.height },
        series: spec.rows.map((r) => r.value ?? 0),
        labels: spec.rows.map((r) => r.label),
        // A 2px gap in the surface colour separates the slices. Never a stroke
        // in a border colour — that is data-weight ink doing a spacer's job.
        stroke: { width: 2, colors: ['token:--surface'] },
        legend: { show: true, position: 'bottom', horizontalAlign: 'center' },
        plotOptions: {
            pie: {
                donut: {
                    size: '64%',
                    labels: {
                        show: true,
                        value: { fontSize: '20px', fontWeight: 600, color: 'token:--ink', formatter: (v) => centre(v) },
                        total: {
                            show: true,
                            showAlways: true,
                            label: spec.centreLabel ?? 'Total',
                            fontSize: '11px',
                            color: 'token:--muted',
                            formatter: () => centre(total),
                        },
                    },
                },
            },
        },
        dataLabels: { enabled: false },
        tooltip: { y: { formatter: (v) => value(v) } },
    };
}

/**
 * Three measures per row, read left to right: last year, budget, actual.
 *
 * Horizontal, because the categories are names of profit centres and a name
 * reads better beside its bar than rotated under it. Last year wears `--muted`
 * rather than a series token — it is context, not a third thing being compared,
 * and a de-emphasised grey says that without a caption.
 */
function mix(spec) {
    const value = formatter(spec.format);
    const axis = formatter(spec.axisFormat);

    return {
        chart: { type: 'bar', height: spec.height },
        series: spec.measures.map((measure, i) => ({
            name: measure,
            data: spec.rows.map((r) => r.values[i] ?? null),
        })),
        colors: ['token:--muted', 'token:--s4', 'token:--s1'],
        plotOptions: {
            bar: {
                horizontal: true,
                maxHeight: 12,
                borderRadius: 3,
                borderRadiusApplication: 'end',
                // The 2px surface gap between adjacent bars, as a fraction of
                // the band rather than a stroke around each one.
                barHeight: '76%',
            },
        },
        xaxis: { categories: spec.rows.map((r) => r.label), labels: { formatter: axis } },
        yaxis: { labels: { style: { fontSize: '11.5px', colors: 'token:--ink' } } },
        legend: { show: true },
        tooltip: { shared: true, intersect: false, y: { formatter: (v) => (v === null ? fmt.NOTHING : value(v)) } },
    };
}

/**
 * The bridge — budget to actual, one step at a time.
 *
 * **This is a native ApexCharts type as of v7.** The usual waterfall trick is a
 * stacked bar with a transparent base series, and that trick has two real
 * costs: the phantom series shows up in the legend and the tooltip, and a step
 * that crosses zero needs a negative base, which a stacked chart renders below
 * the axis instead of as a floating bar. `chart.type: 'waterfall'` avoids both
 * — a row carries the signed step, `isTotal` marks a bar measured from zero,
 * and the library draws the connectors between the steps that make it read as
 * one walk rather than five unrelated columns.
 *
 * The fills are semantic, not categorical: a step that helped is `--good`, one
 * that hurt is `--crit`, and the two ends are `--s1`. That is the one place a
 * status token belongs on a mark — the colour means good or bad here, it is not
 * an identity.
 *
 * The vertical axis does not start at zero. Against a R25m budget the four
 * middle steps are between 0.1% and 6% of the total and a zero-based axis draws
 * them as hairlines. `<x-chart>` puts a sentence under the chart saying so, and
 * it is not optional, because a truncated axis that does not declare itself is
 * a lie. The window is the range the running total actually occupies, with
 * headroom above and below.
 */
function bridge(spec) {
    const value = formatter(spec.format);
    const axis = formatter(spec.axisFormat);

    // The window: walk the steps, note where the running total goes.
    let running = 0;
    let low = Infinity;
    let high = -Infinity;
    spec.rows.forEach((r) => {
        running = r.total ? (r.value ?? 0) : running + (r.value ?? 0);
        low = Math.min(low, running);
        high = Math.max(high, running);
    });
    const span = Math.max(high - low, Math.abs(high) * 0.02, 1);

    return {
        chart: { type: 'waterfall', height: spec.height },
        series: [{
            name: spec.name,
            data: spec.rows.map((r) => (r.total
                ? { x: r.label, isTotal: true }
                : { x: r.label, y: r.value ?? 0 })),
        }],
        plotOptions: {
            bar: { maxWidth: 64, borderRadius: 3, borderRadiusApplication: 'end' },
            waterfall: {
                colors: {
                    positive: 'token:--good',
                    negative: 'token:--crit',
                    total: 'token:--s1',
                    subtotal: 'token:--s1',
                },
                connectors: { show: true, color: 'token:--axis', strokeWidth: 1, strokeDashArray: 3 },
            },
        },
        yaxis: {
            min: low - span * 0.55,
            max: high + span * 0.22,
            labels: { formatter: axis },
        },
        xaxis: { labels: { rotate: 0, trim: true, hideOverlappingLabels: false, style: { fontSize: '10px' } } },
        // A waterfall has five or six bars and every one of them is the story,
        // so this is the one chart where labelling every mark is right.
        dataLabels: {
            enabled: true,
            formatter: (v) => (v === null || v === undefined ? fmt.NOTHING : value(v)),
            offsetY: -18,
            style: { fontSize: '10.5px', fontWeight: 600, colors: ['token:--ink-2'] },
            background: { enabled: false },
        },
        legend: { show: false },
        tooltip: { y: { formatter: (v) => (v === null ? fmt.NOTHING : value(v)) } },
    };
}

/**
 * Everything against one baseline, both ways off a centre line.
 *
 * Not an ApexCharts type: it is a horizontal bar whose values are signed, which
 * Apex draws either side of zero on its own. What Apex does NOT do is the part
 * that makes it a diverging chart rather than a bar chart with some negatives:
 *
 *  - **The scale is forced symmetric** (`min = -max, max = +max`). Left to
 *    itself Apex fits the axis to the data, so a set running -1% to +6% puts
 *    zero a sixth of the way from the left and a -1% bar looks longer than a
 *    +1% one. Comparing the two sides is the entire point of the form.
 *  - **The one-sided case is anchored at zero instead.** When nothing crosses
 *    the line — every site over budget — a symmetric scale wastes half the
 *    width on an empty side and squashes the bars into the other half. There is
 *    nothing to diverge from, so it becomes an ordinary bar chart, and the
 *    caption still says what the baseline is.
 *
 * Colour is semantic and doubled by position: over the baseline is `--good` and
 * sits right of the line, under is `--crit` and sits left. Never colour alone.
 */
function diverging(spec) {
    const value = formatter(spec.format);
    const axis = formatter(spec.axisFormat);

    const values = spec.rows.map((r) => r.value ?? 0);
    const extent = Math.max(...values.map(Math.abs), Number.MIN_VALUE);
    const oneSided = values.every((v) => v >= 0) || values.every((v) => v <= 0);
    const allNegative = values.every((v) => v <= 0);

    return {
        chart: { type: 'bar', height: spec.height },
        series: [{ name: spec.name, data: spec.rows.map((r) => r.value) }],
        plotOptions: {
            bar: {
                horizontal: true,
                maxHeight: 16,
                barHeight: '68%',
                borderRadius: 3,
                borderRadiusApplication: 'end',
                // Declared as ranges rather than a colour per point: the rule
                // is "which side of the baseline", and saying that once beats
                // computing it row by row where a later edit can get it wrong.
                colors: {
                    ranges: [
                        { from: -Number.MAX_VALUE, to: -Number.MIN_VALUE, color: 'token:--crit' },
                        { from: 0, to: Number.MAX_VALUE, color: 'token:--good' },
                    ],
                },
            },
        },
        xaxis: {
            categories: spec.rows.map((r) => r.label),
            min: oneSided ? (allNegative ? -extent : 0) : -extent,
            max: oneSided ? (allNegative ? 0 : extent) : extent,
            labels: { formatter: axis },
        },
        yaxis: { labels: { style: { fontSize: '11.5px', colors: 'token:--ink' }, maxWidth: 160 } },
        // One series, so no legend box: the title already names what is plotted
        // and a single swatch would just restate it.
        legend: { show: false },
        dataLabels: { enabled: false },
        tooltip: { y: { formatter: (v) => (v === null ? fmt.NOTHING : value(v)) } },
    };
}

const BUILDERS = {
    'daily-bars': dailyBars,
    line,
    donut,
    mix,
    bridge,
    diverging,
};

/* ------------------------------------------------------------------ drawing */

/** Merge two option trees one level deep on the keys where Apex nests. */
function merge(base, extra) {
    const merged = { ...base, ...extra };

    for (const key of ['chart', 'grid', 'legend', 'tooltip', 'xaxis', 'yaxis', 'dataLabels', 'plotOptions', 'stroke', 'markers']) {
        if (base[key] && extra[key]) merged[key] = { ...base[key], ...extra[key] };
    }

    return merged;
}

/**
 * Build the finished ApexCharts options for one spec.
 *
 * Exported so a test — or a console — can look at exactly what a chart is about
 * to be given without having to render it.
 */
export function build(spec) {
    const builder = BUILDERS[spec.type];

    if (!builder) throw new Error(`Unknown chart type "${spec.type}".`);

    const options = merge(merge(baseOptions(), builder(spec)), spec.apex || {});

    const literals = findColourLiterals(options);

    return { options: resolveTokens(options), literals };
}

async function draw(holder, ApexCharts) {
    let spec;

    try {
        spec = JSON.parse(holder.dataset.chart);
    } catch {
        // A malformed chart is a data problem worth seeing, not a blank box.
        holder.textContent = 'This chart could not be drawn.';

        return;
    }

    const { options, literals } = build(spec);

    if (literals.length) {
        // Not fatal in the browser — the chart is still drawn, in the wrong
        // colours, which is the visible symptom. The attribute is here so
        // tests/e2e/charts.spec.js can fail on it rather than a person noticing.
        holder.setAttribute('data-chart-colour-literal', literals.join(' '));
        console.error(`Chart "${spec.type}" carries a colour literal: ${literals.join(', ')}. Charts take their colours from the theme tokens.`);
    }

    holder.textContent = '';

    const chart = new ApexCharts(holder, options);
    await chart.render();

    drawn.set(holder, chart);
    holder.setAttribute('data-chart-ready', '');
}

/**
 * Re-draw everything, because the tokens under it have changed.
 *
 * Destroy and rebuild rather than `updateOptions`: see the note at the top of
 * the file. Debounced, because a fast toggle would otherwise stack renders.
 */
let repaintTimer = null;

function repaint() {
    clearTimeout(repaintTimer);
    repaintTimer = setTimeout(async () => {
        if (drawn.size === 0) return;

        const { default: ApexCharts } = await import('apexcharts');

        for (const [holder, chart] of drawn) {
            if (!holder.isConnected) {
                drawn.delete(holder);
                continue;
            }

            chart.destroy();
            drawn.delete(holder);
            holder.removeAttribute('data-chart-ready');
            await draw(holder, ApexCharts);
        }
    }, 60);
}

let watching = false;

/**
 * Watch for the two ways the theme can move.
 *
 * An explicit choice stamps `data-theme` on <html> — theme.js does that, and it
 * fires no event, so a MutationObserver is the way to hear it without coupling
 * the two files. The default state stamps NOTHING and follows the operating
 * system, so the media query has to be listened to as well or a chart drawn at
 * dusk stays light when the machine turns dark at sunset.
 */
function watchTheme() {
    if (watching) return;
    watching = true;

    new MutationObserver((records) => {
        if (records.some((r) => r.attributeName === 'data-theme')) repaint();
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        // Only when the document is following the system. An explicit choice
        // outranks the OS and a repaint would be wasted work.
        if (!document.documentElement.getAttribute('data-theme')) repaint();
    });
}

/**
 * Redraw on a container resize, not only a window one.
 *
 * ApexCharts listens to `window.resize`; it does not hear a card that got
 * narrower because a panel opened beside it, or a `<details>` that expanded, or
 * an orientation change that left the window the same area. A ResizeObserver on
 * the holder hears all of them. `updateOptions` with nothing in it is the
 * documented way to make Apex re-measure.
 */
function watchSize(holder) {
    if (typeof ResizeObserver === 'undefined') return;

    let last = holder.clientWidth;
    let timer = null;

    new ResizeObserver(() => {
        // Sub-pixel jitter and the observer's own first callback are not resizes.
        if (Math.abs(holder.clientWidth - last) < 2) return;
        last = holder.clientWidth;

        clearTimeout(timer);
        timer = setTimeout(() => drawn.get(holder)?.updateOptions({}, false, false), 80);
    }).observe(holder);
}

/**
 * Draw every `[data-chart]` on the page.
 *
 * The element carries its spec as JSON in `data-chart`; the controller supplied
 * it, because a component never queries for its own data.
 */
export default async function charts(root = document) {
    const holders = root.querySelectorAll('[data-chart]:not([data-chart-ready])');
    if (holders.length === 0) return;

    const { default: ApexCharts } = await import('apexcharts');

    watchTheme();

    for (const holder of holders) {
        await draw(holder, ApexCharts);
        watchSize(holder);
    }
}
