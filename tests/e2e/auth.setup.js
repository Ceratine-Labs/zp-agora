import { expect, test as setup } from '@playwright/test';
import { credentials, STATE_FILE } from './support/fixtures.js';

/**
 * Signs in once per run and saves the session for every other spec.
 *
 * This exists because of a real constraint rather than for speed. Sign-in is
 * rate limited to five attempts per address per five minutes, which is a
 * protection worth having — and a fixture that signed in through the form for
 * every test tripped it on any spec file with more than five tests. The eighth
 * test in navigation.spec.js failed for that reason, and it failed
 * intermittently, which is worse than failing every time.
 *
 * So the form is exercised deliberately, in login.spec.js, where it is the
 * subject; everywhere else reuses the session this produces.
 */
setup('sign in once and save the session', async ({ page }) => {
    setup.skip(
        !credentials.password,
        'AGORA_E2E_PASSWORD is not set — run: php artisan db:seed --class="Modules\\Core\\Database\\Seeders\\E2eFixtureSeeder"'
    );

    await page.goto('/login');
    await page.getByLabel('Email address').fill(credentials.email);
    await page.getByLabel('Password', { exact: true }).fill(credentials.password);
    await page.getByRole('button', { name: 'Sign in' }).click();

    await page.waitForURL('**/app');
    await expect(page.getByRole('heading', { name: 'Today', level: 1 })).toBeVisible();

    await page.context().storageState({ path: STATE_FILE });
});
