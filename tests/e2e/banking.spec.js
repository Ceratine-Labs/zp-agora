import { expect } from '@playwright/test';
import { test } from './support/fixtures.js';

/**
 * Daily Banking and Cashups (T037), read-only.
 *
 * The suite signs in as the shared Playwright AUDITOR, which holds
 * cash.cashup.view and none of cash.cashup.save, .confirm or .close. So this
 * confirms nothing, closes nothing and — above all — never presses Save card
 * amounts, which writes PumpIT; the absence of all three presses is one of
 * the things it checks.
 *
 * What it guards is the half a PHP test cannot see:
 *
 *  - the legs of a cashup are TABS (tabs.js): one pane shows at a time, and
 *    switching tab swaps the pane without a page load;
 *  - the variance is a chip whose tone says how far it is from nought, and the
 *    Cashups grid colours the Variance, Z-read and Cashup columns and nothing
 *    else;
 *  - a Cashups row opens underneath itself onto the cashup (row-detail.js)
 *    and links through to Daily Banking;
 *  - the queues are link-mode tabs that keep the grid's own period;
 *  - an auditor is offered no pool to add from, even by typing ?pool=;
 *  - the phone list reads and offers no Confirm to an auditor, at 375 wide
 *    with no sideways scroll.
 *
 * The site and day default to Nyala One Stop on 22 May 2026 — one of the
 * acceptance's parity days — and can be pointed elsewhere with
 * AGORA_E2E_BANKING_BRANCH and _DATE. A day with no cashups skips the tests
 * that need one rather than failing: an empty local stub is not a broken
 * screen.
 */
const scope = {
    branch_id: process.env.AGORA_E2E_BANKING_BRANCH || '6',
    date: process.env.AGORA_E2E_BANKING_DATE || '2026-05-22',
};

const url = (path, extra = {}) => `${path}?${new URLSearchParams({ ...scope, ...extra })}`;

test.describe('Daily Banking', () => {
    test('asks for a site before it shows anything', async ({ signedIn: page }) => {
        await page.goto('/app/cash/banking');

        await expect(page.getByRole('heading', { name: 'Daily Banking', level: 1 })).toBeVisible();
        await expect(page.getByText('Choose a site').filter({ visible: true }).first()).toBeVisible();
    });

    test('the day lists its cashups with the procedure that produced them', async ({ signedIn: page }) => {
        await page.goto(url('/app/cash/banking'));

        await expect(page.getByText('agora.usp_Cash_GetCashup').first()).toBeVisible();

        const rows = page.locator('table.dt tbody tr').filter({ has: page.locator('a[href*="till="]') });
        test.skip(await rows.count() === 0, 'No cashups for this site and day on this database.');

        // Every variance is a chip, and its text is a figure, never a bare colour.
        const variance = rows.first().locator('td.num .chip').first();
        await expect(variance).toBeVisible();
        await expect(variance).toHaveText(/R-?\d/);
    });

    test('a cashup opens with its legs as tabs, and an auditor gets none of the presses', async ({ signedIn: page }) => {
        await page.goto(url('/app/cash/banking'));

        const first = page.locator('table.dt tbody tr a[href*="till="]').first();
        test.skip(await first.count() === 0, 'No cashups for this site and day on this database.');

        await first.click();
        await page.waitForURL(/till=\d+/);

        // Visible matches only: the same words also sit in the page's info
        // panel, closed, since 4 Oct 2026.
        await expect(page.getByText('Actual total').filter({ visible: true }).first()).toBeVisible();
        await expect(page.getByText('Variance').filter({ visible: true }).first()).toBeVisible();

        const tabs = page.getByRole('tablist', { name: 'Legs of the cashup' });
        await expect(tabs).toBeVisible();

        // Panel mode: exactly one pane visible, and a click swaps it in place.
        const panes = page.locator('[data-tab-panel]');
        await expect(panes.locator('visible=true')).toHaveCount(1);

        const before = page.url();
        await tabs.getByRole('tab', { name: /Cards · FNB/ }).click();
        await expect(page.locator('[data-tab-panel="FNB"]')).toBeVisible();
        await expect(panes.locator('visible=true')).toHaveCount(1);
        expect(page.url()).toBe(before);

        // A control the user cannot use is not rendered (feature-rules §4).
        await expect(page.getByRole('button', { name: 'Confirm' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Close' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Save card amounts' })).toHaveCount(0);
    });

    test('the card load reads the terminal and offers an auditor no save', async ({ signedIn: page }) => {
        await page.goto(url('/app/cash/banking'));

        const first = page.locator('table.dt tbody tr a[href*="till="]').first();
        test.skip(await first.count() === 0, 'No cashups for this site and day on this database.');

        const href = await first.getAttribute('href');
        await page.goto(`${href}&cards=1`);

        await expect(page.getByText('Card amounts from the terminal')).toBeVisible();
        await expect(page.getByText('agora.usp_Cash_LoadCardAmounts')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Save card amounts' })).toHaveCount(0);
    });

    test('an auditor is offered no pool to add from, even by address', async ({ signedIn: page }) => {
        await page.goto(url('/app/cash/banking'));

        const first = page.locator('table.dt tbody tr a[href*="till="]').first();
        test.skip(await first.count() === 0, 'No cashups for this site and day on this database.');

        const href = await first.getAttribute('href');
        await page.goto(`${href}&pool=CashBags`);

        // "Add from …" writes PumpIT (the leg and the pool row's banked mark):
        // neither the link nor the list nor the press reaches someone who may not save.
        await expect(page.getByText('Actual total').filter({ visible: true }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: /^Add from / })).toHaveCount(0);
        await expect(page.getByText('agora.usp_Cash_GetCashupPool')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Add ticked rows to PumpIT' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Reopen' })).toHaveCount(0);
    });
});

