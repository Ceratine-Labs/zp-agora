/**
 * A look at the stock master screens, for a human.
 *
 * Not a test — `npx playwright test masters-product.spec.js` is the test. This
 * exists because a passing assertion does not tell you whether a twenty-seven
 * column grid is legible, and the only way to find that out is to look.
 *
 *     node tests/e2e/support/shot-stock.mjs
 */
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';

const BASE = 'http://127.0.0.1:8123';
const OUT = process.env.SHOT_DIR || '/tmp';
const state = JSON.parse(readFileSync('tests/e2e/.auth/user.json', 'utf8'));

const browser = await chromium.launch();
const ctx = await browser.newContext({
    storageState: state,
    viewport: { width: 1440, height: 1000 },
    deviceScaleFactor: 2,
});
const page = await ctx.newPage();
page.on('pageerror', e => console.log('PAGE ERROR', String(e)));

await page.goto(BASE + '/app/master/stock');
await page.waitForLoadState('networkidle');
console.log('listing rows:', await page.locator('tbody tr').count());
await page.screenshot({ path: `${OUT}/stock-list.png`, fullPage: true });

await page.goto(BASE + '/app/master/critical');
await page.waitForLoadState('networkidle');
console.log('critical rows:', await page.locator('tbody tr').count());
await page.screenshot({ path: `${OUT}/critical-list.png`, fullPage: true });

await page.goto(BASE + '/app/master/stock');
await page.waitForLoadState('networkidle');
const first = page.locator('tbody tr td a').first();
if (await first.count() > 0) {
    await first.click();
    await page.waitForLoadState('networkidle');
    console.log('detail url:', page.url());
    await page.screenshot({ path: `${OUT}/stock-detail.png`, fullPage: true });
}

await browser.close();
