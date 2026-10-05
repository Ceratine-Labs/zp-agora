import { expect } from '@playwright/test';
import { test } from './support/fixtures.js';

/**
 * ZREAD Allocation (T036), read-only.
 *
 * The suite signs in as the shared Playwright AUDITOR, which holds
 * cash.zread.view and neither cash.zread.allocate nor cash.zread.commit. So
 * this stages nothing and saves nothing — a spec that pressed Auto-allocate
 * would leave staged rows in agora.ZreadAllocation on every run — and the
 * absence of the three presses IS one of the things it checks.
 *
 * What it guards is the half a PHP test cannot see:
 *
 *  - only the Decision column is coloured (T036). A proposal written in a
 *    brand colour into the Employee column reads as an allocation to anybody
 *    skimming the list; the colour belongs to the decision alone;
 *  - a row opens underneath itself onto its proposal and evidence
 *    (row-detail.js), and the terminal history names its procedure;
 *  - the screen's scope survives the grid's own search: branch_id, date_from,
 *    date_to are not the grid's parameter names, so its scope form must carry
 *    them through as hidden fields or a search would drop the site;
 *  - the phone's confirm list renders and offers no Confirm to an auditor.
 *
 * The site and period default to Esikhawini Convenience, 21–31 Aug 2026 — the
 * acceptance's own — and can be pointed elsewhere with AGORA_E2E_ZREAD_BRANCH,
 * _FROM and _TO. A scope with no Z-reads skips the row tests rather than
 * failing them: an empty local stub is not a broken screen.
 */
const scope = {
    branch_id: process.env.AGORA_E2E_ZREAD_BRANCH || '9',
    date_from: process.env.AGORA_E2E_ZREAD_FROM || '2026-08-21',
    date_to: process.env.AGORA_E2E_ZREAD_TO || '2026-08-31',
    show: 'all',
};

const url = (path) => `${path}?${new URLSearchParams(scope)}`;

test.describe('ZREAD Allocation', () => {
    test('asks for a site before it shows anything', async ({ signedIn: page }) => {
        await page.goto('/app/cash/zread');

        await expect(page.getByRole('heading', { name: 'ZREAD Allocation', level: 1 })).toBeVisible();
        await expect(page.getByText('Choose a site').filter({ visible: true }).first()).toBeVisible();
        await expect(page.locator('[data-grid]')).toHaveCount(0);
    });

    test('an auditor sees the Z-reads and none of the presses', async ({ signedIn: page }) => {
        await page.goto(url('/app/cash/zread'));

        await expect(page.locator('[data-grid]')).toBeVisible();
        await expect(page.getByText('agora.usp_Cash_GridZread')).toBeVisible();

        // A control the user cannot use is not rendered (feature-rules §4).
        await expect(page.locator('#zread-auto')).toHaveCount(0);
        await expect(page.locator('#zread-clear')).toHaveCount(0);
        await expect(page.locator('#zread-commit')).toHaveCount(0);
    });

    test('only the decision column carries colour', async ({ signedIn: page }) => {
        await page.goto(url('/app/cash/zread'));

        const rows = page.locator('[data-grid] table.dg tbody tr[data-detail-url]');
        test.skip(await rows.count() === 0, 'No Z-reads in this scope on this database.');

        // Every chip and every proposal in the table body sits in the Decision
        // column, and nowhere else.
        const stray = await page.locator('[data-grid] table.dg tbody td')
            .evaluateAll((cells) => cells
                .filter((td) => td.dataset.column !== 'Decision')
                .filter((td) => td.querySelector('.chip, .proposal'))
                .map((td) => td.dataset.column));

        expect(stray, 'no chip or proposal outside the Decision column').toEqual([]);

        const decisions = page.locator('[data-grid] table.dg tbody td[data-column="Decision"]');
        await expect(decisions.first().locator('.chip, .proposal').first()).toBeVisible();
    });

    test('a row opens onto its proposal, its evidence and who works that terminal', async ({ signedIn: page }) => {
        await page.goto(url('/app/cash/zread'));

        const row = page.locator('[data-grid] table.dg tbody tr[data-detail-url]').first();
        test.skip(await row.count() === 0, 'No Z-reads in this scope on this database.');

        await row.locator('td[data-column="TerminalNo"]').click();

        const panel = page.locator('.proposal .evidence').first();
        await expect(panel).toBeVisible();
        await expect(page.getByText('agora.usp_Cash_ZreadTerminalHistory')).toBeVisible();

        // The auditor may read the proposal and may not act on it.
        await expect(page.getByRole('button', { name: 'Accept suggestion' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Set this Z-read' })).toHaveCount(0);
    });

    test('the site and period survive a search in the grid', async ({ signedIn: page }) => {
        await page.goto(url('/app/cash/zread'));

        const search = page.locator('[data-grid] form.dg-scope input[name="q"]');
        await search.fill('ARCH');
        await search.press('Enter');
        await page.waitForURL(/q=ARCH/);

        const params = new URL(page.url()).searchParams;
        expect(params.get('branch_id')).toBe(scope.branch_id);
        expect(params.get('date_from')).toBe(scope.date_from);
        expect(params.get('date_to')).toBe(scope.date_to);
        await expect(page.locator('[data-grid]')).toBeVisible();
    });

    test('the phone list confirms only, and not for an auditor', async ({ signedIn: page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto(url('/app/cash/zread/confirm'));

        await expect(page.getByRole('heading', { name: 'Confirm Z-reads', level: 1 })).toBeVisible();

        // Confirm-only: no hand-set form and no grid on the phone, ever.
        await expect(page.locator('select[name="employee_code"]')).toHaveCount(0);
        await expect(page.locator('[data-grid]')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Confirm' })).toHaveCount(0);
    });
});
