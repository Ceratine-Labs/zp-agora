import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';

// This exercises the real grid module with a delayed first response; a slow
// request must not overwrite the newer choice when the network reorders work.
test('a slow layout save cannot overwrite a newer layout', async ({ page }) => {
    const source = (await readFile('resources/js/components/data-grid.js')).toString('base64');
    await page.setContent(`
        <meta name="csrf-token" content="test">
        <div data-grid data-state-url="/save">
            <div data-chooser-list><div data-chooser-item="name">
                <input data-chooser-visible value="name" type="checkbox" checked>
            </div></div>
            <select data-text-size><option value="normal">Normal</option>
                <option value="large">Large</option><option value="compact">Compact</option></select>
            <table class="dg"><tr><th data-column="name"></th></tr></table>
        </div>`);
    const result = await page.evaluate(async (moduleSource) => {
        const calls = [];
        let release;
        window.fetch = async (_url, request) => {
            calls.push(JSON.parse(request.body).text_size);
            if (calls.length === 1) await new Promise((resolve) => { release = resolve; });
            return { ok: true };
        };
        const module = await import(`data:text/javascript;base64,${moduleSource}`);
        module.default(document);
        const size = document.querySelector('[data-text-size]');
        size.value = 'large';
        size.dispatchEvent(new Event('change'));
        await new Promise((resolve) => setTimeout(resolve, 600));
        size.value = 'compact';
        size.dispatchEvent(new Event('change'));
        await new Promise((resolve) => setTimeout(resolve, 600));
        const beforeRelease = [...calls];
        release();
        await new Promise((resolve) => setTimeout(resolve, 30));
        return { beforeRelease, calls };
    }, source);
    expect(result.beforeRelease).toEqual(['large']);
    expect(result.calls).toEqual(['large', 'compact']);
});

test('reset waits for an in-flight layout save', async ({ page }) => {
    const source = (await readFile('resources/js/components/data-grid.js')).toString('base64');
    await page.setContent(`
        <meta name="csrf-token" content="test">
        <div data-grid data-state-url="/save">
            <div data-chooser-list><div data-chooser-item="name">
                <input data-chooser-visible value="name" type="checkbox" checked>
            </div></div>
            <select data-text-size><option value="normal">Normal</option><option value="large">Large</option></select>
            <button data-chooser-reset>Reset</button>
            <table class="dg"><tr><th data-column="name"></th></tr></table>
        </div>`);
    const result = await page.evaluate(async (moduleSource) => {
        const calls = [];
        let release;
        window.fetch = async (_url, request) => {
            calls.push(request.method);
            if (request.method === 'POST') await new Promise((resolve) => { release = resolve; });
            return { ok: request.method !== 'DELETE' };
        };
        // Prevent navigation so the sequence can be inspected.
        window.Agora = { notify: { confirm: async () => true, toast: () => {} } };
        const module = await import(`data:text/javascript;base64,${moduleSource}`);
        module.default(document);
        const size = document.querySelector('[data-text-size]');
        size.value = 'large';
        size.dispatchEvent(new Event('change'));
        await new Promise((resolve) => setTimeout(resolve, 600));
        document.querySelector('[data-chooser-reset]').click();
        await new Promise((resolve) => setTimeout(resolve, 30));
        const beforeRelease = [...calls];
        release();
        await new Promise((resolve) => setTimeout(resolve, 30));
        return { beforeRelease, calls };
    }, source);
    expect(result.beforeRelease).toEqual(['POST']);
    expect(result.calls).toEqual(['POST', 'DELETE']);
});

test('grid height choice is applied and sent for persistence', async ({ page }) => {
    const source = (await readFile('resources/js/components/data-grid.js')).toString('base64');
    await page.setContent(`<meta name="csrf-token" content="test"><div data-grid data-state-url="/save"><div data-chooser-list></div><select data-table-height><option value="normal">Normal</option><option value="large">Tall</option></select><table class="dg"><tr><th data-column="name"></th></tr></table></div>`);
    await page.evaluate(async (moduleSource) => {
        window.savedGrid = null;
        window.fetch = async (_url, request) => { window.savedGrid = JSON.parse(request.body); return { ok: true }; };
        const module = await import(`data:text/javascript;base64,${moduleSource}`);
        module.default(document);
    }, source);
    await page.locator('[data-table-height]').selectOption('large');
    await expect(page.locator('[data-grid]')).toHaveClass(/dg-height-large/);
    await expect.poll(() => page.evaluate(() => window.savedGrid?.table_height)).toBe('large');
});
