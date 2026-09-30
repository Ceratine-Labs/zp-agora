import { expect } from '@playwright/test';
import { test } from './support/fixtures.js';

/**
 * Assets (T057): the register, movements, repair job cards and the masters.
 *
 *   npx playwright test tests/e2e/assets.spec.js --project=desktop
 *   npx playwright test tests/e2e/assets.spec.js --project=mobile
 *
 * What only a browser can show, and so what this asserts:
 *
 *  - the register says its depreciation rates are PROVISIONAL, above the grid,
 *    for as long as the config says so (Ryan, 27 Sep 2026: SARS wear-and-tear
 *    per group until ZP confirm their own);
 *  - every screen names the procedure behind it (feature-rules §3.4);
 *  - a header filter that matches nothing empties the grid, rather than
 *    quietly doing nothing — the only filter test worth writing;
 *  - an asset number opens the asset (§3.7), with its value and schedule;
 *  - "comebacks only" leaves nothing but comebacks;
 *  - nothing scrolls the window sideways on a phone.
 *
 * READ-ONLY. The suite signs in as the shared Playwright auditor, which holds
 * assets.*.view (through *.*.view) and none of save / edit / delete — so no
 * form is offered, and that is asserted rather than worked around. The write
 * paths are proved in tests/Feature/Assets against the local stub. Nothing in
 * this module writes the customer's databases in any case: the register is
 * Agora's own.
 *
 * Environments without assets skip the row-level tests rather than failing:
 * the register is new, and a fresh database has none.
 */

