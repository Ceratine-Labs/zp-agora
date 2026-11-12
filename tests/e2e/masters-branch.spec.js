import { expect } from '@playwright/test';
import { test } from './support/fixtures.js';

/**
 * Setup → The business → Branches (T021): the branch master.
 *
 *   npx playwright test tests/e2e/masters-branch.spec.js --project=desktop
 *   npx playwright test tests/e2e/masters-branch.spec.js --project=mobile
 *
 * What only a browser can show, and so what this asserts:
 *
 *  - the listing names its procedure (feature-rules §3.4) and lists the sites
 *    — Total Hluhluwe carrying POS type NAMOS — but NOT the six administrative
 *    entities until "Show administrative entities" is pressed, and the choice
 *    travels in the URL;
 *  - a header filter on POS type narrows the grid to the one NAMOS site;
 *  - a branch name opens the branch (§3.7), with its profit centres and the
 *    history the migration wrote;
 *  - Regions, brands and formats lists all three;
 *  - nothing scrolls the window sideways on a phone.
 *
 * READ-ONLY. The suite signs in as the shared Playwright auditor, which holds
 * masters.*.view (through *.*.view) and no edit — so no Edit control is
 * offered, and that is asserted. The write paths are proved in
 * tests/Feature/Masters/BranchMasterTest against the local stub. Nothing in
 * this module writes PumpIT.
 */

async function sidewaysOverflow(page) {
    return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

function rows(page, isMobile) {
    return isMobile ? page.locator('.dg-card') : page.locator('.data-grid tbody tr');
}

test.describe('branch master', () => {
    test('lists the sites with their procedure, and hides the administrative entities until asked', async ({ signedIn: page, isMobile }) => {
        await page.goto('/app/setup/branches');

        await expect(page.getByRole('heading', { name: 'Branches', level: 1 })).toBeVisible();
        await expect(page.getByText('usp_Masters_GridBranches').first()).toBeVisible();
        await expect(rows(page, isMobile).filter({ hasText: 'Total Hluhluwe' })).toHaveCount(1);
        await expect(rows(page, isMobile).filter({ hasText: 'Arcum Venandi' })).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Edit', exact: true })).toHaveCount(0);

        await page.getByRole('link', { name: 'Show administrative entities' }).click();
        await expect(page).toHaveURL(/admin=1/);
        await expect(rows(page, isMobile).filter({ hasText: 'Arcum Venandi' })).toHaveCount(1);
        await expect(page.getByRole('link', { name: 'Hide administrative entities' })).toBeVisible();

        expect(await sidewaysOverflow(page), 'the window does not scroll sideways').toBeLessThanOrEqual(1);
    });

    test('a POS type filter leaves the one NAMOS site', async ({ signedIn: page, isMobile }) => {
        await page.goto('/app/setup/branches?' + new URLSearchParams({ 'f[POSType][in][]': 'NAMOS', admin: '1' }));

        await expect(rows(page, isMobile)).toHaveCount(1);
        await expect(rows(page, isMobile).first()).toContainText('Total Hluhluwe');
        await expect(rows(page, isMobile).first()).toContainText('NAMOS');
    });

    test('a branch name opens the branch with its profit centres and history', async ({ signedIn: page, isMobile }) => {
        test.skip(isMobile, 'The link is the same on a phone; the table is the desktop rendering.');

        await page.goto('/app/setup/branches?' + new URLSearchParams({ 'f[Name][q]': 'Total Hluhluwe' }));
        await page.locator('.data-grid tbody tr a', { hasText: 'Total Hluhluwe' }).first().click();

        await expect(page).toHaveURL(/\/app\/setup\/branches\/26$/);
        await expect(page.getByRole('heading', { name: 'Total Hluhluwe', level: 1 })).toBeVisible();
        await expect(page.getByText('NAMOS').first()).toBeVisible();
        await expect(page.getByText('Profit centres').first()).toBeVisible();
        await expect(page.getByText('Migrated from SS_Branch').first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'Edit branch' })).toHaveCount(0);
    });

    test('regions, brands and formats are listed, with nothing to press for an auditor', async ({ signedIn: page }) => {
        await page.goto('/app/setup/branch-groupings');

        await expect(page.getByRole('heading', { name: 'Regions, brands and formats', level: 1 })).toBeVisible();
        for (const title of ['Regions', 'Brands', 'Formats']) {
            await expect(page.getByText(title, { exact: true }).first()).toBeVisible();
        }
        await expect(page.getByText('Add a region')).toHaveCount(0);
        await expect(page.getByText('Change', { exact: true })).toHaveCount(0);

        expect(await sidewaysOverflow(page), 'the window does not scroll sideways').toBeLessThanOrEqual(1);
    });
});
