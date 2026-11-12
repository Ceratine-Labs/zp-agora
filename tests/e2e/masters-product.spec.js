import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { signInThroughTheForm } from './support/fixtures.js';

/**
 * Setup → Trading rules → Stock recon master (T025).
 *
 * The listing and one line's detail, driven in a real browser. What is worth
 * asserting here rather than in phpunit is the things only a render shows:
 * that the branch selector is present because an item number means nothing
 * without a site, that the procedure name is on the page (feature-rules §3.4),
 * that the provenance notice actually explains where the columns come from,
 * and that clicking an item opens it.
 */
test.describe('Stock recon master', () => {
    test('the listing renders with its scope, its provenance and its procedure named', async ({ page }) => {
        await page.goto('/app/master/stock');

        await expect(page.getByRole('heading', { name: 'Stock recon master', level: 1 })).toBeVisible();

        // The branch selector. An item number is per site — "item 10" is
        // twenty-two different products across the estate — so a listing
        // without a scope control is a listing nobody can read.
        await expect(page.locator('.scopebar')).toBeVisible();

        // feature-rules §3.4: the customer can see which procedure produced
        // this, so they can open it themselves.
        await expect(page.getByText('usp_Product_GridStockItems')).toBeVisible();

        // And nothing above the grid but the title. The eyebrow, the blurb and
        // the collapsible provenance panel were removed on 21 September —
        // they took half the screen before the first row, and the provenance
        // they explained is on the columns themselves.
        await expect(page.getByText('Where each column comes from')).toHaveCount(0);
        await expect(page.getByText('Every stock line a site carries')).toHaveCount(0);
    });

    test('the columns that carry the answer are the ones that start visible', async ({ page, isMobile }) => {
        test.skip(isMobile, 'the table is the desktop rendering — a phone gets the card list');

        await page.goto('/app/master/stock');

        for (const heading of ['Item', 'Description', 'POS code', 'POS system', 'Counting area', 'Sell (incl)', 'Cost (excl)', 'GP %', 'Status']) {
            await expect(page.getByRole('columnheader', { name: heading, exact: false }).first()).toBeVisible();
        }
    });

    test('a header filter narrows the list rather than quietly doing nothing', async ({ page, isMobile }) => {
        await page.goto('/app/master/stock');

        const rows = () => isMobile ? page.locator('.dg-card') : page.locator('tbody tr');

        test.skip(await rows().count() === 0, 'No stock master rows in this environment.');

        // A value that matches nothing is the only filter test worth writing:
        // one that matches something passes whether the filter works or not,
        // which is exactly how usp_Core_GridUsers shipped with every filter
        // inert for weeks.
        await page.goto('/app/master/stock?' + new URLSearchParams({ 'f[StockItemDescription][q]': 'ZZZZ-NO-SUCH-PRODUCT' }));

        await expect(rows()).toHaveCount(0);
    });

    test('an item opens its own page, and that page says where each number came from', async ({ page, isMobile }) => {
        await page.goto('/app/master/stock');

        // The table is the desktop rendering; a phone gets a card list, and
        // the way IN has to work on both or the screen is desk-only.
        const firstItem = isMobile
            ? page.locator('.dg-card a').first()
            : page.locator('tbody tr td a').first();

        test.skip(await firstItem.count() === 0, 'No stock master rows in this environment.');

        await firstItem.click();

        await expect(page.getByText('What this line is')).toBeVisible();
        await expect(page.getByText('How the counting behaves')).toBeVisible();

        // Provenance, not decoration: the detail page has to say whether the
        // row it is showing is the customer's or an Agora override.
        await expect(page.getByText('Held by')).toBeVisible();
    });

    test('a reader who cannot write is not offered the batch flag action', async ({ page }) => {
        // The fixture user is an Auditor — master.stock.view, not
        // master.stock.edit. feature-rules §4: the control is not drawn at
        // all, rather than drawn and then refused. Asserted on the LISTING
        // because that is where the batch action lives, beside the editor
        // assertion below which covers the detail page.
        await page.goto('/app/master/stock');

        await expect(page.getByRole('button', { name: 'Set a flag…' })).toHaveCount(0);
        await expect(page.locator('dialog#stock-flags')).toHaveCount(0);

        // The tick boxes themselves stay: selecting rows is not writing, and
        // a reader who cannot act on a selection still has one from the
        // grid's own bar.
        await expect(page.locator('[data-check-all]')).toBeVisible();
    });

    test('a reader who cannot write is not offered the editor', async ({ page, isMobile }) => {
        // The fixture user is an Auditor: master.stock.view, not
        // master.stock.edit. feature-rules §4 — a control the user cannot use
        // is not rendered, rather than rendered and then refused.
        await page.goto('/app/master/stock');

        // The table is still in the DOM on a phone, just hidden — so a
        // combined selector picks the invisible one and the click hangs.
        const firstItem = isMobile
            ? page.locator('.dg-card a').first()
            : page.locator('tbody tr td a').first();

        test.skip(await firstItem.count() === 0, 'No stock master rows in this environment.');

        await firstItem.click();

        await expect(page.getByRole('button', { name: 'Edit' })).toHaveCount(0);
        await expect(page.locator('dialog#edit-stock-item')).toHaveCount(0);
    });
});

