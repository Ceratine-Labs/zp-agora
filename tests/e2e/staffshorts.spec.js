import { anonymous, expect, signInThroughTheForm, test } from './support/fixtures.js';

/**
 * Staff shorts (T040), at a desk and on a phone.
 *
 *   npx playwright test tests/e2e/staffshorts.spec.js --project=desktop
 *   npx playwright test tests/e2e/staffshorts.spec.js --project=mobile
 *
 * What only a browser can show, and so what this asserts:
 *
 *  - the screen, the phone screen and the list open for a person who may only
 *    look, name the procedure behind them, and offer that person no form to
 *    raise, approve, decline or post — and the phone screen does not scroll
 *    sideways at 375 wide;
 *  - A REFUSAL ARRIVES AS A TOAST WITH ITS AGORA CODE: a short raised as
 *    "Other" with no note is refused (CASH_SHORT_NOTE), in a SweetAlert2
 *    toast, and the words stay on the page as a notice.
 *
 * TWO ACCOUNTS, as dropsafe.spec does.
 *
 * The suite's shared account is the read-only auditor (cash.staffshort.view,
 * not .raise), so the refusal cannot be pressed as it. The refusal test signs
 * in as a second account that MAY raise a short, named by
 *
 *   AGORA_E2E_STAFFSHORT_EMAIL / AGORA_E2E_STAFFSHORT_PASSWORD
 *   AGORA_E2E_STAFFSHORT_BRANCH   a site with at least one employee (default 999)
 *
 * and it SKIPS unless AGORA_BASE_URL is this machine. The press is refused by
 * agora.usp_Cash_RaiseStaffShort before its transaction, so nothing is written
 * and no number is taken — but "Raise the short" is a button that writes the
 * customer's PumpIT.dbo.BRN_StaffShorts the moment a note is given, and a spec
 * is not the place to find out it was pointed at production.
 * tests/Feature/Cash/StaffShortTest asserts the writes row by row, locally.
 *
 * Written for T040; not run by the lane that wrote it (Ryan runs the browser
 * suites).
 */

const writer = {
    email: process.env.AGORA_E2E_STAFFSHORT_EMAIL,
    password: process.env.AGORA_E2E_STAFFSHORT_PASSWORD,
};
const site = process.env.AGORA_E2E_STAFFSHORT_BRANCH || '999';
const local = /^https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?\/?$/.test(process.env.AGORA_BASE_URL || 'http://127.0.0.1:8123');

async function sidewaysOverflow(page) {
    return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

/** Choose a value on a select that TomSelect may have hidden, the way a change would. */
async function choose(page, name, value) {
    await page.locator(`select[name="${name}"]`).first().evaluate((select, v) => {
        if (select.tomselect) {
            select.tomselect.setValue(v);
        } else {
            select.value = v;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }, value);
}

/** The first real option of a select, or null. */
async function firstOption(page, name) {
    const values = await page.locator(`select[name="${name}"]`).first().locator('option')
        .evaluateAll((os) => os.map((o) => o.value).filter((v) => v !== ''));

    return values[0] ?? null;
}

/** The site to look at: the pinned one, else the first this account may see. */
async function openSite(page, path) {
    await page.goto(path);
    const scope = page.locator('select[name="branch_id"]');

    if (await scope.count() > 0) {
        const first = await firstOption(page, 'branch_id');
        test.skip(first === null, 'No site this account may see.');
        await page.goto(`${path}?branch_id=${first}`);
    }
}

test.describe('staff shorts — read-only', () => {
    test('the screen names its procedure and offers no press to a person who may only look', async ({ signedIn: page }) => {
        await openSite(page, '/app/cash/staff-shorts');

        await expect(page.getByRole('heading', { name: 'Staff shorts', level: 1 })).toBeVisible();
        await expect(page.getByText('agora.usp_Cash_GetStaffShorts').first()).toBeVisible();
        await expect(page.getByText(/Waiting: \d+/).first()).toBeVisible();

        await expect(page.getByRole('button', { name: 'Raise the short' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Approve' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Decline' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Post to the cashup' })).toHaveCount(0);
    });

    test('the phone screen opens at phone width with no sideways scroll and no raise form', async ({ signedIn: page }) => {
        await openSite(page, '/app/cash/staff-shorts/phone');

        await expect(page.getByRole('heading', { name: 'Staff shorts', level: 1 })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Raise the short' })).toHaveCount(0);
        expect(await sidewaysOverflow(page)).toBeLessThanOrEqual(1);
    });

    test('the list is a grid over its procedure, waiting shorts first', async ({ signedIn: page }) => {
        await page.goto('/app/cash/staff-shorts/list');

        await expect(page.getByRole('heading', { name: 'Staff shorts', level: 1 })).toBeVisible();
        await expect(page.getByText('agora.usp_Cash_GridStaffShorts').first()).toBeVisible();
        expect(await sidewaysOverflow(page)).toBeLessThanOrEqual(1);
    });
});

test.describe('staff shorts — the refusal', () => {
    test.use(anonymous);

    test('"Other" with no note is refused with its AGORA code in a toast, and nothing is written', async ({ page }) => {
        test.skip(!writer.email || !writer.password, 'AGORA_E2E_STAFFSHORT_EMAIL / _PASSWORD are not set.');
        test.skip(!local, 'Runs against this machine only — see the header.');

        await signInThroughTheForm(page, writer);
        await page.waitForURL(/\/app/);

        await page.goto(`/app/cash/staff-shorts/phone?branch_id=${site}`);
        const form = page.locator('form#staffshort-raise-phone');
        await expect(form).toBeVisible();

        const employee = await firstOption(page, 'employee');
        test.skip(employee === null, 'The site has no employee on this database.');

        await choose(page, 'employee', employee);
        await form.locator('input[name="amount"]').fill('12.34');
        await choose(page, 'reason', 'other');
        await form.locator('input[name="note"]').fill('');

        await form.getByRole('button', { name: 'Raise the short' }).click();

        // The confirmation is SweetAlert2's, worded as the action.
        const dialog = page.locator('.ag-dialog');
        await expect(dialog).toBeVisible();
        await dialog.getByRole('button', { name: 'Raise the short' }).click();

        await page.waitForURL(/\/app\/cash\/staff-shorts\/phone/);

        const toast = page.locator('.ag-toast');
        await expect(toast).toBeVisible();
        await expect(toast).toContainText('CASH_SHORT_NOTE');

        await expect(page.getByText('That was not saved')).toBeVisible();
        await expect(page.getByText('CASH_SHORT_NOTE').first()).toBeVisible();
        expect(await sidewaysOverflow(page)).toBeLessThanOrEqual(1);
    });
});
