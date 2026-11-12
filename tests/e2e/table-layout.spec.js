import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';

test('ordinary table keeps layout and filtering does not change selection semantics', async ({ page }) => {
    const tools = (await readFile('resources/js/components/table-tools.js')).toString('base64');
    const layout = (await readFile('resources/js/components/table-layout.js')).toString('base64');
    const markup = `<meta name="csrf-token" content="test"><div class="table-block" data-table-layout-url="/layout"><div class="table-scroll"><table class="dt" data-table-tools><thead><tr><th class="pick"><input type="checkbox"></th><th>Site</th><th>Amount</th></tr></thead><tbody><tr><td><input type="checkbox" data-check checked></td><td>Zulu</td><td>20</td></tr><tr><td><input type="checkbox" data-check checked></td><td>Alpha</td><td>10</td></tr></tbody></table></div></div>`;
    await page.setContent(markup);
    await page.evaluate(async ({ tools, layout }) => {
        window.savedTableLayout = {};
        window.fetch = async (_url, options = {}) => {
            if (options.method === 'POST') window.savedTableLayout = JSON.parse(options.body);
            if (options.method === 'DELETE') window.savedTableLayout = {};
            return { ok: true, json: async () => ({ state: window.savedTableLayout }) };
        };
        window.tableToolsModule = await import(`data:text/javascript;base64,${tools}`);
        window.tableLayoutModule = await import(`data:text/javascript;base64,${layout}`);
        window.tableToolsModule.default(document);
        await window.tableLayoutModule.default(document);
    }, { tools, layout });
    await page.locator('.tl-chooser summary').click();
    await page.getByRole('button', { name: 'Move Amount earlier' }).click();
    await expect(page.locator('thead tr:first-child th').nth(1)).toContainText('Amount');
    await page.getByRole('separator', { name: 'Resize Amount' }).focus();
    await page.keyboard.press('ArrowRight');
    const width = await page.locator('thead tr:first-child th').nth(1).evaluate((th) => th.style.width);
    await page.locator('.tl-setting select').first().selectOption('large');
    await page.waitForTimeout(700);
    expect(await page.evaluate(() => window.savedTableLayout.order)).toEqual([0, 2, 1]);
    await page.evaluate(async (content) => {
        document.body.innerHTML = content;
        window.tableToolsModule.default(document);
        await window.tableLayoutModule.default(document);
    }, markup);
    await expect(page.locator('thead tr:first-child th').nth(1)).toContainText('Amount');
    expect(await page.locator('thead tr:first-child th').nth(1).evaluate((th) => th.style.width)).toBe(width);
    await expect(page.locator('.table-block')).toHaveClass(/tl-text-large/);
    // The moved Site filter must still filter on Site, not on Amount.
    await page.locator('tr.tt-filters th').nth(2).locator('input').fill('Zulu');
    await expect(page.locator('tbody tr:not(.tt-out)')).toHaveCount(1);
    await expect(page.locator('tbody tr.tt-out input[data-check]')).toBeDisabled();
    await expect(page.locator('tbody tr:not(.tt-out) input[data-check]')).toBeChecked();
    await page.evaluate(() => {
        const table = document.querySelector('table.dt');
        table.tBodies[0].innerHTML = '<tr><td><input type="checkbox" data-check checked></td><td>Zulu</td><td>30</td></tr>';
        table.dispatchEvent(new Event('table-tools:rescan'));
    });
    await expect(page.locator('tbody tr:not(.tt-out)')).toHaveCount(1);
    await expect(page.locator('tbody tr td').nth(1)).toHaveText('30');
    await expect(page.locator('tbody input[data-check]')).toBeEnabled();
    await page.locator('.tl-chooser summary').click();
    await expect(page.locator('.tl-list label').filter({ hasText: 'Site' }).locator('input')).toBeDisabled();
    await page.locator('.tl-list label').filter({ hasText: 'Amount' }).locator('input').uncheck();
    await page.evaluate(() => {
        const row = document.createElement('tr'); row.className = 'row-detail';
        const cell = document.createElement('td'); cell.colSpan = 99; row.append(cell);
        document.querySelector('tbody').append(row);
    });
    await expect(page.locator('tr.row-detail td')).toHaveAttribute('colspan', '2');
    await page.evaluate(() => {
        const table = document.querySelector('table.dt');
        table.tBodies[0].innerHTML = '<tr><td><input type="checkbox" data-check checked></td><td>Zulu</td><td><input value="30"></td></tr>';
        table.dispatchEvent(new Event('table-tools:rescan'));
    });
    await expect(page.locator('thead tr:first-child th').filter({ hasText: 'Amount' })).toBeVisible();
    await expect(page.locator('tbody input[value="30"]')).toBeVisible();
});

test('grouped headings preserve spans while a column is hidden', async ({ page }) => {
    const layout = (await readFile('resources/js/components/table-layout.js')).toString('base64');
    await page.setContent(`<meta name="csrf-token" content="test"><div class="table-block" data-table-layout-url="/layout"><div class="table-scroll"><table class="dt"><thead><tr><th colspan="2">Original</th><th>New</th></tr><tr><th>A</th><th>B</th><th>C</th></tr></thead><tbody><tr><td>1</td><td>2</td><td>3</td></tr></tbody></table></div></div>`);
    await page.evaluate(async (source) => {
        window.fetch = async () => ({ ok: true, json: async () => ({ state: {} }) });
        const module = await import(`data:text/javascript;base64,${source}`);
        await module.default(document);
    }, layout);
    await page.locator('.tl-chooser summary').click();
    await page.locator('.tl-list label').filter({ hasText: 'B' }).locator('input').uncheck();
    await expect(page.locator('thead tr:first-child th').first()).toHaveAttribute('colspan', '1');
    await expect(page.locator('thead tr:nth-child(2) th').nth(1)).toBeHidden();
    await expect(page.locator('tbody td').nth(1)).toBeHidden();
    await expect(page.locator('.tl-panel')).toContainText('Grouped columns keep their order');
    await page.locator('.tl-list label').filter({ hasText: 'B' }).locator('input').check();
    await expect(page.locator('thead tr:first-child th').first()).toHaveAttribute('colspan', '2');
});

test('loads several table preferences in one request', async ({ page }) => {
    const layout = (await readFile('resources/js/components/table-layout.js')).toString('base64');
    await page.setContent(`<meta name="csrf-token" content="test"><div class="table-block" data-table-layout-url="https://example.test/layout/one?signature=one" data-table-layout-read-url="/read"><div class="table-scroll"><table class="dt"><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table></div></div><div class="table-block" data-table-layout-url="https://example.test/layout/two?signature=two" data-table-layout-read-url="/read"><div class="table-scroll"><table class="dt"><thead><tr><th>C</th><th>D</th></tr></thead><tbody><tr><td>3</td><td>4</td></tr></tbody></table></div></div>`);
    await page.evaluate(async (source) => {
        window.batchCalls = [];
        window.fetch = async (_url, options) => {
            window.batchCalls.push(JSON.parse(options.body));
            return { ok: true, json: async () => ({ states: { one: { text_size: 'large' }, two: { height: 'compact' } } }) };
        };
        const module = await import(`data:text/javascript;base64,${source}`);
        module.default(document);
    }, layout);
    await expect(page.locator('.table-block').first()).toHaveClass(/tl-text-large/);
    await expect(page.locator('.table-block').last()).toHaveClass(/tl-height-compact/);
    expect(await page.evaluate(() => window.batchCalls)).toHaveLength(1);
});
