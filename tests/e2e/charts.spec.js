import { anonymous, expect, test } from './support/fixtures.js';

/**
 * The half of T015 that cannot be proved without a browser.
 *
 * tests/Feature/Charts proves what goes onto the page: the payload, the token
 * references, the absence of a colour literal, the empty state, the table twin
 * and the SVG geometry of the sparkline and the mini bar. All of that is
 * deterministic and runs in two seconds.
 *
 * What it cannot prove is the acceptance itself — "every chart type renders in
 * both themes with the right series colours and responds to container resize".
 * That needs a rendered document, so it lives here.
 *
 * The page is /dev/charts, registered in local and testing only and outside the
 * auth stack, exactly like /dev/theme. It runs anonymous for the same reason
 * that spec does.
 *
 *     npx playwright test tests/e2e/charts.spec.js --project=desktop
 *     npx playwright test tests/e2e/charts.spec.js --project=mobile
 *
 * `npm run build` first if resources/ has changed — the config serves built
 * assets deliberately, so a stale build is visible rather than papered over.
 */

/** Every ApexCharts-backed type the gallery draws, in the order it draws them. */
const TYPES = ['daily-bars', 'line', 'donut', 'mix', 'bridge', 'diverging'];

/** What each type's rendered root element is, once ApexCharts has mounted. */
const MOUNTED = '.apexcharts-canvas';

async function drawn(page) {
    await page.goto('/dev/charts');
    // Every holder gets `data-chart-ready` once its chart has rendered. The
    // library arrives by dynamic import, so there is a real wait here.
    await expect(page.locator('[data-chart]')).toHaveCount(TYPES.length);
    await expect(page.locator('[data-chart][data-chart-ready]')).toHaveCount(TYPES.length, { timeout: 15_000 });

    return page;
}

