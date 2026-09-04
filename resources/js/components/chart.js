/**
 * Charts.
 *
 * ApexCharts is ~150 KB and only a handful of screens draw anything, so it is
 * fetched on demand — when a `[data-chart]` element is actually present.
 *
 * Colours come from the theme tokens read off the document at draw time, not
 * from a palette baked into the chart config: a chart that keeps its light
 * colours after the viewer switches to dark is the most common way a themed
 * system gives itself away.
 */
export function tokens() {
    const style = getComputedStyle(document.documentElement);
    const read = (name) => style.getPropertyValue(name).trim();

    return {
        series: ['--s1', '--s2', '--s3', '--s4', '--s5'].map(read),
        ink: read('--ink'),
        muted: read('--muted'),
        grid: read('--grid'),
        axis: read('--axis'),
        good: read('--good'),
        warn: read('--warn'),
        crit: read('--crit'),
        font: read('--font-text') || 'sans-serif',
    };
}

/** The defaults every Agora chart starts from. */
export function baseOptions() {
    const t = tokens();

    return {
        chart: {
            fontFamily: t.font,
            foreColor: t.muted,
            toolbar: { show: false },
            animations: { enabled: !window.matchMedia('(prefers-reduced-motion: reduce)').matches },
        },
        colors: t.series,
        grid: { borderColor: t.grid, strokeDashArray: 3 },
        dataLabels: { enabled: false },
        legend: { labels: { colors: t.muted } },
        tooltip: { theme: document.documentElement.getAttribute('data-theme') || 'light' },
    };
}

/**
 * Draw every `[data-chart]` on the page.
 *
 * The element carries its options as JSON in `data-chart`; the controller
 * supplies them, because a component never queries for its own data.
 */
export default async function charts(root = document) {
    const holders = root.querySelectorAll('[data-chart]:not([data-chart-ready])');
    if (holders.length === 0) return;

    const { default: ApexCharts } = await import('apexcharts');

    holders.forEach((holder) => {
        holder.setAttribute('data-chart-ready', '');

        let options;
        try {
            options = JSON.parse(holder.dataset.chart);
        } catch {
            // A malformed chart is a data problem worth seeing, not a blank box.
            holder.textContent = 'This chart could not be drawn.';

            return;
        }

        const base = baseOptions();
        new ApexCharts(holder, {
            ...base,
            ...options,
            chart: { ...base.chart, ...(options.chart || {}) },
        }).render();
    });
}
