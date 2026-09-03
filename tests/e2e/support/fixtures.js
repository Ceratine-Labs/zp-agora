/**
 * Test fixtures.
 *
 * `signedIn` gives a page already authenticated as the read-only Playwright
 * account. The session comes from auth.setup.js, which signs in through the
 * real form once per run — see that file for why it is once rather than per
 * test.
 *
 * `signInThroughTheForm` is for the specs that are ABOUT signing in. They run
 * with an empty storage state so nothing is assumed.
 *
 * Every page produced here fails the test on a console error or an uncaught
 * exception. A silent JS error is how a menu stops opening while every
 * assertion still passes.
 */
import { test as base, expect } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

/** Where auth.setup.js writes the session the other specs reuse. */
export const STATE_FILE = join(here, '..', '.auth', 'user.json');

export const credentials = {
    email: process.env.AGORA_E2E_EMAIL,
    password: process.env.AGORA_E2E_PASSWORD,
};

/** A page with no session at all, for the specs that test signing in. */
export const anonymous = { storageState: { cookies: [], origins: [] } };

export async function signInThroughTheForm(page, { email, password } = credentials) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
}

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
        test.skip(!credentials.password, 'AGORA_E2E_PASSWORD is not set.');

        await page.goto('/app');
        await use(page);
    },
});

export { expect };