/**
 * Setup → Trading rules → Critical lines (T025).
 *
 * The local stub carries no critical rows — 7 of the 22 live sites have no
 * critical list either — so the assertions that need data skip rather than
 * fail, and the ones about the screen itself always run.
 */
test.describe('Critical lines', () => {
    test('the screen leads with how many are out of stock, and names its procedure', async ({ page }) => {
        await page.goto('/app/master/critical');

        await expect(page.getByRole('heading', { name: 'Critical lines', level: 1 })).toBeVisible();

        // The count is the question people arrive holding. It is a KPI rather
        // than something to be found by sorting a grid.
        await expect(page.getByText('Out of stock now')).toBeVisible();

        // feature-rules §3.4.
        await expect(page.getByText('usp_Product_GridCriticalLines')).toBeVisible();

        // The screen has to say what the list IS — keyed to the POS file, not
        // the stock master — or a third of it looks like missing data. Since
        // 4 Oct 2026 that is behind the info button beside the title.
        await page.locator('.page-head details.info summary').click();
        await expect(page.getByText('Keyed to the POS file, not the stock master.')).toBeVisible();
    });

    test('the branch selector is there, because the list differs at every site', async ({ page }) => {
        await page.goto('/app/master/critical');
        await expect(page.locator('.scopebar')).toBeVisible();
    });

    test('an empty list is an answer, not a broken page', async ({ page, isMobile }) => {
        await page.goto('/app/master/critical');

        const rows = isMobile ? page.locator('.dg-card') : page.locator('tbody tr');
        test.skip(await rows.count() > 0, 'This environment has critical lines; the empty state is not on screen.');

        // 7 of the 22 live sites have no critical list at all, so this is a
        // state real users will meet.
        await expect(page.getByText('Out of stock now')).toBeVisible();

        // <x-data-grid>'s own empty text. An empty tbody is zero-height and
        // therefore 'hidden' to a visibility assertion, which is why this
        // asserts the sentence rather than the container.
        await expect(page.getByText('Nothing matched')).toBeVisible();
    });
});

/**
 * Setup → Trading rules → Fuel matrix (T025).
 *
 * The first block runs as the Auditor fixture against whatever the local
 * stub holds, which carries no fuel prices: what it proves is the screen's
 * shape — both procedures named, the price-change log and the summary
 * present, no write control anywhere, the entry under Setup.
 *
 * The second block is the acceptance: Elephant Coast's August pump prices,
 * captured read-only from the customer's BRN_FuelPrice and replayed into the
 * local stub at TEST branch 999 by support/fuel-fixture.php, must show both
 * August moves — 05 Aug ULP 95 25.54 → 25.02 and both diesels 27.77 → 29.01;
 * 13 Aug both diesels 29.01 → 29.10. Opt-in, because it writes local fixture
 * rows: AGORA_FUEL_E2E=1 with AGORA_FUEL_POS_EVIDENCE and
 * AGORA_FUEL_DOE_EVIDENCE pointing at the two capture files.
 */
