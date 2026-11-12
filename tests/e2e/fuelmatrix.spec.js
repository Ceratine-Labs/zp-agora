import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { signInThroughTheForm } from './support/fixtures.js';

test.use({ storageState: { cookies: [], origins: [] } });

test('fuel matrix and source history retain the recorded August moves in both themes', async ({ page, baseURL }, testInfo) => {
    test.skip(process.env.AGORA_FUEL_E2E !== '1', 'Requires explicit local source replay and recorded evidence.');
    test.setTimeout(240_000);
    expect(['127.0.0.1', 'localhost', '[::1]']).toContain(new URL(baseURL).hostname);
    const token = randomBytes(6).toString('hex');
    const fixture = (action) => JSON.parse(execFileSync('php', [
        fileURLToPath(new URL('./support/fuel-fixture.php', import.meta.url)), action, token,
    ], { encoding: 'utf8', env: process.env }));
    const prepared = fixture('prepare');
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
    const evidence = { day_list_id: process.env.AGORA_DAY_LIST_ID ?? null, source_branch: prepared.source_branch,
        local_branch: prepared.local_branch, pos_rows: prepared.pos_rows, doe_rows: prepared.doe_rows, sql_host: prepared.sql_host };
    try {
        await signInThroughTheForm(page, prepared);
        await page.waitForURL('**/app/fuel/matrix');
        for (const theme of ['light', 'dark']) {
            await page.context().addCookies([{ name: 'agora_theme', value: theme, url: baseURL }]);
            await page.goto('/app/fuel/matrix?from=2026-08-01&to=2026-08-31');
            await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
            await expect(page.getByRole('heading', { name: 'Fuel matrix', exact: true })).toBeVisible();
            await expect(page.locator('[data-grid]')).toContainText('25.54');
            await expect(page.locator('[data-grid]')).toContainText('25.02');
            await expect(page.locator('[data-grid]')).toContainText('27.77');
            await expect(page.locator('[data-grid]')).toContainText('29.01');
            await expect(page.locator('.apexcharts-canvas').first()).toBeVisible();
            const changes = page.locator('.card').filter({ has: page.getByRole('heading', { name: /Price-change log/ }) });
            await expect(changes).toContainText('2026-08-05');
            await expect(changes).toContainText('25.54');
            await expect(changes).toContainText('25.02');
            await expect(changes).toContainText('27.77');
            await expect(changes).toContainText('29.01');
            await page.screenshot({ path: testInfo.outputPath(`fuel-matrix-${theme}.png`), fullPage: true });

            await page.goto('/app/fuel/history?from=2026-08-01&to=2026-08-31');
            await expect(page.getByRole('heading', { name: 'Fuel price history', exact: true })).toBeVisible();
            await expect(page.locator('[data-grid]')).toContainText('Trading and source dates differ.');
            const historyChanges = page.locator('.card').filter({ has: page.getByRole('heading', { name: /Price-change log/ }) });
            await expect(historyChanges).toContainText('2026-08-13');
            await expect(historyChanges).toContainText('29.01 – 29.10');
            await expect(historyChanges).toContainText('records without margin');
            const spec = JSON.parse(await page.locator('[data-chart]').first().getAttribute('data-chart'));
            expect(spec.rows.some((series) => series.points.some((point) => point.label === '2026-08-13' && point.value === 29.1))).toBeTruthy();
            await expect(page.locator('.apexcharts-canvas').first()).toBeVisible();
            await page.screenshot({ path: testInfo.outputPath(`fuel-history-${theme}.png`), fullPage: true });
            expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
        }
        await page.goto('/app/fuel/history?from=2026-08-01&to=2026-08-31&f[SellingPrice][q]=29.10&f[SellingPrice][op]=eq');
        await expect(page.locator('[data-grid]')).toContainText('Cost missing. Margin missing.');
        if (testInfo.project.name === 'mobile') {
            await page.locator('.dg-card-actions').getByRole('link', { name: 'Source detail' }).first().click();
        } else {
            await page.locator('[data-detail-url]').first().click();
        }
        await expect(page.locator('.fuel-source-detail')).toBeVisible();
        await expect(page.locator('.fuel-source-detail')).toContainText('29.100000');
        await expect(page.locator('.fuel-source-detail .fact').filter({ hasText: 'Margin / ℓ' })).toContainText('—');
        await page.screenshot({ path: testInfo.outputPath('fuel-missing-margin-detail.png'), fullPage: true });
        await page.goto('/app/fuel/history?from=2026-08-01&to=2026-08-31&f[SellingPrice][q]=9999&f[SellingPrice][op]=eq');
        await expect(page.locator('[data-grid]')).not.toContainText('29.10');
        await page.goto('/app/reports/fuel-history?from=2026-08-01&to=2026-08-31&sort=SellingPrice&dir=desc');
        await expect(page.getByRole('heading', { name: 'Fuel price history', level: 1, exact: true })).toBeVisible();
        await expect(page.getByRole('table')).toContainText('29.10');
        const csv = await page.request.get('/app/grids/app.fuel.history/extract?from=2026-08-01&to=2026-08-31&f[SellingPrice][q]=29.10&f[SellingPrice][op]=eq&columns=TradingDate,SourceDate,SellingPrice,CostPrice,Margin');
        expect(csv.status()).toBe(200);
        expect(await csv.text()).toContain('29.1');
        expect(errors).toEqual([]);
    } finally {
        await page.context().close();
        evidence.cleanup = fixture('cleanup');
        expect(evidence.cleanup).toEqual({ pos_remaining: 0, doe_remaining: 0, users_remaining: 0, activity_remaining: 0, preferences_remaining: 0, grid_state_remaining: 0, grants_remaining: 0, branches_remaining: 0 });
        writeFileSync(testInfo.outputPath('fuel-evidence.json'), JSON.stringify(evidence, null, 2));
    }
});
