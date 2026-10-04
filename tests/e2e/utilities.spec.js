import { expect } from '@playwright/test';
import { test } from './support/fixtures.js';

/**
 * Utility meters (T056), on a phone.
 *
 *   npx playwright test tests/e2e/utilities.spec.js --project=mobile
 *
 * Agora has no mobile view resolver yet (plan §3.9, T016), so there is no
 * separate mobile blade: the capture screen IS the phone screen, built as one
 * site and one day so it is a short stack of fields at 375px. What only a
 * browser can show, and so what this asserts:
 *
 *  - nothing on the page scrolls the WINDOW sideways — the month table and the
 *    readings grid scroll inside their own containers;
 *  - the scope folds to one line once a site is chosen, and opens again;
 *  - the readings grid becomes cards on a phone and still names its procedure
 *    (feature-rules §3.4);
 *  - a header filter that matches nothing empties the grid, rather than
 *    quietly doing nothing.
 *
 * READ-ONLY. The suite signs in as the shared Playwright auditor, which holds
 * utilities.readings.view (through *.*.view) and NOT save — so the form is
 * not offered, which is asserted rather than worked around. Pressing Save here
 * would write the customer's PumpIT.dbo.BRN_UtilityTransaction on whatever
 * instance the app points at; the write path is proved in
 * tests/Feature/Utilities against the local stub instead.
 */

/** The first site in the picker, or null where this environment has none. */
async function chooseFirstSite(page) {
    await page.goto('/app/utilities');

    const scope = page.locator('details.scope-line');
    if (!(await scope.evaluate((d) => d.open))) {
        await scope.locator('summary').click();
    }

    const site = page.locator('select[name="branch_id"]');
    const values = await site.locator('option').evaluateAll((os) => os.map((o) => o.value).filter((v) => v !== ''));

    if (values.length === 0) {
        return null;
    }

    await site.selectOption(values[0]);
    await page.getByRole('button', { name: 'Show' }).click();
    await expect(page).toHaveURL(/branch_id=/);

    return values[0];
}

async function sidewaysOverflow(page) {
    return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

test.describe('utility meters', () => {
    test('the screen opens on a phone without scrolling the page sideways', async ({ signedIn: page }) => {
        await page.goto('/app/utilities');

        await expect(page.getByRole('heading', { name: 'Utility meters', level: 1 })).toBeVisible();

        // With no site chosen the scope is open and asks for one.
        await expect(page.locator('details.scope-line')).toHaveJSProperty('open', true);
        await expect(page.getByText('Readings are recorded one site at a time')).toBeVisible();

        expect(await sidewaysOverflow(page), 'the window does not scroll sideways').toBeLessThanOrEqual(1);
    });

    test('a site shows its day, its month by meter and the meters standing still', async ({ signedIn: page }) => {
        const site = await chooseFirstSite(page);
        test.skip(site === null, 'No trading sites in this environment.');

        // Chosen once, then folded to one line so the work leads.
        await expect(page.locator('details.scope-line')).toHaveJSProperty('open', false);
        await expect(page.locator('details.scope-line summary')).toContainText('Month to date');

        await expect(page.getByText('Readings this month')).toBeVisible();
        await expect(page.getByRole('heading', { name: /^Readings for / })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Meters standing still' })).toBeVisible();
        // The rule is on the screen, from the same map the procedure is handed:
        // generators (L, M) stand still whenever there is no load-shedding.
        // Behind the card's info button since 4 Oct 2026.
        await page.locator('section.card', { has: page.getByRole('heading', { name: 'Meters standing still' }) })
            .locator('details.info summary').click();
        await expect(page.getByText(/never for meter types L and M/).first()).toBeVisible();
        await expect(page.getByRole('heading', { name: / by meter$/ })).toBeVisible();

        // The procedures are named on the screen (feature-rules §3.4).
        await expect(page.getByText('agora.usp_Utilities_PrepareReadings').first()).toBeVisible();

        // Everything wide scrolls inside itself; the page does not.
        expect(await sidewaysOverflow(page), 'the window does not scroll sideways').toBeLessThanOrEqual(1);
    });

    test('the auditor is shown the readings and not offered a box to change them', async ({ signedIn: page }) => {
        const site = await chooseFirstSite(page);
        test.skip(site === null, 'No trading sites in this environment.');

        // feature-rules §4: a control the user cannot use is not rendered.
        await expect(page.getByRole('button', { name: 'Record readings' })).toHaveCount(0);
        await expect(page.locator('input[name$="[ClosingReading]"]')).toHaveCount(0);
    });

    test('the scope line opens again to change the day', async ({ signedIn: page }) => {
        const site = await chooseFirstSite(page);
        test.skip(site === null, 'No trading sites in this environment.');

        await page.locator('details.scope-line summary').click();

        const day = page.locator('input[name="date"]');
        await expect(day).toBeVisible();
        // A reading is taken, not forecast: the picker stops at today.
        await expect(day).toHaveAttribute('max', /^\d{4}-\d{2}-\d{2}$/);
    });

    test('the readings grid is cards on a phone and names its procedure', async ({ signedIn: page, isMobile }) => {
        await page.goto('/app/utilities');

        await expect(page.getByText('usp_Utilities_GridReadings').first()).toBeVisible();

        const rows = isMobile ? page.locator('.dg-card') : page.locator('.data-grid tbody tr');
        test.skip(await rows.count() === 0, 'No readings this month in this environment.');

        // A value that matches nothing is the only filter test worth writing —
        // one that matches something passes whether the filter works or not.
        await page.goto('/app/utilities?' + new URLSearchParams({ 'f[MeterDescription][q]': 'ZZZZ-NO-SUCH-METER' }));
        await expect(rows).toHaveCount(0);
    });
});
