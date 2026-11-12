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
    test('a separate local editor validates, saves and refreshes an override while the reader stays denied', async ({ page, browser, baseURL }, testInfo) => {
        test.skip(process.env.AGORA_STOCK_EDITOR_E2E !== '1', 'This write-path test requires an explicit local fixture opt-in.');
        expect(['127.0.0.1', 'localhost', '[::1]']).toContain(new URL(baseURL).hostname);
        test.setTimeout(120_000);

        const token = randomBytes(6).toString('hex');
        const fixture = (action) => JSON.parse(execFileSync('php', [
            fileURLToPath(new URL('./support/stock-editor-fixture.php', import.meta.url)), action, token,
        ], { encoding: 'utf8', env: process.env }));
        const prepared = fixture('prepare');
        const evidence = { day_list_id: process.env.AGORA_DAY_LIST_ID ?? null, token, sql_host: prepared.sql_host };
        let editorContext;
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });

        try {
            writeFileSync(testInfo.outputPath('stock-editor-evidence.json'), JSON.stringify(evidence, null, 2));
            await page.goto(prepared.path);
            await expect(page.getByRole('heading', { name: prepared.description, exact: true })).toBeVisible();
            await expect(page.getByRole('button', { name: 'Edit', exact: true })).toHaveCount(0);
            await expect(page.locator('dialog#edit-stock-item')).toHaveCount(0);
            const denied = await page.request.put(prepared.path, { form: {
                _token: await page.locator('meta[name="csrf-token"]').getAttribute('content'),
                action: 'save', StockItemNo: prepared.item, reason: prepared.reason,
                StockItemDescription: prepared.savedDescription, AreaNo: prepared.area,
                PosSystem: 'WINBRANCH', POSCode: prepared.posCode, PriceType: 'Set Price', UOMCode: 'Each',
            } });
            expect(denied.status()).toBe(403);
            evidence.reader_status = denied.status();
            expect(fixture('snapshot').override_count).toBe(0);
            await page.screenshot({ path: testInfo.outputPath('stock-reader-denied.png'), fullPage: true });

            editorContext = await browser.newContext({ baseURL, viewport: page.viewportSize(), storageState: { cookies: [], origins: [] } });
            const editor = await editorContext.newPage();
            editor.on('pageerror', (error) => errors.push(error.message));
            editor.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
            await signInThroughTheForm(editor, { email: prepared.email, password: prepared.password });
            await editor.waitForURL('**/app/master/stock');
            await editor.goto(prepared.path);
            const dialog = editor.getByRole('dialog', { name: 'Change this stock line' });
            const open = async () => {
                await editor.getByRole('button', { name: 'Edit', exact: true }).click();
                await expect(dialog).toBeVisible();
            };
            const save = async () => {
                const [submitted, loaded] = await Promise.all([
                    editor.waitForResponse((response) => response.url().endsWith(prepared.path) && response.request().method() === 'POST'),
                    editor.waitForResponse((response) => response.url().endsWith(prepared.path) && response.request().method() === 'GET'),
                    dialog.getByRole('button', { name: 'Save the override', exact: true }).click(),
                ]);
                expect(submitted.status()).toBe(302);
                expect(loaded.status(), 'the saved or refused form renders its detail page').toBe(200);
                await expect(dialog).not.toBeVisible();
            };

            await open();
            await dialog.getByLabel('Description', { exact: true }).fill(prepared.savedDescription);
            await dialog.getByLabel('Sell (incl VAT)', { exact: true }).fill('42.1250');
            await dialog.getByLabel('Why', { exact: true }).fill('');
            await save();
            await open();
            await expect(dialog.getByText('Say why you are changing this line. It is kept with the change.', { exact: true })).toBeVisible();
            expect(fixture('snapshot').override_count).toBe(0);
            await dialog.screenshot({ path: testInfo.outputPath('stock-editor-validation.png') });

            await dialog.getByLabel('Why', { exact: true }).fill(prepared.reason);
            await dialog.getByLabel('POS code', { exact: true }).fill(prepared.collisionCode);
            await save();
            await expect(editor.getByText('That change was refused', { exact: true })).toBeVisible();
            await expect(editor.getByText('Another line at this site already uses that POS code on that POS system.', { exact: true })).toBeVisible();
            expect(fixture('snapshot').override_count).toBe(0);
            await open();
            await expect(dialog.getByLabel('Description', { exact: true })).toHaveValue(prepared.savedDescription);
            await dialog.getByLabel('POS code', { exact: true }).fill(prepared.posCode);
            await save();
            await expect(editor.getByText('Saved', { exact: true })).toBeVisible();
            await expect(editor.getByRole('heading', { name: prepared.savedDescription, exact: true })).toBeVisible();
            await editor.reload();
            await expect(editor.getByRole('heading', { name: prepared.savedDescription, exact: true })).toBeVisible();
            const source = editor.locator('.compare-side').filter({ has: editor.getByRole('heading', { name: "PumpIT — the customer's row", exact: true }) });
            const override = editor.locator('.compare-side').filter({ has: editor.getByRole('heading', { name: 'Agora — the override', exact: true }) });
            await expect(source.getByText(prepared.description, { exact: true })).toBeVisible();
            await expect(source.getByText('R29.99', { exact: true })).toBeVisible();
            await expect(override.getByText(prepared.savedDescription, { exact: true })).toBeVisible();
            await expect(override.getByText('R42.13', { exact: true })).toBeVisible();
            await expect(override).toHaveClass(/is-live/);
            await editor.locator('.compare').screenshot({ path: testInfo.outputPath('stock-editor-comparison.png') });
            await open();
            await expect(dialog.getByLabel('Description', { exact: true })).toHaveValue(prepared.savedDescription);
            await expect(dialog.getByLabel('Sell (incl VAT)', { exact: true })).toHaveValue('42.1250');
            await expect(dialog.getByLabel('POS code', { exact: true })).toHaveValue(prepared.posCode);
            evidence.saved = fixture('snapshot');
            expect(evidence.saved.override_count).toBe(1);
            expect(evidence.saved.override.StockItemDescription).toBe(prepared.savedDescription);
            expect(Number(evidence.saved.override.SellingPrice)).toBe(42.125);
            expect(evidence.saved.override.Reason).toBe(prepared.reason);
            expect(evidence.saved.source.StockItemDescription).toBe(prepared.description);
            expect(Number(evidence.saved.source.SellingPrice)).toBe(29.99);
            await dialog.screenshot({ path: testInfo.outputPath('stock-editor-persisted.png') });
            await page.reload();
            await expect(page.getByRole('heading', { name: prepared.savedDescription, exact: true })).toBeVisible();
            await expect(page.getByRole('button', { name: 'Edit', exact: true })).toHaveCount(0);
            expect(errors, 'editor browser console and page errors').toEqual([]);
        } finally {
            try {
                await editorContext?.close();
            } finally {
                try {
                    evidence.cleanup = fixture('cleanup');
                } finally {
                    writeFileSync(testInfo.outputPath('stock-editor-evidence.json'), JSON.stringify(evidence, null, 2));
                }
            }
            await testInfo.attach('stock-editor-evidence', { path: testInfo.outputPath('stock-editor-evidence.json'), contentType: 'application/json' });
            expect(Object.values(evidence.cleanup.remaining)).toEqual([0, 0, 0, 0]);
            expect(evidence.cleanup.auditor).toEqual(prepared.auditor);
        }
    });

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

    test('an item opens its own page, and that page says where each number came from', async ({ page, isMobile }, testInfo) => {
        test.setTimeout(120_000); // Two navigations, four screenshots and both close methods per breakdown.
        const errors = [];
        const breakdowns = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
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

        const cards = page.locator('.kpis button.kpi');
        await expect(cards).toHaveCount(4);
        await expect(cards.locator('.kpi-art svg')).toHaveCount(4);
        for (const title of ['What this line is', 'What it costs and makes', 'How the counting behaves']) {
            const panel = page.locator('.card').filter({ has: page.getByRole('heading', { name: title }) });
            await expect(panel.locator(':scope > .card-b')).toHaveCSS('padding', '0px');
        }
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await page.locator('.kpis').screenshot({ path: testInfo.outputPath('stock-detail-cards.png') });

        // A breakdown must agree with its headline, retain its source context,
        // and return focus after either keyboard or visible close-button use.
        for (const [name, title, factLabel, context] of [
            ['Sell (incl VAT)', 'Selling price', 'Master sell (incl VAT)', "From the site's POS cost file."],
            ['Cost (excl VAT)', 'Cost and margin', 'Cost (excl VAT)', '(POS sell less cost) ÷ POS sell; both prices exclude VAT.'],
            ['On hand', 'Stock on hand', 'On hand', "From the site's POS cost file."],
            ['Last counted', 'Counting history', 'Last counted', 'All-time counts'],
        ]) {
            const card = cards.filter({ has: page.locator('.lbl', { hasText: name }) });
            const headline = (await card.locator('.val').innerText()).trim();
            await expect(card.locator('.kpi-art')).toBeVisible();
            expect(await card.evaluate((node) => getComputedStyle(node).backgroundImage)).toContain('linear-gradient');
            await card.click();
            const dialog = page.getByRole('dialog', { name: title, exact: true });
            await expect(dialog).toBeVisible();
            const fact = dialog.locator('.fact').filter({ has: page.getByText(factLabel, { exact: true }) });
            await expect(fact.locator('.fact-value')).toHaveText(headline);
            await expect(dialog.getByText(context, { exact: true })).toBeVisible();
            const close = dialog.getByRole('button', { name: 'Close', exact: true });
            const contrast = await close.evaluate((node) => {
                const luminance = (color) => color.match(/[\d.]+/g).slice(0, 3)
                    .map((value) => Number(value) / 255)
                    .map((value) => value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4)
                    .reduce((total, value, index) => total + value * [0.2126, 0.7152, 0.0722][index], 0);
                const foreground = getComputedStyle(node).color;
                const background = getComputedStyle(node.closest('.modal-head')).backgroundColor;
                const [low, high] = [luminance(foreground), luminance(background)].sort((left, right) => left - right);
                return { foreground, background, ratio: (high + 0.05) / (low + 0.05) };
            });
            expect(contrast.ratio, `${title}: visible Close icon contrast`).toBeGreaterThanOrEqual(3);
            breakdowns.push({ title, headline, close: contrast });
            await dialog.screenshot({ path: testInfo.outputPath(`${await dialog.getAttribute('id')}.png`) });
            await page.keyboard.press('Escape');
            await expect(dialog).not.toBeVisible();
            await expect(card).toBeFocused();
            await card.click();
            await dialog.getByRole('button', { name: 'Close', exact: true }).click();
            await expect(dialog).not.toBeVisible();
            await expect(card).toBeFocused();
        }
        expect(errors, 'stock card browser console and page errors').toEqual([]);
        await testInfo.attach('stock-detail-evidence', { body: JSON.stringify({
            day_list_id: process.env.AGORA_DAY_LIST_ID ?? null, path: new URL(page.url()).pathname, breakdowns,
        }, null, 2), contentType: 'application/json' });
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
