import { expect } from '@playwright/test';
import { test } from './support/fixtures.js';

/**
 * The bank recon dashboard (13 Oct 2026), read-only: three views of one scope,
 * and the scope — several sites at once — carried between them.
 *
 * What a PHP test cannot see: the site picker is the shared select component
 * (TomSelect, loaded on demand), the charts draw, and the Items grid keeps the
 * dashboard's sites when somebody searches it.
 */
test.describe('bank recon dashboard', () => {
    test('summary, per site and items keep the same sites', async ({ signedIn: page }, info) => {
        await page.goto('/app/recon/dashboard');
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

        await page.locator('form.dg-scope input[name="q"]').fill('0');
        await page.locator('form.dg-scope').evaluate((form) => form.requestSubmit());
        await expect(page).toHaveURL(/q=0/);
        expect(page.url()).toContain(encodeURIComponent('branches[]') + '=' + site);
        await page.screenshot({ path: info.outputPath('items.png'), fullPage: true });
    });
});
