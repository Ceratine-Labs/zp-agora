import { expect } from '@playwright/test';
import { test } from './support/fixtures.js';

/**
 * The bank recon dashboard (13 Oct 2026), read-only: four views of one scope —
 * Pivot (the default since 16 Nov 2026), Summary, Per site, Items — and the
 * scope, several sites at once, carried between them.
 *
 * What a PHP test cannot see: the site picker is the shared select component
 * (TomSelect, loaded on demand), the charts draw, the pivot's Status column is
 * a strip of pills built in the browser, its link chips change the view, and
 * the Items grid keeps the dashboard's sites when somebody searches it.
 */
test.describe('bank recon dashboard', () => {
    test('lands on the pivot', async ({ signedIn: page }, info) => {
        await page.goto('/app/recon/dashboard');
        await expect(page.getByRole('heading', { name: 'What is outstanding' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Outstanding by site' })).toBeVisible();
        await expect(page.getByText('Bank lines outstanding')).toBeVisible();
        // The tiles that add up: one-sided money on each side, and what is on both.
        await expect(page.getByRole('link', { name: /^Not in bank/ })).toBeVisible();
        await expect(page.getByRole('link', { name: /^Not declared/ })).toBeVisible();
        await expect(page.getByRole('link', { name: /^On both sides/ })).toBeVisible();

        const table = page.locator('table.rp-pivot');
        await expect(table).toBeVisible();
        // One head row of headings; the tools filter row is the second.
        await expect(table.locator('thead tr').first().locator('th').first()).toHaveText('Site');
        await page.screenshot({ path: info.outputPath('pivot.png'), fullPage: true });
    });

    test('difference keeps its lens in the URL and filters by status', async ({ signedIn: page }, info) => {
        await page.goto('/app/recon/dashboard');
        await page.getByRole('link', { name: 'Difference', exact: true }).click();
        await expect(page).toHaveURL(/lens=difference/);
        await expect(page.getByRole('group', { name: 'Filter by Status' })).toBeVisible();
        await expect(page.locator('table.rp-pivot thead th', { hasText: 'Not in bank, last 30 days' })).toBeVisible();
        await page.screenshot({ path: info.outputPath('difference.png'), fullPage: true });
    });

    test('dates over seven days open one day of one site', async ({ signedIn: page }, info) => {
        await page.goto('/app/recon/dashboard');
        await page.getByRole('link', { name: 'Dates', exact: true }).click();
        await expect(page).toHaveURL(/cols=dates/);
        await page.getByRole('link', { name: '7 days', exact: true }).click();
        await expect(page).toHaveURL(/from=/);

        // Site, Status, seven days, Total.
        const headings = page.locator('table.rp-pivot thead tr').first().locator('th');
        await expect(headings).toHaveCount(10);
        await expect(headings.nth(2)).toHaveText(/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun) \d{1,2}$/);
        await page.screenshot({ path: info.outputPath('dates.png'), fullPage: true });

        // The first linked day cell: not Site, not Status, not Total.
        const cell = page.locator('table.rp-pivot tbody tr td:nth-child(n+3):not(:last-child) a[href*="view=items"]').first();
        test.skip(await cell.count() === 0, 'Nothing outstanding dated in the last seven days.');
        await cell.click();
        await expect(page).toHaveURL(/view=items/);
        const url = new URL(page.url());
        expect(url.searchParams.get('from')).toBeTruthy();
        expect(url.searchParams.get('from')).toBe(url.searchParams.get('to'));
        expect(url.searchParams.getAll('branches[]')[0] ?? url.searchParams.getAll('branches[0]')[0]).toBeTruthy();
    });

    test('summary, per site and items keep the same sites', async ({ signedIn: page }, info) => {
        await page.goto('/app/recon/dashboard?view=summary');
        await expect(page.getByRole('heading', { name: 'What is outstanding' })).toBeVisible();
        await expect(page.getByText('Bank lines outstanding')).toBeVisible();
        await page.screenshot({ path: info.outputPath('summary.png'), fullPage: true });

        // The first site in the per-site table, by its link.
        const first = page.locator('table a[href*="view=sites"]').first();
        test.skip(await first.count() === 0, 'Nothing outstanding in this database.');
        const name = (await first.innerText()).trim();
        await first.click();
        await expect(page).toHaveURL(/view=sites/);
        await expect(page.getByText(name).first()).toBeVisible();
        await page.screenshot({ path: info.outputPath('sites.png'), fullPage: true });

        // To Items with the same site, then search: the site must survive.
        await page.getByRole('tab', { name: 'Items' }).or(page.getByRole('link', { name: 'Items' })).first().click();
        await expect(page).toHaveURL(/view=items/);
        const url = new URL(page.url());
        const site = url.searchParams.getAll('branches[]')[0] ?? url.searchParams.getAll('branches[0]')[0];
        expect(site).toBeTruthy();

        const fold = page.locator('.dg-scope-fold');
        if (!(await fold.evaluate((element) => element.open))) await fold.locator('> summary').click();
        await page.locator('form.dg-scope input[name="q"]').fill('0');
        await page.locator('form.dg-scope').evaluate((form) => form.requestSubmit());
        await expect(page).toHaveURL(/q=0/);
        expect(page.url()).toContain(encodeURIComponent('branches[]') + '=' + site);
        await page.screenshot({ path: info.outputPath('items.png'), fullPage: true });
    });
});
