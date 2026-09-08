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
    test('the hub leads with the invariant and names its exclusions', async ({ signedIn: page }) => {
        await page.goto('/app/stock-recon');

        await expect(page.getByRole('heading', { name: 'Stock recon centre', level: 1 })).toBeVisible();

        // The one sentence every figure on the screen follows from.
        await expect(page.getByText(/Balancing moves variance between shifts/)).toBeVisible();

        // Virtual products are out, and the screen says so rather than
        // silently dropping them.
        await expect(page.getByText(/Virtual Items/)).toBeVisible();
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
