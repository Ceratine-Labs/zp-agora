import { test, expect } from './support/fixtures.js';

// Local TEST-reader preferences only. The runner must guard all SQL connections.
test.beforeEach(async ({ baseURL }) => {
    test.skip(process.env.AGORA_FILTER_PREF_E2E !== '1', 'Requires the guarded local filter-preference run.');
    expect(['127.0.0.1', 'localhost', '[::1]']).toContain(new URL(baseURL).hostname);
});

async function resetDisclosure(page, selector) {
    const fold = page.locator(selector);
    await expect(fold).toHaveAttribute('data-remember-ready', '');
    const key = `agora.open.${await fold.getAttribute('data-remember')}`;
    const previous = await page.evaluate((name) => localStorage.getItem(name), key);
    await page.evaluate((name) => localStorage.removeItem(name), key);
    return { key, previous };
}

async function restoreDisclosures(page, saved) {
    if (!saved.length) return;
    // A transport failure leaves an opaque error page. The test's private
    // browser context is discarded; no storage state is exported from it.
    if (await page.evaluate(() => location.origin === 'null')) return;
    await page.evaluate((items) => {
        for (const { key, previous } of items) {
            if (previous === null) localStorage.removeItem(key);
            else localStorage.setItem(key, previous);
        }
    }, saved);
}

async function toggle(page, selector, open) {
    const fold = page.locator(selector);
    await fold.locator(':scope > summary').click();
    await expect.poll(() => fold.evaluate((element) => element.open)).toBe(open);
    const key = `agora.open.${await fold.getAttribute('data-remember')}`;
    await expect.poll(() => page.evaluate((name) => localStorage.getItem(name), key)).toBe(open ? '1' : '0');
}

test('grid filter panels remember independent choices after reload', async ({ signedIn: page }, info) => {
    test.setTimeout(120_000);
    await page.goto('/app/dev/grids');
    const first = '[data-grid-key="app.dev.grids:branches"] .dg-scope-fold';
    const second = '[data-grid-key="app.dev.grids:dayclose"] .dg-scope-fold';
    const saved = [await resetDisclosure(page, first), await resetDisclosure(page, second)];
    try {
        await page.reload();
        await expect(page.locator(first)).not.toHaveAttribute('open', '');
        await expect(page.locator(second)).not.toHaveAttribute('open', '');
        await toggle(page, first, true);
        await page.reload();
        await expect(page.locator(first)).toHaveAttribute('open', '');
        await expect(page.locator(second)).not.toHaveAttribute('open', '');
        await page.screenshot({ path: info.outputPath('grid-filter-open.png'), fullPage: true });
        await toggle(page, first, false);
        await page.reload();
        await expect(page.locator(first)).not.toHaveAttribute('open', '');
    } finally {
        await restoreDisclosures(page, saved);
    }
});

test('report filters stay closed for empty results and remember each report', async ({ signedIn: page }) => {
    test.setTimeout(180_000);
    const paths = ['/app/reports/day-close?branches[]=999&q=TEST-no-matching-filter-row', '/app/reports/my-queue?branches[]=999&q=TEST-no-matching-filter-row'];
    const saved = [];
    try {
        for (const path of paths) {
            await page.goto(path);
            await expect(page.locator(path.includes('/my-queue') ? '.queue-empty' : '.table-empty')).toBeVisible();
            saved.push(await resetDisclosure(page, '.report-scope'));
        }
        expect(saved[0].key).not.toBe(saved[1].key);
        await page.goto(paths[0]);
        await expect(page.locator('.report-scope')).not.toHaveAttribute('open', '');
        await toggle(page, '.report-scope', true);
        await page.goto(paths[1]);
        await expect(page.locator('.report-scope')).not.toHaveAttribute('open', '');
        await page.goto(paths[0]);
        await expect(page.locator('.report-scope')).toHaveAttribute('open', '');
        await expect(page.locator('.report-scope input[name="q"]')).toHaveValue('TEST-no-matching-filter-row');
        await toggle(page, '.report-scope', false);
        await page.reload();
        await expect(page.locator('.report-scope')).not.toHaveAttribute('open', '');
    } finally {
        await restoreDisclosures(page, saved);
    }
});

