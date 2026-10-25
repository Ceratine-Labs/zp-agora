import { expect } from '@playwright/test';
import { test } from './support/fixtures.js';

// Reads an existing saved run; never previews, balances or changes stock.
test('global comparison preserves the saved run and shows its raw quantity warning', async ({ signedIn: page }, testInfo) => {
    let run = process.env.AGORA_STOCK_RECON_RUN_ID
        ? '/stock-recon/runs/' + Number(process.env.AGORA_STOCK_RECON_RUN_ID) : null;
    if (!run) {
        await page.goto('/app/stock-recon');
        const link = page.locator('a[href*="/stock-recon/runs/"]').first();
        test.skip(await link.count() === 0, 'No saved run is available; set AGORA_STOCK_RECON_RUN_ID.');
        const href = await link.getAttribute('href');
        run = href.match(/\/stock-recon\/runs\/\d+/)?.[0];
    }
    expect(run).toBeTruthy();
    await page.goto('/app' + run + '/global');
    await expect(page.getByText('Raw quantities — ratios are still being prepared')).toBeVisible();
    await expect(page.getByRole('link', { name: 'After proposed balancing', exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'As counted', exact: true }).click();
    await expect(page).toHaveURL(/basis=original/);
    await expect(page.getByText('Linked candidates to investigate', { exact: true })).toBeVisible();
    const group = page.locator('details[id^="group-"]').first();
    if (await group.count()) {
        await group.locator('summary').click();
        await expect(group).toHaveJSProperty('open', true);
        const chain = group.locator('tr[data-detail-url]').first();
        const chainUrl = await chain.getAttribute('data-detail-url');
        const response = page.waitForResponse(r => r.url() === new URL(chainUrl, page.url()).href && r.status() === 200);
        await chain.locator('td').nth(1).click();
        await response;
        await expect(chain).toHaveAttribute('aria-expanded', 'true');
        await expect(group.locator('.row-detail table').first()).toBeVisible();
        await chain.locator('td').nth(1).click();
    }
    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
    await page.screenshot({ path: testInfo.outputPath('global-desktop.png'), fullPage: true });
    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'));
    await page.screenshot({ path: testInfo.outputPath('global-light.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
    await page.screenshot({ path: testInfo.outputPath('global-mobile.png'), fullPage: true });
});
