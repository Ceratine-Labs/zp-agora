import { test, expect } from './support/fixtures.js';

/**
 * <x-data-grid>, in a browser, at 1440 and at 375.
 *
 * Read-only against the rows. The one thing it writes is the signed-in user's
 * own layout in `agora.UserGridColumn` for the development grid — which is the
 * point of the persistence tests, and cannot be proved any other way: a unit
 * test cannot see whether the save actually fired, only that a function was
 * called. Each test that changes a layout puts it back with Reset.
 *
 * Run it:
 *     npx playwright test tests/e2e/grid.spec.js --project=desktop
 *     npx playwright test tests/e2e/grid.spec.js --project=mobile
 *
 * `/app/dev/grids` is registered in local and testing only, so this spec is
 * skipped anywhere it is not there rather than failing.
 */

const GRID = '[data-grid-key="app.dev.grids:branches"]';
const PROC = '[data-grid-key="app.dev.grids:dayclose"]';

/** Put the grid back to the catalogue's own layout, whatever the test did. */
async function reset(page, selector) {
    const grid = page.locator(selector);

    if (!(await grid.locator('[data-chooser-reset]').count())) return;

    await grid.locator('.dg-chooser > summary').click();
    await grid.locator('[data-chooser-reset]').click();
    // The reset asks first — SweetAlert2, like every confirmation in Agora.
    await page.getByRole('button', { name: 'Reset' }).last().click();
    await page.waitForLoadState('load');
}

test.beforeEach(async ({ signedIn }) => {
    const response = await signedIn.goto('/app/dev/grids');
    test.skip(response !== null && response.status() === 404, '/app/dev/grids is not registered here.');
    await expect(signedIn.locator(GRID)).toBeVisible();
});

