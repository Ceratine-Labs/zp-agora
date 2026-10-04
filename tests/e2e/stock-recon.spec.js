import { expect } from '@playwright/test';
import { test } from './support/fixtures.js';

/**
 * The stock recon centre, read-only.
 *
 * Read-only in the strong sense: it previews nothing and commits nothing. A
 * preview writes rows into agora.StockReconRun for the account that ran it,
 * and the suite signs in as the shared Playwright auditor — so a spec that
 * previewed would leave a trail on every run and would be indistinguishable
 * from somebody's real work in the run list.
 *
 * What it does guard is the half that has broken before and is invisible to a
 * PHP test:
 *
 *  - the area picker narrows to the chosen site IN THE BROWSER, which is the
 *    only reason the form does not need a round trip per site;
 *  - the caps block submits at all. A number input steps by 1 unless told
 *    otherwise, so `min="0.01" max="1.0"` holding 1 is invalid to the browser
 *    and Chrome refuses the whole form with a console message and nothing on
 *    screen — which is exactly what the Preview button did before `step` was
 *    added to <x-field>. checkValidity() is the assertion that catches it.
 *
 * The auditor holds stockrecon.runs.view and .exceptions.view and NOT create,
 * so the page renders and the press is refused by the route. That is the
 * shape being tested, not a limitation of it.
 */
test.describe('stock recon centre', () => {
    test('the standing explanation is behind the info button beside the title', async ({ signedIn: page }) => {
        await page.goto('/app/stock-recon');

        await expect(page.getByRole('heading', { name: 'Stock recon centre', level: 1 })).toBeVisible();

        // ZRP, 4 Oct 2026: the paragraphs on this screen went unread, so they
        // sit behind an "i". Closed on arrival; one press opens it, Escape
        // closes it again.
        const info = page.locator('.page-head details.info');
        await expect(info).toHaveJSProperty('open', false);
        await expect(page.getByText(/decides who carries a loss/)).toBeHidden();

        await info.locator('summary').click();
        await expect(page.getByText(/decides who carries a loss/)).toBeVisible();
        await expect(page.getByText(/A commit writes|Journal mode/)).toBeVisible();

        await page.keyboard.press('Escape');
        await expect(info).toHaveJSProperty('open', false);
    });

    test('the hub names its exclusions', async ({ signedIn: page }) => {
        await page.goto('/app/stock-recon');

        // Virtual products are out, and the form's info button says so rather
        // than the screen silently dropping them.
        await page.locator('.card-h details.info summary').first().click();
        await expect(page.getByText(/Virtual Items/)).toBeVisible();
    });

    test('the period is a month, then weeks inside it', async ({ signedIn: page }) => {
        await page.goto('/app/stock-recon');

        const form = page.locator('form[action$="/stock-recon/preview"]');
        const month = form.locator('select[name="month"]');
        const from = form.locator('select[name="from"]');
        const to = form.locator('select[name="to"]');

        // ZRP, 4 Oct 2026. A month narrows the weeks in the browser, and the
        // end cannot be set before the start.
        const months = await month.locator('option').evaluateAll((o) => o.map((x) => x.value));
        await month.selectOption(months[1]);

        const weeks = await from.locator('option:not([disabled])').allTextContents();
        expect(weeks.length, 'four to six Monday-Sunday weeks in a month').toBeGreaterThanOrEqual(4);
        expect(weeks[0]).toMatch(/^Week 1 · /);

        await from.selectOption({ label: weeks[2] });
        const ends = await to.locator('option:not([disabled])').allTextContents();
        expect(ends[0], 'the first end offered is the week the start is in').toBe(weeks[2]);
        expect(await to.inputValue() >= await from.inputValue(), 'the end follows the start').toBe(true);
    });

    test('the counting areas narrow to the chosen site, without a round trip', async ({ signedIn: page }) => {
        await page.goto('/app/stock-recon');

        const form = page.locator('form[action$="/stock-recon/preview"]');
        const site = form.locator('select[name="branch_id"]');
        const areas = form.locator('select[name="area_no"]');

        test.skip(await site.count() === 0, 'A pinned branch workspace has one site and no selector.');

        const total = await areas.locator('option').count();
        expect(total, 'every site\'s areas are rendered once and filtered in the browser').toBeGreaterThan(1);

        const values = await site.locator('option').evaluateAll((o) => o.map((x) => x.value));

        for (const value of values.slice(0, 2)) {
            const url = page.url();
            await site.selectOption(value);

            const offered = await areas.locator('option:not([disabled])').count();

            expect(offered, 'at least "every area at this site" is always offered').toBeGreaterThan(0);
            expect(offered, 'the list narrows to one site').toBeLessThan(total);
            expect(page.url(), 'narrowing the areas must not cost a page load').toBe(url);
        }
    });

    test('the plausibility caps do not block the form', async ({ signedIn: page }) => {
        await page.goto('/app/stock-recon');

        const form = page.locator('form[action$="/stock-recon/preview"]');

        // Every control, including the ones inside the closed <details>. An
        // invalid one there is the worst case: the browser refuses to submit
        // and cannot scroll to the field to say why.
        const valid = await form.evaluate((f) => f.checkValidity());

        expect(valid, 'the form is submittable as rendered — see the step note on <x-field>').toBe(true);
    });
});
