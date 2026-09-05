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

    test('the branch workspace lets the user say which site', async ({ signedIn }) => {
        // The branch workspace IS "I am working at one site", so saying which
        // one is the whole job of the bar. Head office is the estate-wide
        // workspace and carries no branch control at all.
        await signedIn.goto('/app?ws=branch');

        const site = signedIn.locator('.scope-field select[name="branch"]');
        await expect(site).toBeVisible();
        await site.selectOption({ label: 'Caltex Ulundi' });

        await signedIn.waitForURL(/branch=8/);
        await expect(site).toHaveValue('8');

        // And back out, so the rest of the file runs in head office.
        await signedIn.goto('/app?ws=ho');
    });

    test('head office carries no branch selector in the chrome', async ({ signedIn }) => {
        // Feature-rules 3.3 puts the branches component on the RESULT SET, not
        // in the bar. Having it in both is not two ways to say the same thing;
        // it is two answers that disagree, and on the recon workbench the form
        // silently won while the bar showed a different site.
        await expect(signedIn.locator('.scope-field select[name="branch"]')).toHaveCount(0);

        // The scope bar still exists and still carries the "as at" date.
        await expect(signedIn.locator('.scope-field input[name="asat"]')).toBeVisible();
        await expect(signedIn.locator('.scope-note')).toContainText('sites in scope');
    });

    test('a screen that needs one site offers the trading sites itself', async ({ signedIn }) => {
        await signedIn.goto('/app/recon/auto/ABSA');

        const branch = signedIn.locator('select[name="branch_id"]');
        await expect(branch).toBeVisible();

        const options = await branch.locator('option').allTextContents();

        // Trading sites only. The six administrative entities keep no trading
        // day and have no bank statement to reconcile.
        expect(options).toContain('Caltex Ulundi');
        expect(options).not.toContain('Zululand Petroleum');
        expect(options).not.toContain('AJLG Properties');

        // And the inactive test fixture is invisible to a person.
        expect(options).not.toContain('TEST-Playwright');
    });

    test('the page reports which database is behind it', async ({ signedIn }) => {
        // On a system whose dev target is production, "which database am I
        // looking at" is not a detail.
        // Agora owns its own database now; the estate it reads is PumpIT's,
        // reached through agora.vw_*. The footer names the one it writes to.
        await expect(signedIn.locator('.shell-foot')).toContainText('Agora');
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