test.describe('Cashups', () => {
    const period = { branches: scope.branch_id, from: scope.date, to: scope.date };

    test('the manager\'s view colours only the variance, the Z-read and the status', async ({ signedIn: page }) => {
        await page.goto(`/app/cash/cashups?${new URLSearchParams(period)}`);

        await expect(page.locator('[data-grid]')).toBeVisible();
        await expect(page.getByText('agora.usp_Cash_GridCashups')).toBeVisible();

        const rows = page.locator('[data-grid] table.dg tbody tr[data-detail-url]');
        test.skip(await rows.count() === 0, 'No cashups in this scope on this database.');

        const stray = await page.locator('[data-grid] table.dg tbody td')
            .evaluateAll((cells) => cells
                .filter((td) => !['Variance', 'ZreadCheck', 'Status'].includes(td.dataset.column))
                .filter((td) => td.querySelector('.chip'))
                .map((td) => td.dataset.column));

        expect(stray, 'no chip outside Variance, Z-read and Cashup').toEqual([]);
    });

    test('a row opens onto the cashup and links to Daily Banking', async ({ signedIn: page }) => {
        await page.goto(`/app/cash/cashups?${new URLSearchParams(period)}`);

        const row = page.locator('[data-grid] table.dg tbody tr[data-detail-url]').first();
        test.skip(await row.count() === 0, 'No cashups in this scope on this database.');

        await row.locator('td[data-column="TillDescription"]').click();

        const open = page.getByRole('link', { name: 'Open on Daily Banking' });
        await expect(open).toBeVisible();
        await expect(page.getByRole('button', { name: 'Close' })).toHaveCount(0);
        await expect(open).toHaveAttribute('href', /\/app\/cash\/banking\?.*till=\d+/);
    });

    test('the queues are tabs that keep the period', async ({ signedIn: page }) => {
        await page.goto(`/app/cash/cashups?${new URLSearchParams(period)}`);

        await page.getByRole('link', { name: 'Open cashups' }).click();
        await page.waitForURL(/show=open/);
        let params = new URL(page.url()).searchParams;
        expect(params.get('from')).toBe(scope.date);
        expect(params.get('branches')).toBe(scope.branch_id);

        await page.getByRole('link', { name: 'Z-reads with no cashup' }).click();
        await page.waitForURL(/no-cashup/);
        params = new URL(page.url()).searchParams;
        expect(params.get('to')).toBe(scope.date);
        await expect(page.getByText('agora.usp_Cash_GridZreadsNoCashup')).toBeVisible();
    });
});

test.describe('Confirm cashups on a phone', () => {
    test('reads the day and offers an auditor no Confirm, with no sideways scroll', async ({ signedIn: page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto(url('/app/cash/banking/phone'));

        await expect(page.getByRole('heading', { name: 'Confirm cashups', level: 1 })).toBeVisible();
        await expect(page.locator('[data-grid]')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Confirm' })).toHaveCount(0);

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow, 'no horizontal scroll at 375px').toBeLessThanOrEqual(0);
    });
});
