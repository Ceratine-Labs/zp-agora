import { anonymous, expect, test } from './support/fixtures.js';

/**
 * The identity surfaces T007 adds, in a browser.
 *
 * login.spec.js already covers signing in and out and is deliberately left
 * alone — it is about the credential. This file is about everything around it:
 * the mockup's two-column page, the system-state panel that renders before
 * anybody is authenticated, and the forgot-password flow that is the ONLY way
 * in for the 85 users migrated from SS_Users.
 *
 * NOTHING HERE WRITES. The forgot-password form is submitted with an address
 * that belongs to nobody, which is exactly the case the page is designed to be
 * indistinguishable from a real one — so the assertion is that the two look
 * identical, and no row is created either way.
 *
 * Not run by this lane. Browser runs are Ryan's call — he is on solar and
 * usually working on the machine at the same time:
 *
 *     npx playwright test tests/e2e/auth.spec.js --project=desktop
 *     npx playwright test tests/e2e/auth.spec.js --project=mobile
 */
test.describe('identity', () => {
    // These pages are about not being signed in.
    test.use(anonymous);

    test('the sign-in page is the mockup: wordmark, expansion, tagline', async ({ page }) => {
        await page.goto('/login');

        const hero = page.locator('.signin-hero');

        // AG<em>O</em>RA — the O is the sand one. Asserted as text rather than
        // as an image, because it is text.
        await expect(hero.locator('.wm')).toHaveText('AGORA');
        await expect(hero.locator('.wm em')).toHaveText('O');
        await expect(hero.locator('.sub')).toHaveText('Zululand Retail & Petroleum');

        await expect(hero.locator('.signin-exp'))
            .toHaveText('All Group Operations, Reconciliation & Analysis');
        await expect(hero.locator('.signin-tag'))
            .toHaveText('The whole estate, in one market square');
    });

    test('the system-state panel renders before anybody has signed in', async ({ page }) => {
        await page.goto('/login');

        const rows = page.locator('.signin-state-row');

        // Four figures, in the order core.signin_state lists them: loads,
        // exceptions, Z-reads, purchase approvals.
        await expect(rows).toHaveCount(4);

        // Every row carries a status dot. Its tone is the provider's, so this
        // asserts the class is applied rather than which colour it is.
        for (let i = 0; i < 4; i++) {
            await expect(rows.nth(i).locator('.sdot')).toHaveCount(1);
        }

        await expect(page.locator('.signin-state')).toContainText('System state');
    });

    test('a figure whose module is not built says so instead of claiming zero', async ({ page }) => {
        await page.goto('/login');

        // The four owning epics have not landed, so all four rows are
        // placeholders. "All overnight loads clean" on a system that has not
        // looked would be a lie on the one screen everybody sees — and this is
        // the assertion that would fail the day somebody makes the provider
        // return 0 instead of null.
        await expect(page.locator('.signin-state')).toContainText('not reported yet');
        await expect(page.locator('.signin-state')).not.toContainText('All overnight loads clean');
    });

    test('the forgot-password link leads to the form', async ({ page }) => {
        await page.goto('/login');
        await page.getByRole('link', { name: 'Forgot your password?' }).click();

        await expect(page).toHaveURL(/\/password\/forgot$/);
        await expect(page).toHaveTitle(/Set your password · Agora/);
        await expect(page.getByLabel('Email address')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Send me a link' })).toBeVisible();
    });

    test('an address that belongs to nobody gets the same answer as one that does', async ({ page }) => {
        await page.goto('/password/forgot');
        await page.getByLabel('Email address').fill('nobody-at-all@agora.invalid');
        await page.getByRole('button', { name: 'Send me a link' }).click();

        // The confirmation must not tell anyone which addresses exist — on a
        // system whose users are firstname@zp.co.za, a form that says "no such
        // account" is a staff directory with a submit button.
        const notice = page.locator('.notice');
        await expect(notice).toBeVisible();
        await expect(notice).toContainText('If that address belongs to an Agora account');
        await expect(notice).not.toContainText(/no such|not found|unknown|does not exist/i);
    });

    test('a reset link with a token nobody issued is refused', async ({ page }) => {
        await page.goto('/password/reset/not-a-real-token?email=nobody-at-all@agora.invalid');

        await page.getByLabel('New password').fill('Th1s-Is-A-Real-Password!');
        await page.getByLabel('Confirm the new password').fill('Th1s-Is-A-Real-Password!');
        await page.getByRole('button', { name: 'Save it' }).click();

        // One message for expired, already used and simply wrong.
        await expect(page.locator('.signin-error')).toContainText('That link is no longer valid');
    });

    test('the address on the reset form cannot be changed', async ({ page }) => {
        await page.goto('/password/reset/not-a-real-token?email=someone@agora.invalid');

        // The token is valid for one address only. Letting somebody type a
        // different one turns a wrong keystroke into "that link is no longer
        // valid" with no explanation.
        const email = page.getByLabel('Email address');
        await expect(email).toHaveValue('someone@agora.invalid');
        await expect(email).toHaveAttribute('readonly', '');
    });

    test('the sign-in page stands up at a phone width', async ({ page }) => {
        // The two columns collapse to one below 900px. Run under
        // --project=mobile this is the 375x812 pass; under desktop it proves
        // the hero and the panel are both present at 1440.
        await page.goto('/login');

        await expect(page.locator('.signin-hero')).toBeVisible();
        await expect(page.locator('.signin-panel')).toBeVisible();

        // Whatever the width, the page must not scroll sideways.
        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1
        );
        expect(overflow, 'the sign-in page must not scroll horizontally').toBe(false);
    });
});
