import { expect } from '@playwright/test';
import { test } from './support/fixtures.js';

/**
 * Setup → the reference masters (T022).
 *
 *   npx playwright test tests/e2e/masters.spec.js --project=desktop
 *   npx playwright test tests/e2e/masters.spec.js --project=mobile
 *
 * What only a browser can show, and so what this asserts:
 *
 *  - every master's listing renders through <x-data-grid>, names its
 *    procedure (feature-rules §3.4) and offers the extract;
 *  - a per-branch master asks for a branch before it lists anything, and the
 *    branch it was given travels in the URL;
 *  - the profit centres list Fuel as five centres, and a row opens its detail
 *    panel in the grid (§3.5) with the sites behind it;
 *  - a header filter on GP band narrows the categories to the missing bands;
 *  - a category name opens the category's own page (§3.7);
 *  - nothing scrolls the window sideways on a phone.
 *
 * READ-ONLY, like the masters themselves: the shared Playwright auditor holds
 * masters.*.view (through *.*.view) and there is nothing to edit — no master
 * here is written by Agora, so no Edit, Save or Delete is ever offered, and
 * that is asserted. The procedures are proved in
 * tests/Feature/Masters/ReferenceMastersTest.
 */

async function sidewaysOverflow(page) {
    return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

function rows(page, isMobile) {
    return isMobile ? page.locator('.dg-card') : page.locator('.data-grid tbody tr[data-detail-url]');
}

// slug => [title, a branch that carries rows of it on the estate, procedure]
const PER_BRANCH = {
    categories: ['Categories and GP bands', 13, 'usp_Masters_GridCategoryGlCodes'],
    'expense-codes': ['Expense codes', 19, 'usp_Masters_GridExpenses'],
    tills: ['Tills', 8, 'usp_Masters_GridTills'],
    shifts: ['Shifts', 8, 'usp_Masters_GridShifts'],
    'transaction-types': ['Transaction types', 8, 'usp_Masters_GridTransactionTypes'],
    'fuel-types': ['Fuel types', 8, 'usp_Masters_GridFuelTypes'],
    tanks: ['Tanks', 8, 'usp_Masters_GridTanks'],
    pumps: ['Pumps', 8, 'usp_Masters_GridPumps'],
    meters: ['Utility meters', 19, 'usp_Masters_GridUtilityMeters'],
    terminals: ['Merchant and terminal numbers', 8, 'usp_Masters_GridTerminals'],
    'statement-criteria': ['Statement criteria', 1, 'usp_Masters_GridReconCriteria'],
};

test.describe('reference masters', () => {
    test('profit centres: nineteen, fuel as five, and a row opens the sites behind it', async ({ signedIn: page, isMobile }) => {
        await page.goto('/app/setup/profit-centres');

        await expect(page.getByRole('heading', { name: 'Profit centres', level: 1 })).toBeVisible();
        await expect(page.getByText('usp_Masters_GridProfitCentres').first()).toBeVisible();
        await expect(rows(page, isMobile)).toHaveCount(19);
        const names = isMobile
            ? await page.locator('.dg-card').allTextContents()
            : (await page.locator('.data-grid tbody td[data-column="Name"]').allTextContents()).map((t) => t.trim());
        for (const fuel of ['ULP 95', 'ULP 93', 'Diesel 50', 'Diesel 500', 'Diesel 10']) {
            expect(isMobile ? names.some((t) => t.includes(fuel)) : names.includes(fuel), `${fuel} is a centre of its own`).toBe(true);
        }
        await expect(page.getByRole('link', { name: /^(Edit|Delete)/ })).toHaveCount(0);

        if (!isMobile) {
            const shop = rows(page, isMobile).filter({ hasText: 'Conv Shop' }).first();
            await shop.locator('td[data-column="CategoryCount"]').click();
            await expect(page.locator('.row-detail').getByText('On the branch master').first()).toBeVisible();
            await expect(page.locator('.row-detail').getByText('dbo.SS_ProfitCentre').first()).toBeVisible();
        }

        expect(await sidewaysOverflow(page), 'the window does not scroll sideways').toBeLessThanOrEqual(1);
    });

    test('standard categories list the GL pair each posts to', async ({ signedIn: page, isMobile }) => {
        await page.goto('/app/setup/standard-categories?' + new URLSearchParams({ 'f[SupplierGLCode][q]': '51010209', 'f[SupplierGLCode][eq]': '1' }));

        await expect(page.getByRole('heading', { name: 'Standard categories', level: 1 })).toBeVisible();
        await expect(page.getByText('usp_Masters_GridStandardCategories').first()).toBeVisible();
        await expect(rows(page, isMobile).filter({ hasText: 'Grocery Foods' })).toHaveCount(1);
        await expect(rows(page, isMobile).filter({ hasText: 'Diesel 50' })).toHaveCount(0);
    });

    test('a per-branch master asks for a branch, then lists that site only', async ({ signedIn: page, isMobile }) => {
        await page.goto('/app/setup/pumps');
        await expect(page.getByText('Choose a branch').first()).toBeVisible();
        await expect(rows(page, isMobile)).toHaveCount(0);

        await page.goto('/app/setup/pumps?' + new URLSearchParams({ 'branches[]': '8' }));
        await expect(page).toHaveURL(/branches/);
        await expect(rows(page, isMobile).first()).toBeVisible();
        const sites = await rows(page, isMobile).evaluateAll((list) => list.map((r) => r.textContent));
        expect(sites.every((text) => text.includes('Caltex Ulundi')), 'every pump listed is at the site chosen').toBe(true);

        expect(await sidewaysOverflow(page), 'the window does not scroll sideways').toBeLessThanOrEqual(1);
    });

    test('a GP band filter leaves only the categories with no band, and a category opens its page', async ({ signedIn: page, isMobile }) => {
        await page.goto('/app/setup/categories?' + new URLSearchParams({ 'branches[]': '13', 'f[GpBand][in][]': 'Missing' }));

        await expect(page.getByRole('heading', { name: 'Categories and GP bands', level: 1 })).toBeVisible();
        const listed = rows(page, isMobile);
        test.skip(await listed.count() === 0, 'This site carries no category without a band on this database.');
        const texts = await listed.evaluateAll((list) => list.map((r) => r.textContent));
        expect(texts.every((text) => text.includes('Missing')), 'every row listed has no GP band').toBe(true);

        test.skip(isMobile, 'The link is the same on a phone; the table is the desktop rendering.');
        const link = page.locator('.data-grid tbody tr a').first();
        const name = (await link.textContent()).trim();
        await link.click();
        await expect(page).toHaveURL(/\/app\/setup\/categories\/13\?key=/);
        await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible();
        await expect(page.getByText('The same category at your other sites').first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'Back to Categories and GP bands' })).toBeVisible();
    });

    for (const [slug, [title, branch, procedure]] of Object.entries(PER_BRANCH)) {
        test(`${title} lists through the grid with its procedure and an extract`, async ({ signedIn: page, isMobile }) => {
            await page.goto(`/app/setup/${slug}?` + new URLSearchParams({ 'branches[]': String(branch) }));

            await expect(page.getByRole('heading', { name: title, level: 1 })).toBeVisible();
            await expect(page.getByText(procedure).first()).toBeVisible();
            await expect(rows(page, isMobile).first()).toBeVisible();
            await expect(page.locator('[data-grid] a[href*="/grids/app.setup."][href*="/extract"]').first()).toBeAttached();
            await expect(page.getByRole('link', { name: /^(Edit|Delete)/ })).toHaveCount(0);

            expect(await sidewaysOverflow(page), 'the window does not scroll sideways').toBeLessThanOrEqual(1);
        });
    }
});
