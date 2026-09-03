import { test, expect } from './support/fixtures.js';

/**
 * The shell around every screen: theme, scope, and the mobile pass.
 */
test.describe('shell', () => {
    test('the theme toggle flips the page and survives a reload', async ({ signedIn }) => {
        const root = signedIn.locator('html');

        // Three states, not two: an unstamped document follows the system, so
        // read what is actually rendering rather than trusting the attribute.
        const before = await signedIn.evaluate(() =>
            getComputedStyle(document.body).backgroundColor
        );

        await signedIn.locator('#themebtn').click();

        const after = await signedIn.evaluate(() =>
            getComputedStyle(document.body).backgroundColor
        );
        expect(after, 'the first click must visibly change something').not.toBe(before);

        const chosen = await root.getAttribute('data-theme');
        expect(['light', 'dark']).toContain(chosen);

        // Stored in a cookie so the server can stamp <html> and the page never
        // flashes the wrong theme on the way in.
        await signedIn.reload();
        await expect(root).toHaveAttribute('data-theme', chosen);
    });

    test('the scope bar lists sites and excludes the administrative entities', async ({ signedIn }) => {
        const branch = signedIn.locator('.scope-field select[name="branch"]');
        await expect(branch).toBeVisible();

        const options = await branch.locator('option').allTextContents();

        // 25 trading sites plus the "All sites" entry. The six administrative
        // entities keep no trading day and must not be selectable.
        expect(options).toContain('All sites');
        expect(options).not.toContain('Zululand Petroleum');
        expect(options).not.toContain('AJLG Properties');
        expect(options).toContain('Caltex Ulundi');

        // And the inactive test fixture is invisible to a person.
        expect(options).not.toContain('TEST-Playwright');
    });

    test('choosing a site puts it in the URL so a link carries its scope', async ({ signedIn }) => {
        const branch = signedIn.locator('.scope-field select[name="branch"]');
        await branch.selectOption({ label: 'Caltex Ulundi' });

        await signedIn.waitForURL(/branch=8/);
        await expect(branch).toHaveValue('8');
    });

    test('the page reports which database is behind it', async ({ signedIn }) => {
        // On a system whose dev target is production, "which database am I
        // looking at" is not a detail.
        await expect(signedIn.locator('.shell-foot')).toContainText('PumpIT');
    });

    test('nothing scrolls sideways', async ({ signedIn }) => {
        const overflow = await signedIn.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth
        );
        expect(overflow).toBeLessThanOrEqual(0);
    });
});

test.describe('shell on a phone', () => {
    // Skipped on anything wide. A describe-level modifier callback receives the
    // fixtures only — not testInfo — so this asks the viewport rather than the
    // project name, which is also the thing the block actually cares about.
    test.skip(({ viewport }) => (viewport?.width ?? 0) > 500, 'narrow viewports only');

    test('the app bar and a menu panel still work at 375px', async ({ signedIn }) => {
        await expect(signedIn.locator('.appbar')).toBeVisible();

        await signedIn.locator('[data-menu="today"]').click();
        await expect(signedIn.locator('[data-panel="today"]')).toBeVisible();

        const overflow = await signedIn.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth
        );
        expect(overflow, 'the body must not scroll sideways on a phone').toBeLessThanOrEqual(0);
    });
});
