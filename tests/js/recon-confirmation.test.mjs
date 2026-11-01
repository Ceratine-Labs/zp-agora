import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from '@playwright/test';
import { cachedChromium } from '../e2e/support/chromium.js';

// Only the changed recon components, in a real browser with no server or DB.
test('cash-bag confirmation requires an explicit target and carries exact changes', async () => {
    const browser = await chromium.launch({ executablePath: cachedChromium() });
    try {
        const page = await browser.newPage();
        await page.route('http://recon.test/**', async route => {
            const name = new URL(route.request().url()).pathname.slice(1);
            const source = ['recon-match.js', 'recon-run-filters.js'].includes(name)
                ? await readFile(new URL(`../../resources/js/components/${name}`, import.meta.url), 'utf8') : '';
            await route.fulfill({ contentType: name.endsWith('.js') ? 'text/javascript' : 'text/html', body: source });
        });
        await page.goto('http://recon.test/');
        const bag = (id, key, amount) => `<tr data-cashier-code="BAOWAIT006" data-cashier-name="Cashier" data-amount="${amount}"><td><input checked type="checkbox" data-pane-pick="mops" value='${JSON.stringify({ id, key, amt: amount })}'></td></tr>`;
        await page.setContent(`<form data-confirm-text="Write confirmed changes to PumpIT">
            <div data-match-allocation data-bag-writes="1"><input data-alloc-json><input data-bag-adjust-json>
            <span data-alloc-head></span><table><tbody></tbody></table><span data-alloc-state></span><button type="button" data-alloc-reset></button></div>
            <div data-two-pane><table><tbody><tr data-amount="895"><td><input type="checkbox" checked data-pane-pick="bank"></td></tr>
            ${bag(45395, '390067308257', 592)}${bag(45396, 'OTHER', 368)}</tbody></table></div>
            <button type="submit" data-match-submit>Match</button></form>`);
        await page.evaluate(async () => { const { default: init } = await import('/recon-match.js'); init(); });
        assert.equal(await page.locator('[data-match-submit]').isDisabled(), true);
        await page.locator('[data-alloc-target]').selectOption('45395');
        assert.equal(await page.locator('[data-match-submit]').isDisabled(), false);
        const changes = JSON.parse(await page.locator('[data-bag-adjust-json]').inputValue());
        assert.deepEqual(changes.map(({id, amount, share}) => ({id, amount, share})), [
            { id: 45395, amount: '592.00', share: '-65.00' }, { id: 45396, amount: '368.00', share: '0.00' },
        ]);
        const confirmation = await page.locator('form').getAttribute('data-confirm-text');
        assert.match(confirmation, /390067308257.*R592.00 → R527.00; AdjustedBy changes by R65.00/);
        await page.locator('[data-alloc-share]').fill('-600');
        assert.equal(await page.locator('[data-match-submit]').isDisabled(), true);
        // Same totals, different bag identity: a changed selection resets the target.
        await page.locator('[data-pane-pick="mops"]').first().uncheck();
        await page.locator('[data-pane-pick="mops"]').first().check();
        assert.equal(await page.locator('[data-alloc-target]').inputValue(), '');
        assert.equal(await page.locator('[data-match-submit]').isDisabled(), true);
    } finally { await browser.close(); }
});

test('a changed run status submits the header filter immediately and only once', async () => {
    const browser = await chromium.launch({ executablePath: cachedChromium() });
    try {
        const page = await browser.newPage();
        await page.setContent(`<form id="scope"></form><div data-grid-key="app.recon.runs"><table><tbody><tr class="dg-filters"><td>
            <select form="scope" name="f[Status][in][]"><option value="previewed">Previewed</option><option value="committed">Committed</option></select>
            </td></tr></tbody></table></div>`);
        const source = await readFile(new URL('../../resources/js/components/recon-run-filters.js', import.meta.url), 'utf8');
        await page.addScriptTag({ type: 'module', content: source.replace('export default function', 'window.reconRunFilters = function') });
        await page.waitForFunction(() => window.reconRunFilters);
        await page.evaluate(() => {
            window.submissions = [];
            document.querySelector('form').addEventListener('submit', event => {
                event.preventDefault(); window.submissions.push([new FormData(event.target).get('f[Status][in][]'), new FormData(event.target).get('show')]);
            });
            window.reconRunFilters(); window.reconRunFilters();
        });
        await page.locator('select').selectOption('committed');
        assert.deepEqual(await page.evaluate(() => window.submissions), [['committed', 'all']]);
    } finally { await browser.close(); }
});