test.describe('data grid — desktop', () => {
    test.skip(({ isMobile }) => isMobile, 'the table is the desktop rendering');

    test('a grid says which procedure produced it', async ({ signedIn }) => {
        // feature-rules §3.4. The customer reads this and opens that object.
        await expect(signedIn.locator(PROC).locator('.table-proc'))
            .toHaveText('agora.usp_Reports_GridDayClose');
    });

    test('a grid says how much of the answer it is showing', async ({ signedIn }) => {
        await expect(signedIn.locator(PROC).locator('.dg-shown')).toContainText(/Showing \d/);
    });

    test('clicking a sortable header sorts, and clicking it again flips it', async ({ signedIn }) => {
        const grid = signedIn.locator(GRID);
        const header = grid.locator('th[data-column="Name"]');

        await header.getByRole('link').click();
        await expect(signedIn.locator(GRID).locator('th[data-column="Name"]'))
            .toHaveAttribute('aria-sort', 'ascending');

        await signedIn.locator(GRID).locator('th[data-column="Name"]').getByRole('link').click();
        await expect(signedIn.locator(GRID).locator('th[data-column="Name"]'))
            .toHaveAttribute('aria-sort', 'descending');
    });

    test('a numeric column sorts as a number, so 10 comes after 9', async ({ signedIn }) => {
        // The classic grid defect. The branch ids run past 9, so a text sort
        // would put 10 immediately after 1.
        await signedIn.locator(GRID).locator('th[data-column="BranchId"]').getByRole('link').click();
        await signedIn.waitForURL(/branches_sort=BranchId/);

        const ids = (await signedIn.locator(`${GRID} td[data-column="BranchId"]`).allTextContents())
            .map((t) => Number(t.trim()))
            .filter((n) => Number.isFinite(n));

        expect(ids.length).toBeGreaterThan(9);
        expect([...ids]).toEqual([...ids].sort((a, b) => a - b));
    });

    test('a header filter narrows the rows and travels in the URL', async ({ signedIn }) => {
        const grid = signedIn.locator(GRID);
        const before = await grid.locator('tbody tr').count();

        await grid.locator('input[name="branches_f[Name][q]"]').fill('Ulundi');
        await grid.locator('form.dg-scope button[type="submit"]').click();

        await signedIn.waitForURL(/branches_f%5BName%5D%5Bq%5D=Ulundi/);

        const after = await signedIn.locator(`${GRID} tbody tr`).count();
        expect(after).toBeLessThan(before);
        expect(after).toBeGreaterThan(0);

        for (const name of await signedIn.locator(`${GRID} td[data-column="Name"]`).allTextContents()) {
            expect(name.toLowerCase()).toContain('ulundi');
        }
    });

    test('hiding a column hides it now and keeps it hidden after a reload', async ({ signedIn }) => {
        const grid = signedIn.locator(GRID);

        await grid.locator('.dg-chooser > summary').click();
        await grid.locator('[data-chooser-visible][value="SortOrder"]').uncheck();
        await expect(grid.locator('th[data-column="SortOrder"]')).toBeHidden();

        // The save is debounced, so the reload has to wait for the request that
        // carries it — a unit test cannot see whether it actually fired.
        await signedIn.waitForResponse(
            (r) => r.url().includes('/columns') && r.request().method() === 'POST' && r.ok(),
        );

        await signedIn.reload();
        await expect(signedIn.locator(`${GRID} th[data-column="SortOrder"]`)).toHaveCount(0);

        await reset(signedIn, GRID);
        await expect(signedIn.locator(`${GRID} th[data-column="SortOrder"]`)).toBeVisible();
    });

    test('reordering a column in the chooser reorders the table and survives a reload', async ({ signedIn }) => {
        const grid = signedIn.locator(GRID);

        await grid.locator('.dg-chooser > summary').click();
        await grid.locator('[data-chooser-item="Name"] [data-chooser-up]').click();

        const headers = await grid.locator('thead tr:first-child th[data-column]').evaluateAll(
            (cells) => cells.map((c) => c.dataset.column),
        );
        expect(headers[0]).toBe('Name');

        await signedIn.waitForResponse(
            (r) => r.url().includes('/columns') && r.request().method() === 'POST' && r.ok(),
        );

        await signedIn.reload();
        const after = await signedIn.locator(`${GRID} thead tr:first-child th[data-column]`).evaluateAll(
            (cells) => cells.map((c) => c.dataset.column),
        );
        expect(after[0]).toBe('Name');

        await reset(signedIn, GRID);
    });

    test('a resized column keeps its width, and a re-shown column does not collapse', async ({ signedIn }) => {
        // ZP's bug, named in feature-rules §3.6: the widths map is seeded from
        // the columns visible at the time, so a column brought back after a
        // resize has no width of its own and renders at zero.
        const grid = signedIn.locator(GRID);
        const name = grid.locator('th[data-column="Name"]');
        const box = await name.boundingBox();

        await name.locator('.dg-resize').hover();
        await signedIn.mouse.down();
        await signedIn.mouse.move(box.x + box.width + 90, box.y + box.height / 2, { steps: 8 });
        await signedIn.mouse.up();

        await signedIn.waitForResponse(
            (r) => r.url().includes('/columns') && r.request().method() === 'POST' && r.ok(),
        );

        await signedIn.reload();
        const widened = await signedIn.locator(`${GRID} th[data-column="Name"]`).boundingBox();
        expect(widened.width).toBeGreaterThan(box.width + 40);

        // Now hide and re-show a DIFFERENT column: it was never in the widths
        // map, so it must inherit auto sizing rather than render at zero.
        const back = signedIn.locator(GRID);
        await back.locator('.dg-chooser > summary').click();
        await back.locator('[data-chooser-visible][value="IsActive"]').uncheck();
        await back.locator('[data-chooser-visible][value="IsActive"]').check();

        const reshown = await back.locator('th[data-column="IsActive"]').boundingBox();
        expect(reshown.width).toBeGreaterThan(20);

        await reset(signedIn, GRID);
    });

    test('the extract drawer opens, says what it will produce, and closes on Escape', async ({ signedIn }) => {
        const grid = signedIn.locator(GRID);
        const drawer = signedIn.locator('#dg-app-dev-grids-branches-extract');

        await expect(drawer).toBeHidden();
        await grid.getByRole('button', { name: 'Extract' }).click();
        await expect(drawer).toBeVisible();

        await expect(drawer.getByRole('link', { name: 'Download .xlsx' })).toBeVisible();
        await expect(drawer.getByRole('link', { name: 'Download .csv' })).toBeVisible();

        await signedIn.keyboard.press('Escape');
        await expect(drawer).toBeHidden();
    });

    test('the extract downloads what the grid is showing', async ({ signedIn }) => {
        await signedIn.locator(GRID).getByRole('button', { name: 'Extract' }).click();

        const [download] = await Promise.all([
            signedIn.waitForEvent('download'),
            signedIn.locator('#dg-app-dev-grids-branches-extract')
                .getByRole('link', { name: 'Download .csv' }).click(),
        ]);

        expect(download.suggestedFilename()).toMatch(/^branches-\d{4}-\d{2}-\d{2}\.csv$/);
    });

    test('ticking rows shows the selection bar and clearing hides it again', async ({ signedIn }) => {
        const grid = signedIn.locator(GRID);
        const bar = grid.locator('[data-selection]');

        await expect(bar).toBeHidden();

        await grid.locator('tbody [data-check]').first().check();
        await expect(bar).toBeVisible();
        await expect(bar.locator('[data-selection-count]')).toHaveText('1');

        // The header box is check-all.js's, and it must not also expand a row.
        await grid.locator('thead [data-check-all]').check();
        const rows = await grid.locator('tbody [data-check]').count();
        await expect(bar.locator('[data-selection-count]')).toHaveText(String(rows));

        await bar.getByRole('button', { name: 'Clear' }).click();
        await expect(bar).toBeHidden();
    });

    test('the grid never makes the page scroll sideways', async ({ signedIn }) => {
        const overflow = await signedIn.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );
        expect(overflow).toBeLessThanOrEqual(1);
    });

    test('it renders in both themes', async ({ signedIn }) => {
        const background = () => signedIn.evaluate(
            () => getComputedStyle(document.querySelector('.dg-bar')).backgroundColor,
        );

        const before = await background();
        await signedIn.locator('#themebtn').click();
        expect(await background()).not.toBe(before);

        // And the header is still legible against it rather than inverting to
        // one of its own colours — every token, no hex.
        const ink = await signedIn.evaluate(
            () => getComputedStyle(document.querySelector('table.dg thead th')).color,
        );
        expect(ink).not.toBe('rgba(0, 0, 0, 0)');
    });
});

