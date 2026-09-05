import { anonymous, expect, test } from './support/fixtures.js';

/**
 * The half of T013's acceptance that phpunit cannot reach.
 *
 * tests/Feature/Components/ComponentRenderTest.php already proves the markup —
 * the mockup's class names, the props contract, the empty states, a missing
 * figure as an em dash. None of that needs a browser. What does need one:
 *
 *   - **zero console errors**, which the shared `page` fixture asserts on every
 *     test in this file automatically;
 *   - **both themes**, because a token that resolves to nothing is invisible in
 *     one of them and fine in the other;
 *   - **375px**, because a component that only works at 1440 fails a stated
 *     requirement;
 *   - **the behaviour** — a tab that remembers, a disclosure that persists, a
 *     run button that disables itself. Those are the only three JavaScript
 *     modules in this task, and each is here because it does something the
 *     platform does not give away.
 *
 * NOT RUN in the lane that wrote it. Ryan is on solar and browser runs are his
 * call. Run it with:
 *
 *     npx playwright test tests/e2e/components.spec.js --project=desktop
 *     npx playwright test tests/e2e/components.spec.js --project=mobile
 *
 * `npm run build` first — the config serves built assets on purpose, so a stale
 * build is visible rather than papered over.
 *
 * The page is public in local and testing only, so this runs without a session.
 */

/** Every group heading the gallery renders, so a section that stopped rendering is caught. */
const SECTIONS = ['chrome', 'structure', 'data', 'parameters', 'queues', 'messages', 'pending'];

test.describe('the component gallery', () => {
    test.use(anonymous);

    test('renders every section with no console errors', async ({ page }) => {
        await page.goto('/dev/components');

        for (const section of SECTIONS) {
            await expect(page.locator(`#g-${section}`)).toBeVisible();
        }

        // The console assertion is in the shared fixture and runs on teardown:
        // a silent JS error is how a tab strip stops opening while every other
        // assertion still passes.
    });

    test('nothing scrolls sideways', async ({ page }) => {
        await page.goto('/dev/components');

        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth
        );
        expect(overflow, 'the body must never scroll sideways').toBeLessThanOrEqual(0);
    });

    for (const theme of ['light', 'dark']) {
        test(`every component has a real background and ink in ${theme}`, async ({ page }) => {
            await page.goto('/dev/components');
            await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);

            // A component that named no colour, or named a token that does not
            // exist in this theme, computes to transparent or to the initial
            // black. Either is invisible in one theme and fine in the other,
            // which is exactly the bug a single-theme screenshot misses.
            const offenders = await page.evaluate(() => {
                const found = [];
                const opaque = (c) => c && c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent';

                document.querySelectorAll('.card, .kpi, .chip, .ex, .proposal, .libcard, .statstrip .s, .runbar, .tabs, .sqlbox')
                    .forEach((el) => {
                        const s = getComputedStyle(el);
                        if (!opaque(s.backgroundColor) && !opaque(s.borderTopColor)) {
                            found.push(el.className + ' has no background and no border');
                        }
                    });

                return found.slice(0, 10);
            });

            expect(offenders).toEqual([]);
        });
    }

    test('the theme toggle flips the whole gallery', async ({ page }) => {
        await page.goto('/dev/components');

        const before = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
        await page.locator('#themebtn').click();
        const after = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);

        expect(after, 'the first click must visibly change something').not.toBe(before);
    });

    test('a screenshot of each component, for the eye', async ({ page }, testInfo) => {
        // Not a visual-regression assertion — there is no baseline and this
        // lane could not have produced a trustworthy one. It attaches one image
        // per component to the report so a person can scan the set in a minute
        // instead of scrolling the page twice in two themes.
        await page.goto('/dev/components');

        const cards = page.locator('main .card').filter({ has: page.locator('.gal-variant') });
        const count = await cards.count();
        expect(count, 'the gallery should carry a card per component').toBeGreaterThan(15);

        for (let i = 0; i < count; i++) {
            const card = cards.nth(i);
            const name = (await card.locator('h3').first().textContent())?.trim() ?? `component-${i}`;

            await card.scrollIntoViewIfNeeded();
            await testInfo.attach(name.replace(/[<>]/g, ''), {
                body: await card.screenshot(),
                contentType: 'image/png',
            });
        }
    });
});

