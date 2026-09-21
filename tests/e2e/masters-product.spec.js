import { expect, test } from '@playwright/test';

/**
 * Setup → Trading rules → Stock master (T025).
 *
 * The listing and one line's detail, driven in a real browser. What is worth
 * asserting here rather than in phpunit is the things only a render shows:
 * that the branch selector is present because an item number means nothing
 * without a site, that the procedure name is on the page (feature-rules §3.4),
 * that the provenance notice actually explains where the columns come from,
 * and that clicking an item opens it.
 */
test.describe('Stock master', () => {
    test('the listing renders with its scope, its provenance and its procedure named', async ({ page }) => {
        await page.goto('/app/master/stock');

        await expect(page.getByRole('heading', { name: 'Stock master', level: 1 })).toBeVisible();

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
        // the stock master — or a third of it looks like missing data.
        await expect(page.getByText('What this list is, and what it is not')).toBeVisible();
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
