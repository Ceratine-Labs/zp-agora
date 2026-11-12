import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const fixturePath = fileURLToPath(new URL('./support/email-template-fixture.php', import.meta.url));
const optIn = process.env.AGORA_EMAIL_TEMPLATE_E2E === '1';
function fixture(action, token) {
    return JSON.parse(execFileSync('php', [fixturePath, action, token], { encoding: 'utf8', env: process.env }));
}

test.describe('email templates', () => {
    test.use({ storageState: { cookies: [], origins: [] } });
    test('branch editor validates, previews samples, inserts variables and keeps version history', async ({ page, baseURL }, testInfo) => {
        // This single case includes login, validation, an isolated preview and two real saves.
        test.setTimeout(60_000);
        test.skip(!optIn, 'Opt in with AGORA_EMAIL_TEMPLATE_E2E=1 against a guarded local server.');
        expect(['127.0.0.1', 'localhost', '[::1]']).toContain(new URL(baseURL).hostname);
        const token = randomBytes(6).toString('hex');
        const data = fixture('prepare', token);
        const pageErrors = [];
        page.on('pageerror', error => pageErrors.push(error.message));
        try {
            await page.goto('/login');
            await page.getByLabel('Email address').fill(data.email);
            await page.getByLabel('Password', { exact: true }).fill(data.password);
            await page.getByRole('button', { name: 'Sign in', exact: true }).click();
            await page.goto('/app/setup/email-templates');
            await page.locator('select[name="module"]').selectOption('core');
            await page.getByRole('button', { name: 'Filter templates' }).click();
            await expect(page.locator('tbody')).toContainText('core.welcome');
            await expect(page.locator('tbody')).not.toContainText('cash.staff_short_raised');
            await page.goto(data.path);
            await page.getByLabel('Subject', { exact: true }).fill(`${data.prefix} {{unknown}}`);
            await page.getByRole('button', { name: 'Save template', exact: true }).click();
            await expect(page.getByText('Unknown variable: unknown.', { exact: true }).first()).toBeVisible();
            await page.getByLabel('Subject', { exact: true }).fill(`${data.prefix} Hello `);
            await page.getByRole('button', { name: '{{name}}', exact: true }).click();
            await expect(page.getByLabel('Subject', { exact: true })).toHaveValue(`${data.prefix} Hello {{name}}`);
            await page.getByLabel('HTML body', { exact: true }).fill('<p>Hello {{name}}.</p><p><a href="{{url}}">Open Agora</a></p>');
            await page.getByLabel('Plain text body', { exact: true }).fill('Hello {{name}}. {{url}}');
            const previewResponse = page.waitForResponse(response => response.url().endsWith('/core.welcome/preview'));
            await page.getByRole('button', { name: 'Preview sample' }).click();
            expect((await previewResponse).headers()['content-security-policy']).toContain("default-src 'none'");
            const frame = page.frameLocator('iframe[title="Email sample preview"]');
            await expect(frame.getByRole('heading', { level: 1 })).toHaveText(`${data.prefix} Hello Sample person`);
            await expect(frame.getByRole('link', { name: 'Open Agora' })).toHaveAttribute('href', 'https://example.invalid/login');
            await expect(page.locator('iframe[title="Email sample preview"]')).toHaveAttribute('sandbox', '');
            await page.locator('iframe[title="Email sample preview"]').scrollIntoViewIfNeeded();
            await expect(frame.getByRole('heading', { level: 1 })).toBeVisible();
            await page.screenshot({ path: testInfo.outputPath('email-preview.png') });
            await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
            await page.screenshot({ path: testInfo.outputPath('email-editor.png'), fullPage: true });
            await page.getByRole('button', { name: 'Save template', exact: true }).click();
            await expect(page.getByText('Email template saved.', { exact: true })).toBeVisible();
            await page.getByLabel('Subject', { exact: true }).fill(`${data.prefix} Updated {{name}}`);
            await page.getByRole('button', { name: 'Save template', exact: true }).click();
            await expect(page.getByText('Email template saved.', { exact: true })).toBeVisible();
            await page.getByText('Version history', { exact: true }).click();
            await expect(page.locator('summary').filter({ hasText: 'Version 2 · Active' })).toBeVisible();
            await expect(page.locator('summary').filter({ hasText: 'Version 1 · Active' })).toBeVisible();
            expect(pageErrors).toEqual([]);
        } finally {
            const removed = fixture('cleanup', token);
            expect(removed.users_remaining).toBe(0);
            expect(removed.overrides_remaining).toBe(0);
            console.log(`Email fixture cleanup: ${JSON.stringify(removed)}`);
        }
    });
});
