import { expect, test } from './support/fixtures.js';

/**
 * The Today reports, in a browser.
 *
 * The feature suite already proves the procedures return the right columns and
 * the blades render them. What only a browser can show is the part
 * feature-rules cares about visually: a wide result set scrolls inside its own
 * container instead of making the page scroll sideways (§3.2 / proposed §C),
 * the procedure name is on the grid where the customer can read it (§3.4), and
 * the scope survives a round trip through the URL.
 *
 * Read-only. Every page here is a GET.
 */
test.describe('Today reports', () => {
    test('the catalogue lists the reports and names each procedure', async ({ signedIn: page }) => {
        await page.goto('/app/reports');

        await expect(page.getByRole('heading', { name: 'Reports', level: 1 })).toBeVisible();
        await expect(page.getByRole('link', { name: /Day close status/ })).toBeVisible();
        await expect(page.getByText('agora.usp_Reports_GridDayClose')).toBeVisible();
    });

    test('a report renders its grid and says which procedure produced it', async ({ signedIn: page }) => {
        await page.goto('/app/reports/day-close?from=2026-09-03&to=2026-09-03');

        await expect(page.getByRole('heading', { name: 'Day close status', level: 1 })).toBeVisible();
        await expect(page.locator('.table-proc')).toHaveText('agora.usp_Reports_GridDayClose');
        await expect(page.locator('table.dt thead th').first()).toBeVisible();
    });

    test('a wide grid scrolls inside itself, not the page', async ({ signedIn: page }) => {
        // Day close is the widest of the fifteen. A page that scrolls sideways
        // is the failure mode a unit test cannot see.
        await page.goto('/app/reports/day-close?from=2026-09-03&to=2026-09-03');

        const pageOverflows = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1
        );

        expect(pageOverflows, 'the page itself must not scroll horizontally').toBe(false);
    });

    test('the scope travels in the url, so a link opens what the sender saw', async ({ signedIn: page }) => {
        await page.goto('/app/reports/unallocated-zreads?from=2026-09-01&to=2026-09-03');

        await expect(page.locator('input[name="from"]')).toHaveValue('2026-09-01');
        await expect(page.locator('input[name="to"]')).toHaveValue('2026-09-03');
    });

    test('the sites picker is a real multi-select, not a raw listbox', async ({ signedIn: page }) => {
        /*
         * TomSelect hides the original control with a CLASS, so with no
         * stylesheet loaded the native <select multiple> stays on the page at
         * full size with an orphaned search box under it. That is what shipped
         * first, and a feature test cannot see it — the markup is identical
         * either way. Only a browser can tell.
         */
        await page.goto('/app/reports/day-close?from=2026-09-03&to=2026-09-03');

        await expect(page.locator('.ts-control')).toBeVisible();

        /*
         * The original control is visually hidden by the clip technique rather
         * than display:none, so that it keeps its form semantics for a screen
         * reader — which means Playwright still calls it "visible". The
         * regression to catch is the one you can SEE: a stranded native
         * listbox is a hundred-odd pixels tall, and a correctly hidden one is
         * a single pixel.
         */
        const stranded = await page.locator('select[data-select]').boundingBox();
        expect(stranded.height, 'the native listbox must not still be on the page').toBeLessThan(5);

        // Several choices, each its own chip, and each removable.
        await page.locator('.ts-control').click();
        await page.locator('.ts-control input').fill('Baobab Inn');
        await page.keyboard.press('Enter');
        await page.locator('.ts-control input').fill('Caltex Ulundi');
        await page.keyboard.press('Enter');

        await expect(page.locator('.ts-control .item')).toHaveCount(2);

        await page.locator('.ts-control .item .remove').first().click();
        await expect(page.locator('.ts-control .item')).toHaveCount(1);
    });

    test('the matched characters in the dropdown take the theme, not a fixed wash', async ({ signedIn: page }) => {
        // TomSelect ships a tan highlight that belongs to neither theme and is
        // unreadable on the active row. It is overridden — and its stylesheet
        // loads AFTER ours, so the override is a specificity fix that is easy
        // to undo by accident.
        await page.goto('/app/reports/day-close');
        await page.locator('.ts-control').click();
        await page.locator('.ts-control input').fill('ulundi');

        const highlight = page.locator('.ts-dropdown .highlight').first();
        await expect(highlight).toBeVisible();

        const background = await highlight.evaluate((el) => getComputedStyle(el).backgroundColor);
        expect(background, 'the match highlight must not carry a fixed background').toBe('rgba(0, 0, 0, 0)');
    });

    test('a procedure that refuses is a message, not a stack trace', async ({ signedIn: page }) => {
        await page.goto('/app/reports/overnight-loads?from=2020-01-01&to=2026-09-03');

        await expect(page.locator('.notice-stop')).toContainText('92 days at a time');
    });
});
