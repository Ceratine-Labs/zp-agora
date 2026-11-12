import { anonymous, expect, signInThroughTheForm, test } from './support/fixtures.js';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';

/**
 * Users and access (T028): taking away sign-in ends the session already open,
 * and a head-office person granted no site sees every site.
 *
 * THIS SPEC WRITES. It deactivates people, so it uses its own TEST people from
 * support/users-fixture.php — an administrator and three subjects, created per
 * test and removed in `finally` — and runs only with AGORA_USERS_E2E=1 against
 * a server whose Agora database is the local container. Nobody real is touched.
 *
 * Each subject signs in in a browser context of its own, the way a second
 * person at another till would, and the administrator acts in a third. "The
 * next request" is simply that person's next page.
 *
 * Sign-ins are rationed: POST /login is throttled to ten a minute per address,
 * and this file spends four per project. Run one project at a time:
 *
 *     AGORA_USERS_E2E=1 npx playwright test tests/e2e/users.spec.js --project=desktop
 *     AGORA_USERS_E2E=1 npx playwright test tests/e2e/users.spec.js --project=mobile
 */
const fixturePath = fileURLToPath(new URL('./support/users-fixture.php', import.meta.url));
const optIn = process.env.AGORA_USERS_E2E === '1';

function fixture(action, token) {
    return JSON.parse(execFileSync('php', [fixturePath, action, token], { encoding: 'utf8', env: process.env }));
}

const DEACTIVATED = 'Your account has been deactivated, so you have been signed out. Ask head office if you still need access.';

/** A signed-in page for one person, in a browser context of their own. */
async function signedInAs(browser, contextOptions, person) {
    const context = await browser.newContext({ ...contextOptions, ...anonymous });
    const page = await context.newPage();
    await signInThroughTheForm(page, person);
    await page.waitForURL('**/app');
    return { context, page };
}

test.describe('users and access', () => {
    test.use(anonymous);

    let data;
    let token;

    test.beforeEach(async ({ baseURL }) => {
        test.skip(!optIn, 'Opt in with AGORA_USERS_E2E=1 against a server on the local Agora container.');
        expect(['127.0.0.1', 'localhost', '[::1]']).toContain(new URL(baseURL).hostname);
        token = randomBytes(6).toString('hex');
        data = fixture('prepare', token);
    });

    test.afterEach(async () => {
        if (!data) return;
        const removed = fixture('cleanup', token);
        data = undefined;
        expect(removed.users_remaining).toBe(0);
    });

    test('a head-office person with no site granted sees every trading site', async ({ page }) => {
        await signInThroughTheForm(page, data.ho);
        await page.waitForURL('**/app');

        const estate = data.estate.length;
        expect(estate).toBeGreaterThan(2);

        // Head office: the note says the list is the estate, not a grant.
        await expect(page.locator('.scope-note')).toHaveText(`${estate} sites in scope — every trading site`);

        // Dropping into the branch workspace offers every one of them.
        await page.goto('/app?ws=branch');
        const offered = await page.locator('select[name="branch"] option').evaluateAll(
            (options) => options.map((o) => Number(o.value)).sort((a, b) => a - b),
        );
        expect(offered).toEqual([...data.estate].sort((a, b) => a - b));
    });

    test('deactivating a signed-in person ends their session on their next page, one at a time or in bulk', async ({ page, browser }, testInfo) => {
        // Slow on purpose: four sessions, and the shell reads the legacy estate on every page.
        test.setTimeout(180_000);
        // The same device as the project (desktop or the 375px phone).
        const { baseURL, viewport, userAgent, deviceScaleFactor, isMobile, hasTouch } = testInfo.project.use;
        const options = { baseURL, viewport, userAgent, deviceScaleFactor, isMobile, hasTouch };

        const one = await signedInAs(browser, options, data.one);
        const two = await signedInAs(browser, options, data.two);

        try {
            // The administrator, in this test's own page.
            await signInThroughTheForm(page, data.admin);
            await page.waitForURL('**/app');

            // ---- One person, from their edit screen.
            await page.goto(`/app/setup/users/${data.one.id}/edit`);
            await page.locator('input[type="checkbox"][name="IsActive"]').uncheck();
            await page.getByRole('button', { name: 'Save user' }).click();
            // The editor saves back onto itself, flagged.
            await expect(page.getByText(`${data.prefix} one is saved.`)).toBeVisible({ timeout: 15_000 });
            await expect(page.getByText('This account is inactive.')).toBeVisible();

            // Their next page is the sign-in form, saying why.
            await one.page.goto('/app');
            await expect(one.page).toHaveURL(/\/login$/);
            await expect(one.page.locator('.signin-error')).toHaveText(DEACTIVATED);
            // And the session stays ended.
            await one.page.goto('/app');
            await expect(one.page).toHaveURL(/\/login$/);

            // The other person, untouched so far, is still in.
            await two.page.goto('/app');
            await expect(two.page).toHaveURL(/\/app$/);

            // ---- In bulk, from the user list.
            await page.goto(`/app/setup/users?f[EmailAddress][q]=${encodeURIComponent(data.two.email)}`);
            // The table's own checkbox on desktop; at 375px the grid is cards,
            // whose checkbox mirrors it (grid/_cards.blade.php).
            const id = data.two.id;
            const pick = page.locator(`input[data-check][value="${id}"]:visible, input[data-card-check][value="${id}"]:visible`);
            await expect(pick).toHaveCount(1);
            await pick.check();
            await page.getByRole('button', { name: 'Change account status…' }).click();

            const form = page.locator('#users-bulk-status form');
            await expect(form.locator('[data-bulk-count]')).toHaveText('1');
            await form.locator('select[name="action"]').selectOption('deactivate');
            await form.getByRole('button', { name: 'Apply to selected users' }).click();

            const dialog = page.locator('.ag-dialog');
            await expect(dialog).toBeVisible();
            await dialog.getByRole('button', { name: 'Apply change' }).click();
            await page.waitForURL('**/app/setup/users**');

            await two.page.goto('/app');
            await expect(two.page).toHaveURL(/\/login$/);
            await expect(two.page.locator('.signin-error')).toHaveText(DEACTIVATED);

            // The administrator's own session is not touched by any of it.
            await page.goto('/app');
            await expect(page).toHaveURL(/\/app$/);
        } finally {
            await one.context.close();
            await two.context.close();
        }
    });
});
