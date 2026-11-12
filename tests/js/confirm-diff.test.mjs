import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from '@playwright/test';
import { cachedChromium } from '../e2e/support/chromium.js';

// confirm-diff.js in a real browser, no server or DB: the text it writes into
// data-confirm-text is what the SweetAlert2 dialog shows before a PumpIT
// write-back. These strings are the ones the People partial wrote before it
// became a shared module (12 Nov 2026); they must not drift.
const FORM = `
<form data-confirm="Save to PumpIT?" data-diff="PumpIT BRN_Employee · TPW01 Zulu Thandi" data-diff-note="The server checks again.">
  <label data-diff-field="IsActive" data-diff-old="1" data-diff-on="Active" data-diff-off="Inactive">
    <input type="hidden" name="IsActive" value="0"><input type="checkbox" name="IsActive" value="1" checked></label>
  <select name="PostNo" data-diff-field="PostNo" data-diff-old="18">
    <option value="18">Fuel Cashier</option><option value="46">Site Manager</option></select>
  <div data-diff-field="NickName" data-diff-old=""><input name="NickName" value=""></div>
  <input type="checkbox" data-diff-area="Fuel bay" data-diff-old="0">
  <input type="checkbox" data-diff-area="Shop" data-diff-old="1" checked>
  <button type="submit">Save</button>
</form>`;

async function page(browser) {
    const p = await browser.newPage();
    await p.route('http://diff.test/**', async (route) => {
        const source = await readFile(new URL('../../resources/js/components/confirm-diff.js', import.meta.url), 'utf8');
        const script = new URL(route.request().url()).pathname === '/confirm-diff.js';
        await route.fulfill({ contentType: script ? 'text/javascript; charset=utf-8' : 'text/html; charset=utf-8', body: script ? source : FORM });
    });
    await p.goto('http://diff.test/');
    await p.evaluate(async () => {
        const { default: confirmDiff } = await import('/confirm-diff.js');
        confirmDiff();
        confirmDiff(); // a second pass (hydrate) must not wire the form twice
        document.querySelector('form').addEventListener('submit', (e) => e.preventDefault());
    });
    return p;
}

const press = async (p) => {
    await p.locator('button[type=submit]').click();
    return p.locator('form').getAttribute('data-confirm-text');
};

test('nothing changed reads as such, with the note', async () => {
    const browser = await chromium.launch({ executablePath: cachedChromium() });
    try {
        const p = await page(browser);
        assert.equal(await press(p), 'PumpIT BRN_Employee · TPW01 Zulu Thandi\nNothing will change.\n\nThe server checks again.');
        assert.equal(await p.locator('form[data-diff-ready]').count(), 1);
    } finally { await browser.close(); }
});

test('each change is named old → new, areas added and removed', async () => {
    const browser = await chromium.launch({ executablePath: cachedChromium() });
    try {
        const p = await page(browser);
        await p.locator('input[name=IsActive][type=checkbox]').uncheck();
        await p.locator('select[name=PostNo]').selectOption('46');
        await p.locator('input[name=NickName]').fill('  Bee ');
        await p.locator('[data-diff-area="Fuel bay"]').check();
        await p.locator('[data-diff-area="Shop"]').uncheck();
        assert.equal(await press(p), [
            'PumpIT BRN_Employee · TPW01 Zulu Thandi',
            'IsActive: Active → Inactive',
            'PostNo: Fuel Cashier → Site Manager',
            'NickName: blank → Bee',
            'Area added: Fuel bay',
            'Area removed: Shop',
            '',
            'The server checks again.',
        ].join('\n'));
    } finally { await browser.close(); }
});
