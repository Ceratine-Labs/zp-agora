/**
 * Test fixtures.
 *
 * `signedIn` gives a page already authenticated as the read-only Playwright
 * account. It signs in through the real form rather than injecting a session:
 * the sign-in path is the thing most likely to break, and a fixture that skips
 * it would hide exactly that.
 *
 * Every page produced here fails the test on a console error or an uncaught
 * exception. A silent JS error is how a menu stops opening while every
 * assertion still passes.
 */
import { test as base, expect } from '@playwright/test';

export const credentials = {
    email: process.env.AGORA_E2E_EMAIL,
    password: process.env.AGORA_E2E_PASSWORD,
};

export const test = base.extend({
    // Applied to every page in every spec, signed in or not.
    page: async ({ page }, use) => {
        const problems = [];
        page.on('pageerror', (e) => problems.push(`uncaught: ${e.message}`));
        page.on('console', (m) => {
            if (m.type() === 'error') problems.push(`console: ${m.text()}`);
        });

        await use(page);

        expect(problems, 'the page reported no JavaScript errors').toEqual([]);
    },

    signedIn: async ({ page }, use) => {
        test.skip(
            !credentials.password,
            'AGORA_E2E_PASSWORD is not set — run: php artisan db:seed --class="Modules\\Core\\Database\\Seeders\\E2eFixtureSeeder"'
        );

        await page.goto('/login');
        await page.getByLabel('Email address').fill(credentials.email);
        await page.getByLabel('Password', { exact: true }).fill(credentials.password);
        await page.getByRole('button', { name: 'Sign in' }).click();
        await page.waitForURL('**/app');

        await use(page);
    },
});

export { expect };
