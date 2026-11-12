import { test, expect } from '@playwright/test';
import 'dotenv/config';

test('ordinary table layout persists for a signed-in user', async ({ page }) => {
    test.skip(!process.env.AGORA_E2E_PASSWORD, 'The local browser fixture is not configured.');
    await page.goto('/login');
    await page.getByLabel('Email address').fill(process.env.AGORA_E2E_EMAIL);
    await page.getByLabel('Password', { exact: true }).fill(process.env.AGORA_E2E_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL('**/app');
    await page.goto('/dev/components');
    const table = page.locator('#gal-table-tools');
    const block = table.locator('xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " table-block ")][1]');
    const chooser = block.locator('.tl-chooser');
    await expect(chooser).toBeVisible();
    const reset = async () => {
        if (!(await chooser.evaluate((element) => element.open))) await chooser.locator('summary').click();
        const response = page.waitForResponse((r) => r.url().includes('/app/table-layouts/') && r.request().method() === 'DELETE');
        await chooser.getByRole('button', { name: 'Reset layout' }).click();
        expect((await response).ok()).toBeTruthy();
    };
    try {
        await chooser.locator('summary').click();
        const response = page.waitForResponse((r) => r.url().includes('/app/table-layouts/') && r.request().method() === 'POST');
        await chooser.locator('.tl-setting select').first().selectOption('large');
        expect((await response).ok()).toBeTruthy();
        await page.reload();
        await expect(block).toHaveClass(/tl-text-large/, { timeout: 30000 });
        await chooser.locator('summary').click();
        await expect(chooser.locator('.tl-setting select').first()).toHaveValue('large');
    } finally {
        await reset();
    }
});