test.describe('Fuel matrix', () => {
    test('the matrix names its procedures, logs pump moves and offers no write', async ({ page }) => {
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto('/app/fuel/matrix?from=2026-08-01&to=2026-08-31');

        await expect(page.getByRole('heading', { name: 'Fuel matrix', level: 1 })).toBeVisible();
        await expect(page.getByText('usp_Fuel_GridPrices').first()).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Pump prices by grade' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Price-change log' })).toBeVisible();
        await expect(page.getByText('agora.usp_Fuel_GetPriceMoves').first()).toBeVisible();

        // feature-rules §4: prices are read-only today, so nothing in the page
        // body edits, saves or deletes.
        const main = page.locator('main');
        for (const name of [/^Edit/, /^Save/, /^Delete/]) {
            await expect(main.getByRole('button', { name })).toHaveCount(0);
        }
        await expect(main.locator('form[method="POST" i], [data-post]')).toHaveCount(0);

        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
        expect(errors).toEqual([]);
    });

    test('Setup → Trading rules carries the fuel matrix beside the other masters', async ({ page, isMobile }) => {
        test.skip(isMobile, 'the mega menu is the desktop navigation');
        await page.goto('/app/master/stock');
        await page.locator('[data-menu="setup"]').click();
        const panel = page.locator('[data-panel="setup"]');
        await expect(panel.getByRole('link', { name: 'Fuel matrix' }).first()).toHaveAttribute('href', /\/app\/fuel\/matrix/);
        await expect(panel.getByRole('link', { name: 'Critical lines' }).first()).toBeVisible();
    });

    test.describe('August at Elephant Coast, replayed', () => {
        test.use({ storageState: { cookies: [], origins: [] } });

        test('both August pump moves are on the log, with the mid-day change marked', async ({ page, baseURL }, testInfo) => {
            test.skip(process.env.AGORA_FUEL_E2E !== '1', 'Requires the explicit local replay of the read-only capture.');
            expect(['127.0.0.1', 'localhost', '[::1]']).toContain(new URL(baseURL).hostname);
            test.setTimeout(120_000);
            const token = randomBytes(6).toString('hex');
            const fixture = (action) => JSON.parse(execFileSync('php', [
                fileURLToPath(new URL('./support/fuel-fixture.php', import.meta.url)), action, token,
            ], { encoding: 'utf8', env: process.env }));
            const prepared = fixture('prepare');
            const errors = [];
            page.on('pageerror', (error) => errors.push(error.message));
            const evidence = { pos_rows: prepared.pos_rows, doe_rows: prepared.doe_rows, sql_host: prepared.sql_host, moves: [] };

            try {
                await signInThroughTheForm(page, prepared);
                await page.waitForURL('**/app/fuel/matrix');
                await page.goto('/app/fuel/matrix?from=2026-08-01&to=2026-08-31');

                const log = page.locator('table[data-fuel-table="moves"]');
                const row = (date, grade) => log.locator('tbody tr').filter({ hasText: date }).filter({ hasText: grade });
                for (const [date, grade, from, to] of [
                    ['2026-08-05', 'ULP 95 (1)', '25.54', '25.02'],
                    ['2026-08-05', 'Diesel 10 ppm (2)', '27.77', '29.01'],
                    ['2026-08-05', 'Diesel 10 ppm (5)', '27.77', '29.01'],
                    ['2026-08-13', 'Diesel 10 ppm (2)', '29.01', '29.10'],
                    ['2026-08-13', 'Diesel 10 ppm (5)', '29.01', '29.10'],
                ]) {
                    const move = row(date, grade);
                    await expect(move).toHaveCount(1);
                    await expect(move.locator('td').nth(3)).toHaveText(from);
                    await expect(move.locator('td').nth(4)).toHaveText(to);
                    evidence.moves.push({ date, grade, from, to });
                }
                await expect(log.locator('tbody tr:not(.tt-empty)')).toHaveCount(5);
                await expect(row('2026-08-13', 'Diesel 10 ppm (2)')).toContainText('Old and new both recorded this day');
                await expect(row('2026-08-05', 'ULP 95 (1)')).not.toContainText('Old and new');

                const summary = page.locator('table[data-fuel-table="summary"] tbody tr').filter({ hasText: 'Diesel 10 ppm (2)' });
                await expect(summary.locator('td').nth(3)).toHaveText('27.77');
                await expect(summary.locator('td').nth(4)).toHaveText('29.10');
                await expect(summary.locator('td').nth(7)).toHaveText('2');

                // The DOE matrix above it still carries the one DOE move.
                await expect(page.locator('[data-grid]')).toContainText('25.02');
                await page.screenshot({ path: testInfo.outputPath(`fuel-matrix-moves-${testInfo.project.name}.png`), fullPage: true });
                expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
                expect(errors).toEqual([]);
            } finally {
                await page.context().close();
                evidence.cleanup = fixture('cleanup');
                writeFileSync(testInfo.outputPath('fuel-matrix-evidence.json'), JSON.stringify(evidence, null, 2));
                expect(evidence.cleanup.pos_remaining).toBe(0);
                expect(evidence.cleanup.doe_remaining).toBe(0);
                expect(evidence.cleanup.users_remaining).toBe(0);
            }
        });
    });
});