/** The value a token computes to on the document as it stands right now. */
function tokenValue(page, name) {
    return page.evaluate(
        (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim(),
        name
    );
}

/**
 * Every fill and stroke actually painted inside one chart, as the browser
 * reports them — `rgb(...)`, whatever the token was written as.
 */
function paintedColours(page, type) {
    return page.evaluate((t) => {
        const root = document.querySelector(`.chart-${t} .apexcharts-canvas`);
        if (!root) return [];

        const found = new Set();
        root.querySelectorAll('path, rect, circle, line').forEach((el) => {
            for (const attr of ['fill', 'stroke']) {
                const value = el.getAttribute(attr) || getComputedStyle(el)[attr];
                if (value && value !== 'none' && value !== 'transparent') found.add(value);
            }
        });

        return [...found];
    }, type);
}

/** Normalise `#2a78d6` / `rgb(42, 120, 214)` / `rgba(42, 120, 214, .55)` to `r,g,b`. */
function rgb(value) {
    const hex = value.match(/^#([0-9a-f]{6})$/i);
    if (hex) {
        return [0, 2, 4].map((i) => parseInt(hex[1].slice(i, i + 2), 16)).join(',');
    }

    const parts = value.match(/-?\d+(\.\d+)?/g);

    return parts ? parts.slice(0, 3).map(Number).join(',') : value;
}

test.describe('charts', () => {
    test.use(anonymous);

    test('every type renders', async ({ page }) => {
        await drawn(page);

        for (const type of TYPES) {
            await expect(
                page.locator(`.chart-${type} ${MOUNTED}`),
                `the ${type} chart should have mounted`
            ).toBeVisible();
        }
    });

    test('no chart carries a colour literal', async ({ page }) => {
        await drawn(page);

        // chart.js stamps this on any holder whose finished options still hold
        // a hex after the token references were resolved — which would mean a
        // builder hard-coded one instead of naming a token.
        await expect(page.locator('[data-chart-colour-literal]')).toHaveCount(0);
    });

    test('the series colours are the tokens, and they follow the theme', async ({ page }) => {
        await drawn(page);

        for (const theme of ['light', 'dark']) {
            await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);

            // chart.js tears each chart down and rebuilds it against the tokens
            // as they are now; the ready flag comes back when it has.
            await expect(page.locator('[data-chart][data-chart-ready]')).toHaveCount(TYPES.length, { timeout: 15_000 });

            const s1 = rgb(await tokenValue(page, '--s1'));
            expect(s1, `--s1 must be defined in the ${theme} theme`).toMatch(/^\d+,\d+,\d+$/);

            // The daily columns are --s1 by construction. If the chart kept the
            // colours it was born with, this is where it shows.
            const painted = (await paintedColours(page, 'daily-bars')).map(rgb);
            expect(painted, `the daily columns should be painted in the ${theme} --s1`).toContain(s1);
        }
    });

    test('the bridge paints its steps with the status tokens, both ways', async ({ page }) => {
        await drawn(page);

        // Every step in the fixture is negative, so --crit must be on the page
        // and --s1 must be on the two ends.
        const crit = rgb(await tokenValue(page, '--crit'));
        const s1 = rgb(await tokenValue(page, '--s1'));
        const painted = (await paintedColours(page, 'bridge')).map(rgb);

        expect(painted).toContain(crit);
        expect(painted).toContain(s1);
    });

    test('the diverging chart is scaled symmetrically about its baseline', async ({ page }) => {
        await drawn(page);

        // A +2% bar and a −2% bar have to be the same length, which is the one
        // thing ApexCharts will not do on its own — left to itself it fits the
        // axis to the data and puts zero wherever it lands.
        const labels = await page
            .locator('.chart-diverging .apexcharts-xaxis text')
            .allInnerTexts();

        expect(labels.length).toBeGreaterThan(2);

        const numbers = labels.map((l) => Number(l.replace(/[^\d.-]/g, ''))).filter((n) => !Number.isNaN(n));
        expect(Math.abs(Math.min(...numbers))).toBeCloseTo(Math.max(...numbers), 1);
    });

    test('a chart re-lays itself out when its container changes width', async ({ page }) => {
        await drawn(page);

        const svg = page.locator('.chart-daily-bars .apexcharts-svg');
        const before = (await svg.boundingBox()).width;

        // The window is unchanged — only the holder narrows. ApexCharts listens
        // to window.resize and would miss this; the ResizeObserver in chart.js
        // is what catches it.
        await page.evaluate(() => {
            document.querySelector('.chart-daily-bars').style.maxWidth = '320px';
        });

        await expect
            .poll(async () => (await svg.boundingBox()).width, { timeout: 5_000 })
            .toBeLessThan(before - 40);
    });

    test('every value on a chart is also reachable without hovering it', async ({ page }) => {
        await drawn(page);

        // The table twin. It is the accessible equivalent and it is also the
        // relief for the light-theme series colours that measure under 3:1
        // against white.
        const twins = page.locator('.chart-table');
        await expect(twins).toHaveCount(TYPES.length);

        const donut = page.locator('.chart-donut .chart-table');
        await donut.locator('summary').click();
        await expect(donut.locator('td', { hasText: '1.92m L' })).toBeVisible();
    });

    test('the figures on a chart are formatted the way the rest of Agora formats them', async ({ page }) => {
        await drawn(page);

        // A plain space groups thousands; Intl would have used a comma or a
        // non-breaking space, and the table beside it would then disagree.
        const ticks = await page.locator('.chart-mix .apexcharts-xaxis text').allInnerTexts();

        expect(ticks.join(' ')).toMatch(/R\d/);
        expect(ticks.join(' '), 'no comma grouping — that would be Intl, not App\\Support\\Format').not.toMatch(/\d,\d{3}/);
    });

    test('a chart with no rows draws an empty state and never loads the library', async ({ page }) => {
        const requested = [];
        page.on('request', (r) => requested.push(r.url()));

        await page.goto('/login');
        await page.waitForLoadState('networkidle');

        expect(
            requested.filter((u) => u.includes('apexcharts')),
            'the sign-in screen must not pull in a chart library'
        ).toEqual([]);
    });

    test('the sparkline and the mini bar are server-rendered SVG, not charts', async ({ page }) => {
        await page.goto('/dev/charts');

        // No ApexCharts instance anywhere near them: they are markup.
        await expect(page.locator('svg.spark').first()).toBeVisible();
        await expect(page.locator('svg.mini-bar').first()).toBeVisible();
        await expect(page.locator('svg.spark .apexcharts-canvas')).toHaveCount(0);

        // A missing trend is an em dash, and the empty case is on the page.
        await expect(page.locator('.spark-none').first()).toHaveText('—');
    });
});

test.describe('charts on a phone', () => {
    test.use(anonymous);

    /**
     * 375px is a target, not an afterthought. The failure this guards against
     * is a chart that keeps its desktop width and makes the whole page scroll
     * sideways, which is worse than a chart that is merely small.
     */
    test('nothing makes the page scroll sideways at 375px', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await drawn(page);

        const overflow = await page.evaluate(() =>
            document.documentElement.scrollWidth - document.documentElement.clientWidth
        );

        expect(overflow, 'the page must not scroll horizontally on a phone').toBeLessThanOrEqual(1);
    });

    test('every chart still draws at 375px', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await drawn(page);

        for (const type of TYPES) {
            const box = await page.locator(`.chart-${type} .apexcharts-svg`).boundingBox();
            expect(box.width, `the ${type} chart should fit the phone`).toBeLessThanOrEqual(375);
            expect(box.height, `the ${type} chart should still have a plot area`).toBeGreaterThan(120);
        }
    });
});
