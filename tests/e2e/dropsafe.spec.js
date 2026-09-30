import { anonymous, expect, signInThroughTheForm, test } from './support/fixtures.js';

/**
 * The drop safe (T039), on a phone.
 *
 *   npx playwright test tests/e2e/dropsafe.spec.js --project=mobile
 *
 * What only a browser can show, and so what this asserts:
 *
 *  - THE ACCEPTANCE: a R2,500 bag with no manager is refused, and the refusal
 *    arrives as a SweetAlert2 toast carrying its AGORA code
 *    (CASH_DROP_MANAGER) — and stays on the page as a notice;
 *  - the phone screen opens at 375 wide without scrolling the page sideways,
 *    shows the "In the safe" chip, and offers a person who may not drop a bag
 *    no form to drop one.
 *
 * TWO ACCOUNTS.
 *
 * The suite's shared account is the read-only auditor (cash.dropsafe.view, not
 * .save), so the refusal cannot be pressed as it: the route would answer 403
 * before the procedure ever spoke. The acceptance test therefore signs in as a
 * second account that MAY drop a bag, named by
 *
 *   AGORA_E2E_DROPSAFE_EMAIL / AGORA_E2E_DROPSAFE_PASSWORD
 *   AGORA_E2E_DROPSAFE_BRANCH   a site with at least one employee (default 999)
 *
 * and it SKIPS unless AGORA_BASE_URL is this machine: the press is refused by
 * the procedure before anything is written, but Drop the bag is a button that
 * writes the customer's PumpIT.dbo.BRN_DropSafe where the manager IS given, and
 * a spec is not the place to find out it was pointed at production. Against
 * the local stub nothing is written either — the refusal comes before the
 * transaction — which tests/Feature/Cash/DropSafeTest asserts row by row.
 */

const writer = {
    email: process.env.AGORA_E2E_DROPSAFE_EMAIL,
    password: process.env.AGORA_E2E_DROPSAFE_PASSWORD,
};
const site = process.env.AGORA_E2E_DROPSAFE_BRANCH || '999';
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

test.describe('drop safe on a phone — read-only', () => {
    test('opens at phone width with the In the safe chip and no sideways scroll', async ({ signedIn: page }) => {
        await page.goto('/app/cash/drop-safe/phone');
        await expect(page.getByRole('heading', { name: 'Drop safe', level: 1 })).toBeVisible();

        const scope = page.locator('select[name="branch_id"]');
        if (await scope.count() > 0) {
            const first = await firstOption(page, 'branch_id');
            test.skip(first === null, 'No site this account may see.');
            await page.goto(`/app/cash/drop-safe/phone?branch_id=${first}`);
        }

        await expect(page.getByText(/In the safe: \d+/).first()).toBeVisible();
        expect(await sidewaysOverflow(page)).toBeLessThanOrEqual(1);
    });

    test('offers the auditor no form to drop a bag', async ({ signedIn: page }) => {
        await page.goto(`/app/cash/drop-safe/phone?branch_id=${site}`);

        await expect(page.getByRole('heading', { name: 'Drop safe', level: 1 })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Drop the bag' })).toHaveCount(0);
    });
});

test.describe('drop safe on a phone — the refusal', () => {
    test.use(anonymous);

    test('a R2,500 bag without a manager is refused with its AGORA code in a toast', async ({ page }) => {
        test.skip(!writer.email || !writer.password, 'AGORA_E2E_DROPSAFE_EMAIL / _PASSWORD are not set.');
        test.skip(!local, 'Runs against this machine only — see the header.');

        await signInThroughTheForm(page, writer);
        await page.waitForURL(/\/app/);

        await page.goto(`/app/cash/drop-safe/phone?branch_id=${site}`);
        const form = page.locator('form[id^="dropsafe-drop-phone"]');
        await expect(form).toBeVisible();

        const cashier = await firstOption(page, 'cashier');
        const reason = await firstOption(page, 'reason');
        test.skip(cashier === null || reason === null, 'The site has no employee or no drop reason on this database.');

        await form.locator('input[name="bag_no"]').fill(`TEST-E2E-${Date.now()}`.slice(0, 20));
        await form.locator('input[name="amount"]').fill('2500.00');
        await choose(page, 'reason', reason);
        await form.locator('input[name="ref_no"]').fill('TEST-E2E');
        await form.locator('input[name="limit_reason"]').fill('TEST e2e — over the limit');
        await choose(page, 'cashier', cashier);
        await choose(page, 'manager', '');

        await form.getByRole('button', { name: 'Drop the bag' }).click();

        // The confirmation is SweetAlert2's, worded as the action.
        const dialog = page.locator('.ag-dialog');
        await expect(dialog).toBeVisible();
        await dialog.getByRole('button', { name: 'Drop the bag' }).click();

        await page.waitForURL(/\/app\/cash\/drop-safe\/phone/);

        // THE ACCEPTANCE: the toast, with the code.
        const toast = page.locator('.ag-toast');
        await expect(toast).toBeVisible();
        await expect(toast).toContainText('CASH_DROP_MANAGER');

        // And it stays, for anybody who looked away.
        await expect(page.getByText('That was not saved')).toBeVisible();
        await expect(page.getByText('CASH_DROP_MANAGER').first()).toBeVisible();
        expect(await sidewaysOverflow(page)).toBeLessThanOrEqual(1);
    });
});