test('Cash desktop and phone filter choices persist independently', async ({ signedIn: page }, info) => {
    test.setTimeout(180_000);
    const saved = [];
    try {
        for (const [path, selector] of [
            ['/app/cash/banking', 'details[data-remember^="card:cash.banking.scope:"]'],
            ['/app/cash/banking/phone', 'details[data-remember^="params:cash-banking-phone:"]'],
        ]) {
            await page.goto(path);
            saved.push(await resetDisclosure(page, selector));
            await page.reload();
            await expect(page.locator(selector)).not.toHaveAttribute('open', '');
            await toggle(page, selector, true);
            await page.reload();
            await expect(page.locator(selector)).toHaveAttribute('open', '');
            await page.screenshot({ path: info.outputPath(path.endsWith('phone') ? 'phone-filter-open.png' : 'cash-filter-open.png'), fullPage: true });
            await toggle(page, selector, false);
            await page.reload();
            await expect(page.locator(selector)).not.toHaveAttribute('open', '');
        }
        expect(saved[0].key).not.toBe(saved[1].key);
    } finally {
        await restoreDisclosures(page, saved);
    }
});

async function layoutRequest(page, url, method = 'GET', state = null) {
    return page.evaluate(async ({ url, method, state }) => {
        const response = await fetch(url, {
            method, credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            ...(state === null ? {} : { body: JSON.stringify(state) }),
        });
        if (!response.ok) throw new Error(`Layout ${method} failed: ${response.status}`);
        return response.json();
    }, { url, method, state });
}

test('existing index and report tables persist column order visibility and widths', async ({ signedIn: page, isMobile }, info) => {
    test.skip(isMobile, 'Column layout is exercised on the desktop table.');
    test.setTimeout(180_000);
    await page.goto('/app/cash/drop-safe');
    await expect(page.locator('.shell-foot')).toContainText('TEST Playwright');
    const site = await page.locator('select[name="branch_id"] option').evaluateAll((options) => options.find((option) => /^\d+$/.test(option.value))?.value);
    expect(site, 'Use a site offered to this local TEST reader.').toBeTruthy();
    for (const [label, path] of [
        ['index', `/app/cash/drop-safe?branch_id=${site}&date_from=2026-10-28&date_to=2026-10-28`],
        ['report', `/app/reports/day-close?branches[]=${site}&from=2026-10-28&to=2026-10-28`],
    ]) {
        const response = await page.goto(path);
        expect(response.ok(), `${label} page loads before exercising its layout.`).toBe(true);
        const block = page.locator('.table-block[data-table-layout-url]').first();
        await expect(block.locator('.tl-chooser')).toBeVisible();
        const url = await block.getAttribute('data-table-layout-url');
        const { state: previous } = await layoutRequest(page, url);
        await info.attach(`${label}-layout-before`, { body: JSON.stringify({ url, state: previous }), contentType: 'application/json' });
        try {
            await layoutRequest(page, url, 'DELETE');
            await page.reload();
            const chooser = block.locator('.tl-chooser');
            await chooser.locator('summary').click();
            const choice = chooser.locator('.tl-list input:not(:disabled)').last();
            const hiddenIndex = Number(await choice.getAttribute('data-tl-choice'));
            await choice.uncheck();
            await chooser.locator('button[aria-label$=" earlier"]:not(:disabled)').first().click();
            const head = block.locator('thead tr').first();
            const handle = head.locator('th').first().getByRole('separator');
            await handle.focus();
            await page.keyboard.press('ArrowRight');
            const order = await head.locator('th').allTextContents();
            const width = await head.locator('th').first().evaluate((cell) => cell.style.width);
            expect(width).toMatch(/^\d+px$/);
            await expect.poll(async () => (await layoutRequest(page, url)).state.hidden).toContain(hiddenIndex);
            await expect.poll(async () => Object.keys((await layoutRequest(page, url)).state.widths ?? {}).length).toBeGreaterThan(0);
            await page.reload();
            await expect.poll(() => head.locator('th').allTextContents()).toEqual(order);
            await expect.poll(() => head.locator('th').first().evaluate((cell) => cell.style.width)).toBe(width);
            await chooser.locator('summary').click();
            await expect(chooser.locator(`[data-tl-choice="${hiddenIndex}"]`)).not.toBeChecked();
            await page.screenshot({ path: info.outputPath(`${label}-saved-layout.png`), fullPage: true });
        } finally {
            const hasPrevious = Object.keys(previous ?? {}).length > 0;
            await layoutRequest(page, url, hasPrevious ? 'POST' : 'DELETE', hasPrevious ? previous : null);
            const restored = (await layoutRequest(page, url)).state;
            await info.attach(`${label}-layout-restored`, { body: JSON.stringify({ url, state: restored }), contentType: 'application/json' });
            expect(restored).toEqual(previous);
        }
    }
});
