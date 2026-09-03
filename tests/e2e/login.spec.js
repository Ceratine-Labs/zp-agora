import { anonymous, credentials, expect, signInThroughTheForm, test } from './support/fixtures.js';

/**
 * Sign-in.
 *
 * The account used here is TEST-playwright@agora.local on the read-only
 * auditor role — no suite signs in as a real person, and the account it does
 * use cannot approve, capture or post anything.
 *
 * Nothing in this file writes. The one side effect of a successful sign-in is
 * User.LastSignInAt moving on the test account, which is the point of the
 * column.
 */
test.describe('sign-in', () => {
    // No saved session: these specs are about getting one.
    test.use(anonymous);

    test('the sign-in page renders and asks for both fields', async ({ page }) => {
        await page.goto('/login');

        await expect(page).toHaveTitle(/Sign in · Agora/);
        await expect(page.getByLabel('Email address')).toBeVisible();
        await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible();

        // The estate's name, not a framework's.
        await expect(page.getByText('Zululand Retail & Petroleum')).toBeVisible();
    });

    test('an anonymous caller asking for the app is sent to sign in', async ({ page }) => {
        await page.goto('/app');
        await expect(page).toHaveURL(/\/login$/);
    });

    test('the root URL leads into the application', async ({ page }) => {
        // Guards against a route creeping back into routes/web.php, which is
        // loaded before every module and would silently win.
        await page.goto('/');
        await expect(page).toHaveURL(/\/(login|app)$/);
    });

    test('a wrong password is refused without saying which half was wrong', async ({ page }) => {
        test.skip(!credentials.password, 'AGORA_E2E_PASSWORD is not set.');

        await signInThroughTheForm(page, { email: credentials.email, password: 'definitely-not-the-password' });

        const error = page.locator('.signin-error');
        await expect(error).toBeVisible();
        await expect(error).toHaveText('Those details do not match an account.');

        // Telling "no such user" apart from "wrong password" tells an attacker
        // which addresses exist.
        await expect(error).not.toContainText(/password|exist|unknown/i);
        await expect(page).toHaveURL(/\/login$/);
    });

    test('an unknown address gets the identical message', async ({ page }) => {
        await signInThroughTheForm(page, { email: 'nobody-at-all@agora.local', password: 'whatever-this-is' });

        await expect(page.locator('.signin-error')).toHaveText('Those details do not match an account.');
    });

    test('correct credentials land on the application, and signing out closes it again', async ({ page }) => {
        test.skip(!credentials.password, 'AGORA_E2E_PASSWORD is not set.');

        // Sign-in and sign-out are one test rather than two on purpose: each
        // sign-in spends one of five attempts allowed per address per five
        // minutes, and a suite that spends them faster than the window drains
        // fails on the limiter rather than on the code.
        await signInThroughTheForm(page);
        await page.waitForURL('**/app');

        await expect(page.getByRole('heading', { name: 'Today', level: 1 })).toBeVisible();

        // The footer states who is signed in and which database is behind it.
        await expect(page.locator('.shell-foot')).toContainText('PumpIT');
        await expect(page.locator('.shell-foot')).toContainText('TEST Playwright');

        await page.getByRole('button', { name: 'Sign out' }).click();
        await page.waitForURL('**/login');

        await page.goto('/app');
        await expect(page).toHaveURL(/\/login$/);
    });
});
