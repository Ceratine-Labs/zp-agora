import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from '@playwright/test';
import { cachedChromium } from '../e2e/support/chromium.js';

test('mobile and desktop grid selection share one batch, including select-page and clear', async () => {
    const browser = await chromium.launch({ executablePath: cachedChromium() });
    try {
        const page = await browser.newPage({ viewport: { width: 375, height: 812 } });
        await page.route('http://grid.test/**', async route => {
            const name = new URL(route.request().url()).pathname.slice(1);
            const source = ['check-all.js', 'data-grid.js', 'bulk-selection.js'].includes(name)
                ? await readFile(new URL(`../../resources/js/components/${name}`, import.meta.url), 'utf8') : '';
            await route.fulfill({ contentType: name.endsWith('.js') ? 'text/javascript' : 'text/html', body: source });
        });
        await page.goto('http://grid.test/');
        await page.setContent(`<style>
            .cards { display: none; }
            @media (max-width: 720px) { table { display: none; } .cards { display: block; } }
            </style>
            <section data-fragment><div data-grid data-grid-key="app.setup.users">
                <div data-selection hidden><span data-selection-count>0</span><button data-selection-clear>Clear</button></div>
                <table class="dg"><thead><tr><th><input type="checkbox" data-check-all></th></tr></thead>
                <tbody>${[11, 12, 13].map(id => `<tr><td><input type="checkbox" data-check value="${id}"></td></tr>`).join('')}</tbody></table>
                <div class="cards"><input type="checkbox" data-card-check-all>
                    ${[11, 12, 13].map(id => `<input type="checkbox" data-card-check value="${id}">`).join('')}</div>
            </div></section>
            <form data-bulk-form="app.setup.users"><div data-bulk-items></div>
                <span data-bulk-count>0</span><button type="submit">Apply</button></form>`);
        await page.evaluate(async () => {
            const { default: checkAll } = await import('/check-all.js');
            const { default: bulkSelection } = await import('/bulk-selection.js');
            const { default: dataGrid } = await import('/data-grid.js');
            checkAll(); bulkSelection();
            const fragment = document.querySelector('[data-fragment]');
            dataGrid(fragment);
            dataGrid(fragment);
            dataGrid();
            window.gridChanges = 0;
            document.querySelector('table').addEventListener('change', () => { window.gridChanges += 1; });
        });
        const selected = () => page.locator('[name="items[]"]').evaluateAll(inputs => inputs.map(input => input.value));
        const states = selector => page.locator(selector).evaluateAll(inputs => inputs.map(input => ({ checked: input.checked, indeterminate: input.indeterminate })));
        const masters = '[data-check-all], [data-card-check-all]';

        assert.equal(await page.locator('button[type="submit"]').isDisabled(), true);
        await page.locator('[data-card-check][value="11"]').check();
        assert.equal(await page.evaluate(() => window.gridChanges), 1, 'Repeated fragment hydration must not duplicate selection handlers.');
        assert.deepEqual(await selected(), ['11']);
        assert.deepEqual(await states(masters), [{ checked: false, indeterminate: true }, { checked: false, indeterminate: true }]);
        assert.equal(await page.locator('[data-selection-count]').textContent(), '1');

        await page.locator('[data-card-check-all]').check();
        assert.deepEqual(await selected(), ['11', '12', '13']);
        assert.equal(await page.locator('[data-bulk-count]').textContent(), '3');
        assert.deepEqual(await states(masters), [{ checked: true, indeterminate: false }, { checked: true, indeterminate: false }]);

        await page.locator('[data-selection-clear]').click();
        assert.deepEqual(await selected(), []);
        assert.equal(await page.locator('[data-selection]').isHidden(), true);
        assert.equal(await page.locator('button[type="submit"]').isDisabled(), true);
        assert.deepEqual(await states(masters), [{ checked: false, indeterminate: false }, { checked: false, indeterminate: false }]);

        await page.setViewportSize({ width: 1280, height: 800 });
        await page.locator('[data-check-all]').check();
        assert.deepEqual(await selected(), ['11', '12', '13'], 'The native header event updates the existing bulk form.');
        assert.equal(await page.locator('[data-selection-count]').textContent(), '3');
        await page.locator('[data-check][value="12"]').uncheck();
        assert.deepEqual(await selected(), ['11', '13']);
        assert.deepEqual(await states('[data-card-check]'), [
            { checked: true, indeterminate: false }, { checked: false, indeterminate: false }, { checked: true, indeterminate: false },
        ]);

        // Client-filtered rows cannot remain in the submitted selection.
        await page.locator('[data-check][value="13"]').evaluate(input => {
            input.disabled = true;
            input.closest('table').dispatchEvent(new Event('table-tools:change', { bubbles: true }));
        });
        assert.deepEqual(await selected(), ['11']);
        assert.equal(await page.locator('[data-selection-count]').textContent(), '1');
        assert.equal(await page.locator('[data-card-check][value="13"]').isDisabled(), true);
        await page.setViewportSize({ width: 375, height: 812 });
        await page.locator('[data-card-check-all]').check();
        assert.deepEqual(await selected(), ['11', '12']);
        assert.equal(await page.locator('[data-bulk-count]').textContent(), '2');
    } finally { await browser.close(); }
});
