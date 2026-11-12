import { expect } from '@playwright/test';
import { test } from './support/fixtures.js';

/**
 * A table's scroll frame ends where the window ends (table-fit.js).
 *
 *   npx playwright test tests/e2e/table-fit.spec.js --project=desktop
 *   npx playwright test tests/e2e/table-fit.spec.js --project=mobile
 *
 * Ryan, 12 Nov 2026: "got body scroll, table scroll, a whole bunch of scrolls
 * and depending on the size of the screen it doesnt display well". Every frame
 * was `100dvh - 230px` whatever sat above it, so a page with more chrome than
 * that scrolled twice. Only a browser can show that it no longer does:
 *
 *  - on a desktop window the branches grid — more rows than a window holds —
 *    scrolls inside its frame, the frame ends inside the window, and the page
 *    itself has nothing left to scroll;
 *  - a shorter window gets a shorter frame, measured, not the same guess;
 *  - a phone keeps the stylesheet's frame: nothing is written on it, and the
 *    window never scrolls sideways.
 *
 * READ-ONLY, as the shared Playwright auditor.
 */

async function measure(page) {
    return page.evaluate(() => {
        const frame = [...document.querySelectorAll('.table-scroll')]
            .find((el) => el.offsetParent !== null && !el.parentElement.closest('.table-scroll'));
        const rect = frame.getBoundingClientRect();

        return {
            written: frame.style.getPropertyValue('--table-max-h'),
            frameBottom: Math.round(rect.bottom),
            frameHeight: Math.round(rect.height),
            rowsOverflow: frame.scrollHeight > frame.clientHeight,
            pageOverflow: document.documentElement.scrollHeight - window.innerHeight,
            sideways: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            window: window.innerHeight,
        };
    });
}

test.describe('table fit', () => {
    test('a long grid scrolls in its frame and the page does not scroll with it', async ({ signedIn: page }, testInfo) => {
        test.skip(testInfo.project.name === 'mobile', 'A phone keeps the stylesheet frame — asserted below.');

        await page.setViewportSize({ width: 1366, height: 768 });
        await page.goto('/app/setup/branches?admin=1');
        await expect(page.locator('.table-scroll').first()).toBeVisible();
        await expect.poll(async () => (await measure(page)).written).not.toBe('');

        const tall = await measure(page);
        expect(tall.rowsOverflow, 'The grid has more rows than the frame — the frame scrolls.').toBe(true);
        expect(tall.frameBottom, 'The frame ends inside the window.').toBeLessThanOrEqual(tall.window);
        expect(tall.pageOverflow, 'Nothing is left for the page to scroll.').toBeLessThanOrEqual(1);

        // A window too short to hold the table under the page's chrome takes
        // the other shape: the page scrolls once to bring the table up, and the
        // frame is then one window less the bars pinned above it — never taller
        // than the window, and never under those bars at the end of the page.
        await page.setViewportSize({ width: 1366, height: 560 });
        await expect.poll(async () => (await measure(page)).pageOverflow).toBeGreaterThan(1);
        await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));

        const pinned = await page.evaluate(() => ['.appbar', '.scopebar']
            .map((selector) => document.querySelector(selector)?.getBoundingClientRect().bottom ?? 0)
            .reduce((a, b) => Math.max(a, b), 0));
        const short = await page.evaluate(() => {
            const frame = [...document.querySelectorAll('.table-scroll')].find((el) => el.offsetParent !== null);
            const rect = frame.getBoundingClientRect();

            return { top: Math.round(rect.top), bottom: Math.round(rect.bottom), window: window.innerHeight };
        });
        expect(short.top, 'The frame clears the pinned bars.').toBeGreaterThanOrEqual(Math.floor(pinned));
        expect(short.bottom, 'And still ends inside the window.').toBeLessThanOrEqual(short.window);
        expect((await measure(page)).sideways).toBeLessThanOrEqual(0);
    });

    test('a phone keeps the stylesheet frame', async ({ signedIn: page }, testInfo) => {
        test.skip(testInfo.project.name !== 'mobile', 'Phone only.');

        await page.goto('/app/setup/branches?admin=1');
        await expect(page.locator('.table-block, .dg-cards').first()).toBeVisible();

        const frames = await page.evaluate(() => [...document.querySelectorAll('.table-scroll')]
            .map((el) => el.style.getPropertyValue('--table-max-h')));
        expect(frames.every((value) => value === '')).toBe(true);
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth))
            .toBeLessThanOrEqual(0);
    });
});
