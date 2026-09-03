import { test, expect, credentials } from './support/fixtures.js';

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

        await page.goto('/login');
        await page.getByLabel('Email address').fill(credentials.email);
        await page.getByLabel('Password', { exact: true }).fill('definitely-not-the-password');
        await page.getByRole('button', { name: 'Sign in' }).click();

        const error = page.locator('.signin-error');
        await expect(error).toBeVisible();
        await expect(error).toHaveText('Those details do not match an account.');

        // Telling "no such user" apart from "wrong password" tells an attacker
        // which addresses exist.
        await expect(error).not.toContainText(/password|exist|unknown/i);
        await expect(page).toHaveURL(/\/login$/);
    });

    test('an unknown address gets the identical message', async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill('nobody-at-all@agora.local');
        await page.getByLabel('Password', { exact: true }).fill('whatever-this-is');
        await page.getByRole('button', { name: 'Sign in' }).click();

        await expect(page.locator('.signin-error')).toHaveText('Those details do not match an account.');
    });

    test('correct credentials land on the application', async ({ signedIn }) => {
        await expect(signedIn).toHaveURL(/\/app$/);
        await expect(signedIn.getByRole('heading', { name: 'Today', level: 1 })).toBeVisible();

        // The footer states who is signed in and which database is behind it.
        await expect(signedIn.locator('.shell-foot')).toContainText('PumpIT');
        await expect(signedIn.locator('.shell-foot')).toContainText('TEST Playwright');
    });

    test('signing out ends the session and the app is closed again', async ({ signedIn }) => {
        await signedIn.getByRole('button', { name: 'Sign out' }).click();
        await signedIn.waitForURL('**/login');

        await signedIn.goto('/app');
        await expect(signedIn).toHaveURL(/\/login$/);
    });
});
