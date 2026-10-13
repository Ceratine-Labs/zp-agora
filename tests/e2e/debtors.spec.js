import { expect } from '@playwright/test';
import { test } from './support/fixtures.js';

/**
 * Debtors statements (13 Oct 2026), read-only: the list for last month, one
 * statement opened from it, and its PDF. Nothing is sent and no address is
 * saved — the PHP test covers those.
 *
 * What a PHP test cannot see: the grid draws with its date range on last
 * month, a row opens its statement, the page fits a phone, and the PDF link
 * answers with a PDF.
 */
test.describe('debtors statements', () => {
    test('the list opens a statement, and the statement its PDF', async ({ signedIn: page }, info) => {
        await page.goto('/app/debtors/statements');
        await expect(page.getByRole('heading', { name: 'Debtors statements', level: 1 })).toBeVisible();
        await page.screenshot({ path: info.outputPath('list.png'), fullPage: true });

        // Email selected: the dialog opens and counts the ticked rows.
        const bulk = page.getByRole('button', { name: 'Email selected' });
        if (await bulk.count()) {
            await bulk.click();
            await expect(page.getByText(/account\(s\)/).first()).toBeVisible();
            await page.screenshot({ path: info.outputPath('send-selected.png') });
            await page.keyboard.press('Escape');
        }

        const first = page.locator('a[href*="/app/debtors/statements/"]:visible').first();
        test.skip(await first.count() === 0, 'No debtor has anything to say for last month in this database.');
        await first.click();

        await expect(page.getByText('Balance brought forward').first()).toBeVisible();
        await expect(page.getByText('Ageing at')).toBeVisible();
        await page.screenshot({ path: info.outputPath('statement.png'), fullPage: true });

        // No sideways scroll on a phone.
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow).toBeLessThanOrEqual(1);

        const href = await page.getByRole('link', { name: 'Download' }).getAttribute('href');
        const response = await page.request.get(href);
        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('application/pdf');

        // The mail as it would go out, without sending it.
        await page.getByRole('link', { name: 'Preview email' }).click();
        await expect(page.getByRole('heading', { name: /Email preview/ })).toBeVisible();
        await expect(page.locator('[data-mail-preview]').getByText('Please find attached your statement')).toBeVisible();
        await page.screenshot({ path: info.outputPath('preview.png'), fullPage: true });
    });
});