test.describe('component behaviour', () => {
    test.use(anonymous);

    test('a tab set remembers the pane the person chose', async ({ page }) => {
        await page.goto('/dev/components');

        const set = page.locator('[data-tabs-persist="gallery-import"]');
        const raw = set.locator('[data-tab-panel="raw"]');

        // The server marked "imported" active, so that pane is the one on
        // screen before any script runs.
        await expect(raw).toBeHidden();

        await set.locator('[data-tab="raw"]').click();
        await expect(raw).toBeVisible();
        await expect(set.locator('[data-tab="raw"]')).toHaveAttribute('aria-selected', 'true');

        // The reason tabs.js exists at all.
        await page.reload();
        await expect(page.locator('[data-tabs-persist="gallery-import"] [data-tab-panel="raw"]')).toBeVisible();
    });

    test('the tab strip moves on the arrow keys with one tab stop', async ({ page }) => {
        await page.goto('/dev/components');

        const set = page.locator('[data-tabs-persist="gallery-import"]');
        await set.locator('[data-tab="imported"]').click();
        await set.locator('[data-tab="imported"]').focus();

        await page.keyboard.press('ArrowLeft');
        await expect(set.locator('[data-tab="stripped"]')).toBeFocused();

        await page.keyboard.press('Home');
        await expect(set.locator('[data-tab="raw"]')).toBeFocused();

        // Only the selected tab is in the tab order; the rest are reached with
        // the arrows. Eight tabs must not cost eight tab presses to walk past.
        await expect(set.locator('[data-tab="stripped"]')).toHaveAttribute('tabindex', '-1');
    });

    test('a card that was closed stays closed after a reload', async ({ page }) => {
        await page.goto('/dev/components');

        const card = page.locator('[data-remember="card:gallery-demo"]');
        await expect(card).not.toHaveAttribute('open', '');

        await card.locator('summary').click();
        await expect(card).toHaveAttribute('open', '');

        await page.reload();
        await expect(page.locator('[data-remember="card:gallery-demo"]')).toHaveAttribute('open', '');
    });

    test('an exception row opens from the keyboard', async ({ page }) => {
        await page.goto('/dev/components');

        // <details> rather than a click handler is the whole point: Enter opens
        // it, and a screen reader calls it a disclosure.
        const row = page.locator('.ex.critical details.body').first();
        await expect(row.locator('.d')).toBeHidden();

        await row.locator('summary').focus();
        await page.keyboard.press('Enter');
        await expect(row.locator('.d')).toBeVisible();
    });

    test('the run bar disables itself once and says it is running', async ({ page }) => {
        await page.goto('/dev/components');

        const bar = page.locator('form [data-runbar]');
        const go = bar.locator('[data-runbar-go]');

        await go.click();

        await expect(bar).toHaveAttribute('aria-busy', 'true');
        await expect(bar.locator('[data-runbar-status]')).toHaveText(/Executing/);
        await expect(go).toBeDisabled();
    });

    test('the tooltip opens on focus, not only on hover', async ({ page }) => {
        await page.goto('/dev/components');

        const tip = page.locator('#tip');
        await expect(tip).toBeHidden();

        await page.locator('[data-tip]').first().focus();
        await expect(tip).toBeVisible();

        await page.keyboard.press('Escape');
        await expect(tip).toBeHidden();
    });

    test('a confirmation is SweetAlert2, never the browser dialog', async ({ page }) => {
        // A native dialog would block the page and fail this outright, because
        // nothing handles it.
        let native = false;
        page.on('dialog', () => { native = true; });

        await page.goto('/dev/components');
        await page.getByRole('button', { name: 'Confirm' }).click();

        await expect(page.locator('.swal2-popup')).toBeVisible();
        expect(native, 'nothing may call window.confirm').toBe(false);
    });
});

test.describe('the gallery on a phone', () => {
    test.use(anonymous);

    // Describe-level modifier callbacks receive the fixtures only, so this asks
    // the viewport rather than the project name — which is also the thing the
    // block actually cares about.
    test.skip(({ viewport }) => (viewport?.width ?? 0) > 500, 'narrow viewports only');

    test('nothing scrolls sideways at 375px', async ({ page }) => {
        await page.goto('/dev/components');

        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth
        );
        expect(overflow, 'a component pushed the document sideways on a phone').toBeLessThanOrEqual(0);
    });

    test('the KPI tiles reflow rather than being hidden', async ({ page }) => {
        await page.goto('/dev/components');

        const tiles = page.locator('.kpis').first().locator('.kpi');
        await expect(tiles).toHaveCount(4);

        for (let i = 0; i < 4; i++) {
            await expect(tiles.nth(i)).toBeVisible();
        }
    });

    test('the parameter grid becomes one column', async ({ page }) => {
        await page.goto('/dev/components');

        const fields = page.locator('.params').first().locator('.f');
        const first = await fields.nth(0).boundingBox();
        const second = await fields.nth(1).boundingBox();

        // Same left edge, different top: stacked, not squeezed to 60px each.
        expect(first.x).toBeCloseTo(second.x, 0);
        expect(second.y).toBeGreaterThan(first.y);
    });

    test('the tab strip scrolls instead of wrapping to four rows', async ({ page }) => {
        await page.goto('/dev/components');

        const strip = page.locator('[data-tabs-persist="gallery-import"] .tabs');
        const box = await strip.boundingBox();

        // One row. A wrapped strip is twice as tall and pushes the pane below
        // the fold on a phone.
        expect(box.height).toBeLessThan(60);
    });

    test('the tooltip is not drawn over the answer', async ({ page }) => {
        await page.goto('/dev/components');

        await page.locator('[data-tip]').first().focus();

        // A fixed box on a phone covers what it describes, and a tooltip only
        // ever repeats something written somewhere permanent.
        await expect(page.locator('#tip')).toBeHidden();
    });
});
