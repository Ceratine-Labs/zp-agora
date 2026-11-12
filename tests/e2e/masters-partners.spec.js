import { expect } from '@playwright/test';
import { test } from './support/fixtures.js';

/**
 * Setup → Trading rules → Suppliers and Account customers (T024).
 *
 *   npx playwright test tests/e2e/masters-partners.spec.js --project=desktop
 *   npx playwright test tests/e2e/masters-partners.spec.js --project=mobile
 *
 * What only a browser can show, and so what this asserts:
 *
 *  - each listing names its procedure (feature-rules §3.4) and offers no
 *    edit, save or delete — there is none, the rows are PumpIT's;
 *  - a supplier code is one row per site, and a header filter on the code
 *    narrows the grid to that supplier everywhere;
 *  - a code opens the supplier (§3.7) with the same code at the other sites;
 *  - account customers open on trading accounts, and "Show payment-control
 *    accounts" travels in the URL;
 *  - the palette's Partners source answers a supplier name as JSON;
 *  - nothing scrolls the window sideways on a phone.
 *
 * READ-ONLY. The suite signs in as the shared Playwright auditor, which holds
 * partners.*.view through *.*.view and none of the write permissions — so the
 * Edit / New supplier / New customer controls of the named write (T024, PM
 * decision 01a1cecf-8e08) are not drawn and their routes refuse, and that is
 * asserted. The writes themselves are proved against the local PumpIT stub in
 * tests/Feature/Partners/PartnersWriteTest. The fixtures are the customer's own
 * rows: APS-0640 Arendale is on the live list (12 Nov 2026) and on the local
 * stub. The rules themselves are proved in
 * tests/Feature/Partners/PartnersMasterTest. Nothing in this module writes
 * anything.
 */

async function sidewaysOverflow(page) {
    return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

function rows(page, isMobile) {
    return isMobile ? page.locator('.dg-card') : page.locator('.data-grid tbody tr');
}

test.describe('trading partners', () => {
    test('suppliers are listed with their procedure and nothing to change', async ({ signedIn: page, isMobile }) => {
        await page.goto('/app/setup/suppliers');

        await expect(page.getByRole('heading', { name: 'Suppliers', level: 1 })).toBeVisible();
        await expect(page.getByText('usp_Partners_GridSuppliers').first()).toBeVisible();
        await expect(rows(page, isMobile).first()).toBeVisible();
        for (const name of ['Edit', 'New supplier', 'Delete']) {
            await expect(page.getByRole('link', { name, exact: true })).toHaveCount(0);
        }

        expect(await sidewaysOverflow(page), 'the window does not scroll sideways').toBeLessThanOrEqual(1);
    });

    test('a code filter shows one supplier at every site it is on', async ({ signedIn: page, isMobile }) => {
        await page.goto('/app/setup/suppliers?' + new URLSearchParams({ 'f[SupplierCode][q]': 'APS-0640', 'f[SupplierCode][eq]': '1' }));

        const found = rows(page, isMobile);
        await expect(found.first()).toContainText('APS-0640');
        const count = await found.count();
        expect(count).toBeGreaterThan(0);
        await expect(found.filter({ hasText: 'APS-0640' })).toHaveCount(count);
    });

    test('a supplier code opens the supplier with its other sites', async ({ signedIn: page, isMobile }) => {
        test.skip(isMobile, 'The link is the same on a phone; the table is the desktop rendering.');

        await page.goto('/app/setup/suppliers?' + new URLSearchParams({ 'f[SupplierCode][q]': 'APS-0640', 'f[SupplierCode][eq]': '1' }));
        await page.locator('.data-grid tbody tr a', { hasText: 'APS-0640' }).first().click();

        await expect(page).toHaveURL(/\/app\/setup\/suppliers\/\d+\/APS-0640$/);
        await expect(page.getByRole('heading', { level: 1 })).toContainText('Arendale');
        await expect(page.getByText('POS account').first()).toBeVisible();
        await expect(page.getByText('At the other sites')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Back to suppliers' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Edit supplier' })).toHaveCount(0);
        await expect(page.getByText('Changes written to PumpIT')).toBeVisible();
    });

    test('account customers open on trading accounts; payment controls are one press away', async ({ signedIn: page, isMobile }) => {
        await page.goto('/app/setup/customers');

        await expect(page.getByRole('heading', { name: 'Account customers', level: 1 })).toBeVisible();
        await expect(page.getByText('usp_Partners_GridCustomers').first()).toBeVisible();
        await expect(rows(page, isMobile).filter({ hasText: 'Pumpit Control' })).toHaveCount(0);

        await page.getByRole('link', { name: 'Show payment-control accounts' }).click();
        await expect(page).toHaveURL(/controls=1/);
        await expect(page.getByRole('link', { name: 'Hide payment-control accounts' })).toBeVisible();

        expect(await sidewaysOverflow(page), 'the window does not scroll sideways').toBeLessThanOrEqual(1);
    });

    test('an auditor is offered no write to PumpIT and the write routes refuse', async ({ signedIn: page }) => {
        await page.goto('/app/setup/suppliers');
        await expect(page.getByRole('link', { name: 'New supplier' })).toHaveCount(0);
        await page.goto('/app/setup/customers');
        await expect(page.getByRole('link', { name: 'New customer' })).toHaveCount(0);

        for (const path of ['/app/setup/suppliers/create', '/app/setup/customers/create']) {
            const response = await page.request.get(path, { maxRedirects: 0 });
            expect(response.status(), path).not.toBe(200);
        }
    });

    test('the palette source answers a supplier name', async ({ signedIn: page }) => {
        const response = await page.request.get('/app/setup/partners/search?q=Arendale');
        expect(response.ok()).toBeTruthy();
        const { results } = await response.json();
        const hit = results.find((r) => r.kind === 'supplier' && r.code === 'APS-0640');
        expect(hit, 'Arendale is offered as one supplier hit').toBeTruthy();
        expect(results.filter((r) => r.code === 'APS-0640')).toHaveLength(1);
    });
});