async function sidewaysOverflow(page) {
    return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

function rows(page, isMobile) {
    return isMobile ? page.locator('.dg-card') : page.locator('.data-grid tbody tr');
}

test.describe('asset register', () => {
    test('opens with its provisional-rates note, its procedure and no form for an auditor', async ({ signedIn: page }) => {
        await page.goto('/app/assets/register');

        await expect(page.getByRole('heading', { name: 'Asset register', level: 1 })).toBeVisible();
        await expect(page.getByText('Provisional rates').first()).toBeVisible();
        await expect(page.getByText('usp_Assets_GridRegister').first()).toBeVisible();
        await expect(page.getByText('agora.usp_Assets_Depreciate').first()).toBeAttached();

        // feature-rules §4: a control the user cannot use is not rendered.
        await expect(page.getByRole('button', { name: 'Register an asset' })).toHaveCount(0);

        expect(await sidewaysOverflow(page), 'the window does not scroll sideways').toBeLessThanOrEqual(1);
    });

    test('the as-at date travels in the URL and keeps the grid scope', async ({ signedIn: page }) => {
        await page.goto('/app/assets/register?q=ZZ');

        const asAt = page.locator('input[name="as_at"]');
        await asAt.fill('2026-08-31');
        await page.getByRole('button', { name: 'Value the register' }).click();

        await expect(page).toHaveURL(/as_at=2026-08-31/);
        await expect(page).toHaveURL(/q=ZZ/);
        await expect(asAt).toHaveValue('2026-08-31');
    });

    test('a header filter that matches nothing empties the register', async ({ signedIn: page, isMobile }) => {
        await page.goto('/app/assets/register');
        test.skip(await rows(page, isMobile).count() === 0, 'No assets registered in this environment.');

        await page.goto('/app/assets/register?' + new URLSearchParams({ 'f[Make][q]': 'ZZZZ-NO-SUCH-MAKE' }));
        await expect(rows(page, isMobile)).toHaveCount(0);
    });

    test('an asset number opens the asset, with its value and its schedule', async ({ signedIn: page, isMobile }) => {
        test.skip(isMobile, 'The link is the same on a phone; the table is the desktop rendering.');

        await page.goto('/app/assets/register');
        const link = page.locator('.data-grid tbody tr a').first();
        test.skip(await link.count() === 0, 'No assets registered in this environment.');

        const assetNo = (await link.textContent()).trim();
        await link.click();

        await expect(page).toHaveURL(/\/app\/assets\/register\/\d+\/\d+/);
        await expect(page.getByRole('heading', { level: 1 })).toContainText(assetNo);
        await expect(page.locator('.kpi').filter({ hasText: 'NBV' })).toBeVisible();
        await expect(page.getByText('Depreciation schedule')).toBeVisible();
        await expect(page.getByText('agora.usp_Assets_GetAsset').first()).toBeAttached();

        // An auditor reads; nothing to press.
        for (const name of ['Edit', 'Record a movement', 'Raise a job card', 'Delete']) {
            await expect(page.getByRole('button', { name, exact: true })).toHaveCount(0);
        }
    });
});

test.describe('asset movement', () => {
    test('lists movements, names its procedure, and filters to nothing when asked to', async ({ signedIn: page, isMobile }) => {
        await page.goto('/app/assets/movement');

        await expect(page.getByRole('heading', { name: 'Asset movement', level: 1 })).toBeVisible();
        await expect(page.getByText('usp_Assets_GridMovements').first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Record a movement' })).toHaveCount(0);

        test.skip(await rows(page, isMobile).count() === 0, 'No movements in the last 90 days in this environment.');

        await page.goto('/app/assets/movement?' + new URLSearchParams({ 'f[AssetNo][q]': 'ZZZZ-NO-SUCH-ASSET' }));
        await expect(rows(page, isMobile)).toHaveCount(0);
    });
});

test.describe('asset repair', () => {
    test('carries the comebacks-by-supplier tally above the job cards', async ({ signedIn: page }) => {
        await page.goto('/app/assets/repairs');

        await expect(page.getByRole('heading', { name: 'Asset repair', level: 1 })).toBeVisible();
        await expect(page.getByText('Comebacks by supplier')).toBeVisible();
        await expect(page.getByText('agora.usp_Assets_Comebacks').first()).toBeAttached();
        await expect(page.getByText('usp_Assets_GridRepairs').first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Raise a job card' })).toHaveCount(0);

        expect(await sidewaysOverflow(page), 'the window does not scroll sideways').toBeLessThanOrEqual(1);
    });

    test('"comebacks only" leaves nothing but comebacks', async ({ signedIn: page, isMobile }) => {
        test.skip(isMobile, 'The Result column is read off the desktop table.');

        await page.goto('/app/assets/repairs?' + new URLSearchParams({ 'f[IsComeback][in][]': 'Yes' }));

        const lines = rows(page, isMobile);
        const count = await lines.count();
        test.skip(count === 0, 'No comebacks in the last year in this environment.');

        for (let i = 0; i < count; i++) {
            await expect(lines.nth(i)).toContainText('Comeback');
        }
    });

    test('a job card names the call before it when it is a comeback', async ({ signedIn: page, isMobile }) => {
        test.skip(isMobile, 'The link is the same on a phone; the table is the desktop rendering.');

        await page.goto('/app/assets/repairs?' + new URLSearchParams({ 'f[IsComeback][in][]': 'Yes' }));
        const link = page.locator('.data-grid tbody tr a').first();
        test.skip(await link.count() === 0, 'No comebacks in the last year in this environment.');

        await link.click();

        await expect(page).toHaveURL(/\/app\/assets\/repairs\/\d+\/\d+/);
        await expect(page.getByText(/A comeback — \d+ days? after the last call/)).toBeVisible();
        await expect(page.getByText('The purchase request')).toBeVisible();
    });
});

test.describe('asset masters', () => {
    test('the groups carry their provisional period, and property is not depreciated', async ({ signedIn: page }) => {
        await page.goto('/app/assets/masters');

        await expect(page.getByRole('heading', { name: 'Asset groups and types', level: 1 })).toBeVisible();
        await expect(page.getByText('Provisional rates').first()).toBeVisible();
        await expect(page.getByText('agora.usp_Assets_Masters').first()).toBeAttached();

        const property = page.locator('tr').filter({ hasText: 'Property' }).first();
        test.skip(await property.count() === 0, 'The customer\'s asset groups are not in this environment\'s PumpIT.');
        await expect(property).toContainText('Not depreciated');
    });

    test('each tab is a link, and the movement types include Agora\'s return from repair', async ({ signedIn: page }) => {
        await page.goto('/app/assets/masters');

        await page.getByRole('link', { name: /Movement types/ }).click();

        await expect(page).toHaveURL(/tab=movements/);
        await expect(page.getByText('Returned from repair')).toBeVisible();
        // Read-only: nothing on the masters writes the customer's lists.
        await expect(page.locator('main form[method="POST"], main form[method="post"]')).toHaveCount(0);
    });
});