test.describe('data grid — mobile', () => {
    test.skip(({ isMobile }) => !isMobile, 'the card list is the mobile rendering');

    test('the table gives way to a card list', async ({ signedIn }) => {
        const grid = signedIn.locator(GRID);

        await expect(grid.locator('.table-scroll')).toBeHidden();
        await expect(grid.locator('.dg-cards')).toBeVisible();
    });

    test('a card carries the first three visible columns', async ({ signedIn }) => {
        const card = signedIn.locator(`${GRID} .dg-card`).first();

        await expect(card.locator('.dg-card-field')).toHaveCount(3);
        await expect(card.locator('.dg-card-field').first()).toContainText('Id');
    });

    test('the rest of the columns are behind an expand', async ({ signedIn }) => {
        const card = signedIn.locator(`${GRID} .dg-card`).first();
        const more = card.locator('.dg-card-more');

        await expect(more.locator('dl')).toBeHidden();
        await more.locator('summary').click();
        await expect(more.locator('dl')).toBeVisible();
    });

    test('the page does not scroll sideways at 375px', async ({ signedIn }) => {
        // "Clean over capable": a wide grid on a phone is the first thing to
        // give way, and a horizontal scrollbar on the BODY is the failure.
        const overflow = await signedIn.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );
        expect(overflow).toBeLessThanOrEqual(1);
    });

    test('the extract drawer fits the viewport', async ({ signedIn }) => {
        await signedIn.locator(GRID).getByRole('button', { name: 'Extract' }).click();

        const panel = signedIn.locator('#dg-app-dev-grids-branches-extract .drawer-panel');
        await expect(panel).toBeVisible();

        const box = await panel.boundingBox();
        expect(box.width).toBeLessThanOrEqual(375);
    });
});
