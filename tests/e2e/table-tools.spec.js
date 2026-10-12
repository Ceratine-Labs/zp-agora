import { anonymous, expect, test } from './support/fixtures.js';

/**
 * The grid suite on an ordinary <x-table>: a head that sticks, a heading that
 * sorts, a column that filters, and a commit count that follows both.
 *
 * All four are browser behaviour and none of them can be reached from phpunit.
 * The sticky head in particular is the reason this file exists: `position:
 * sticky; top: 0` had been on `table.dt thead th` since the table was written
 * and had never once worked, because `.table-scroll` was a scroll container
 * with no height — and nothing but a computed style in a real browser says so.
 *
 * The last test is the one about money. A filter on these screens takes rows
 * OUT of the submission, so a clerk cannot narrow to one site and then stamp
 * four hundred rows they cannot see. It asserts the mechanism (the box is
 * disabled) and the report (the count says so).
 *
 * NOT RUN in the lane that wrote it — browser runs are Ryan's call. Run it:
 *
 *     npx playwright test tests/e2e/table-tools.spec.js --project=desktop
 *
 * `npm run build` first; the config serves built assets on purpose.
 */

const TABLE = '#gal-table-tools';

test.describe('table tools', () => {
    test.use(anonymous);

    test('the head sticks, because the scroller finally has a height', async ({ page }) => {
        await page.goto('/dev/components');

        const scroll = page.locator(`${TABLE}`).locator('xpath=ancestor::div[@class="table-scroll"]');

        // The bound is what turns the declaration into the behaviour. Without
        // it the box is as tall as its content and the header has nothing to
        // stick against.
        const height = await scroll.evaluate((el) => getComputedStyle(el).maxHeight);
        expect(height).not.toBe('none');

        const position = await page.locator(`${TABLE} thead tr:first-child th`).first()
            .evaluate((el) => getComputedStyle(el).position);
        expect(position).toBe('sticky');

        // The filter row is the second head row and sticks UNDER the first, at
        // the height table-tools.js measured rather than at a guess.
        const offset = await page.locator(`${TABLE} tr.tt-filters th`).first()
            .evaluate((el) => getComputedStyle(el).top);
        expect(parseFloat(offset)).toBeGreaterThan(0);
    });

    test('a heading sorts, and a third press gives the server order back', async ({ page }) => {
        await page.goto('/dev/components');

        const sites = () => page.locator(`${TABLE} tbody tr:not(.tt-out) td:nth-child(2)`).allTextContents();

        expect(await sites()).toEqual(['Elephant Coast', 'Nyala One Stop', 'Total Mkuze']);

        const heading = page.locator(`${TABLE} thead tr:first-child th`).nth(3).getByRole('button');

        // Declared: 33 320.00 / 18 240.55 / 7 415.00 — a numeric column, so it
        // must not sort as the strings "33 320.00" < "7 415.00".
        await heading.click();
        expect(await sites()).toEqual(['Total Mkuze', 'Nyala One Stop', 'Elephant Coast']);

        await heading.click();
        expect(await sites()).toEqual(['Elephant Coast', 'Nyala One Stop', 'Total Mkuze']);

        await heading.click();
        expect(await sites()).toEqual(['Elephant Coast', 'Nyala One Stop', 'Total Mkuze']);
        await expect(page.locator(`${TABLE} thead tr:first-child th`).nth(3)).not.toHaveAttribute('aria-sort');
    });

    test('a column filter narrows the rows and says so in the footer', async ({ page }) => {
        await page.goto('/dev/components');

        await page.locator(`${TABLE} tr.tt-filters th`).nth(1).locator('input').fill('Nyala');

        await expect(page.locator(`${TABLE} tbody tr:not(.tt-out)`)).toHaveCount(1);
        await expect(page.locator(`${TABLE}`).locator('xpath=ancestor::div[@class="table-block"]')
            .locator('.table-count')).toHaveText(/Showing 1 of 3 — filtered/);

        // The heading is marked too. The box scrolls out of sight long before
        // the question "why am I only seeing one row" gets asked.
        await expect(page.locator(`${TABLE} thead tr:first-child th`).nth(1)).toHaveClass(/is-filtered/);
    });

    test('the Excel value list is drawn outside the scroller that would clip it', async ({ page }) => {
        await page.goto('/dev/components');

        await page.locator(`${TABLE} tr.tt-filters th`).nth(1).locator('.tt-pick').click();

        const panel = page.locator('.tt-panel');
        await expect(panel).toBeVisible();

        // In the body, not in the cell: .table-scroll clips both axes.
        expect(await panel.evaluate((el) => el.parentElement.tagName)).toBe('BODY');

        await panel.getByText('(Select all)').click();
        await panel.locator('input[type="checkbox"][value="Total Mkuze"]').check();

        await expect(page.locator(`${TABLE} tbody tr:not(.tt-out)`)).toHaveCount(1);
    });

    test('a row a filter hides leaves the submission, and the count says so', async ({ page }) => {
        await page.goto('/dev/components');

        const commit = page.getByRole('button', { name: /Reconcile \d+ batch/ });
        await expect(commit).toHaveText('Reconcile 3 batches');

        await page.locator(`${TABLE} tr.tt-filters th`).nth(1).locator('input').fill('Nyala');

        // Held by its VALUE rather than by `.tt-out`, because the whole point
        // of the last two assertions is that the row stops being filtered out
        // — a locator keyed on the filter would stop matching just as the
        // thing it is meant to prove starts being true.
        const hidden = page.locator(`${TABLE} [data-check][value="CCB69744"]`);

        // The mechanism: disabled, not unticked. A filter is a way of looking,
        // and clearing it has to give back exactly the selection there was.
        await expect(hidden.locator('xpath=ancestor::tr')).toHaveClass(/tt-out/);
        await expect(hidden).toBeDisabled();
        await expect(hidden).toBeChecked();

        await expect(commit).toHaveText('Reconcile 1 batch');
        await expect(page.locator('[data-action-bar-scope]')).toContainText('hidden by a column filter');

        await page.locator(`${TABLE} tr.tt-filters th`).nth(1).locator('input').fill('');

        await expect(commit).toHaveText('Reconcile 3 batches');
        await expect(hidden).toBeEnabled();
        await expect(hidden).toBeChecked();
    });
});

/**
 * Paging, and a column shown as pills.
 *
 * The gallery entry is `#gal-table-paged`: 64 rows, `data-tt-page="25"`, and a
 * State column declared `data-tt-pills="Balanced"`. Both are browser behaviour
 * and neither is reachable from phpunit.
 *
 * The test that matters here is the last one. Paging and filtering look alike
 * on screen and are opposites underneath: a filter takes rows OUT of the
 * submission, a page does not. Get that backwards in either direction and a
 * commit form either stamps rows nobody could see or silently drops rows
 * somebody ticked.
 */
test.describe('pills and paging', () => {
    test.use(anonymous);

    const PAGED = '#gal-table-paged';
    const shown = (page) => page.locator(`${PAGED} tbody tr:not(.tt-out):not(.tt-page-out)`);
    const strip = (page) => page.locator(PAGED)
        .locator('xpath=ancestor::div[contains(@class,"table-block")]');

    test('the strip opens on the pill it names, counted, in the declared order', async ({ page }) => {
        await page.goto('/dev/components');

        // "All" leads, then the order the heading declared — not alphabetical,
        // and not the order the rows happen to arrive in.
        await expect(strip(page).locator('.tt-pill')).toHaveText([
            'All64', 'Balanced39', 'Short11', 'Blocked7', 'Unrecorded issue7',
        ]);

        await expect(strip(page).locator('.tt-pill.is-on')).toHaveText('Balanced39');

        // Scoped on arrival: 39 balanced rows, cut to a page of 25.
        await expect(shown(page)).toHaveCount(25);
        await expect(strip(page).locator('.table-count'))
            .toHaveText('Showing 1–25 of 39 — filtered from 64');
    });

    test('a pill is a filter: one click rescopes, and the heading says so', async ({ page }) => {
        await page.goto('/dev/components');

        // Pills are several at once (7 Oct 2026): from the opening scope,
        // Blocked is ADDED to Balanced; All, then Blocked, is Blocked alone.
        await strip(page).locator('.tt-pill', { hasText: 'Blocked' }).click();
        await expect(strip(page).locator('.tt-pill.is-on')).toHaveText(['Balanced39', 'Blocked7']);

        await strip(page).locator('.tt-pill', { hasText: 'All' }).click();
        await strip(page).locator('.tt-pill', { hasText: 'Blocked' }).click();

        await expect(shown(page)).toHaveCount(7);
        await expect(strip(page).locator('.table-count')).toHaveText('Showing 7 of 64 — filtered');

        // Marked on the head, exactly as the tick list marks it — the strip is
        // the control, not a second mechanism.
        await expect(page.locator(`${PAGED} thead th`).first()).toHaveClass(/is-filtered/);

        // Seven rows cannot be paged into anything, so the pager takes itself
        // off rather than sitting there saying "1".
        await expect(strip(page).locator('.tt-pager')).toBeHidden();

        // A second pill joins the first; pressing one again takes it out.
        await strip(page).locator('.tt-pill', { hasText: 'Short' }).click();
        await expect(shown(page)).toHaveCount(18);
        await strip(page).locator('.tt-pill', { hasText: 'Blocked' }).click();
        await expect(shown(page)).toHaveCount(11);

        await strip(page).locator('.tt-pill', { hasText: 'All' }).click();
        await expect(shown(page)).toHaveCount(25);
        await expect(page.locator(`${PAGED} thead th`).first()).not.toHaveClass(/is-filtered/);
    });

    test('the pages cut the rows the reader is looking at, and say which', async ({ page }) => {
        await page.goto('/dev/components');

        await strip(page).locator('.tt-pill', { hasText: 'All' }).click();
        await expect(strip(page).locator('.tt-pager')).toHaveAttribute('data-range', '1–25 of 64');

        const first = await shown(page).first().locator('td').nth(2).textContent();

        await strip(page).locator('.tt-pager-btn', { hasText: 'Next' }).click();
        await expect(strip(page).locator('.tt-pager')).toHaveAttribute('data-range', '26–50 of 64');
        await expect(shown(page)).toHaveCount(25);
        expect(await shown(page).first().locator('td').nth(2).textContent()).not.toBe(first);

        // The last page is the remainder, and Next stops being offered.
        await strip(page).locator('.tt-pager-btn', { hasText: '3' }).click();
        await expect(strip(page).locator('.tt-pager')).toHaveAttribute('data-range', '51–64 of 64');
        await expect(shown(page)).toHaveCount(14);
        await expect(strip(page).locator('.tt-pager-btn', { hasText: 'Next' })).toBeDisabled();

        // All in one page: every row comes back and the range says the whole set.
        await strip(page).locator('.tt-pager-size select').selectOption('0');
        await expect(shown(page)).toHaveCount(64);
        await expect(strip(page).locator('.tt-pager')).toHaveAttribute('data-range', '1–64 of 64');
    });

    test('a paged-away row is still in the submission; a filtered one is not', async ({ page }) => {
        await page.goto('/dev/components');

        await strip(page).locator('.tt-pill', { hasText: 'All' }).click();

        // THE WHOLE POINT. A row on page three is hidden and enabled; a row a
        // filter removed is hidden and disabled. Same invisibility, opposite
        // consequence for a commit.
        const offPage = page.locator(`${PAGED} tbody tr.tt-page-out`).first();
        await expect(offPage).toHaveClass(/tt-page-out/);
        await expect(offPage).not.toHaveClass(/tt-out/);

        await strip(page).locator('.tt-pill', { hasText: 'Blocked' }).click();

        const filtered = page.locator(`${PAGED} tbody tr.tt-out`).first();
        await expect(filtered).toHaveClass(/tt-out/);
        await expect(filtered).not.toHaveClass(/tt-page-out/);
    });
});

/**
 * The other half of the money rule, and the one Ryan asked for after reading
 * the first: a filter narrowing a commit has to be SAID, not merely counted.
 *
 * Both mistakes are real. Stamping rows nobody can see is the worse one and
 * the disabled inputs above prevent it. This is the reverse — narrow the list
 * to look at something, forget the box is full, press Reconcile, and stamp 12
 * where 138 were meant. The smaller number was on the button the whole time
 * and it is not a stop; the confirmation is the last thing read before the
 * press, so that is where it goes.
 *
 * Against a real run rather than the gallery, because the thing under test is
 * a real commit form wired to a real table. Nothing is written: the dialog is
 * asserted and then cancelled.
 */
test.describe('the reconcile confirmation', () => {
    test('names what a column filter is holding back, and says nothing when none is', async ({ signedIn }) => {
        const response = await signedIn.goto('/app/recon/runs/244');
        test.skip(response !== null && response.status() !== 200, 'run 244 is not in scope here.');

        const table = signedIn.locator('table[data-table-tools]');
        test.skip(await table.count() === 0, 'this run has no proposals to filter.');

        const commit = signedIn.getByRole('button', { name: /^(Reconcile|Record) \d+ batch/ });

        // First with no filter at all: the dialog must not mention one.
        await commit.click();
        await expect(signedIn.locator('.swal2-popup')).toBeVisible();
        await expect(signedIn.locator('.swal2-html-container')).not.toContainText('column filter');
        await signedIn.getByRole('button', { name: 'Cancel' }).click();
        await expect(signedIn.locator('.swal2-popup')).toBeHidden();

        // Now narrow to the matched row — the one that is ticked, so the
        // button stays live — and press again.
        await table.locator('tr.tt-filters th').nth(1).locator('input').fill('Matched');
        await expect(table.locator('tbody tr:not(.tt-out)')).toHaveCount(1);

        await commit.click();
        await expect(signedIn.locator('.swal2-html-container'))
            .toContainText('A column filter is hiding 3 of 4 rows.');
        await expect(signedIn.locator('.swal2-html-container'))
            .toContainText('Clear the filters first if you meant to act on all of them.');

        // Cancelled, so nothing is stamped and the run is left as it was.
        await signedIn.getByRole('button', { name: 'Cancel' }).click();
        await expect(signedIn.locator('.swal2-popup')).toBeHidden();
    });
});

/**
 * Two pill sets in one strip (`data-tt-pills-join`, 7 Oct 2026). Ryan asked for
 * the stock recon chain filter "in the filters row": a second set beside
 * Outcome rather than a second strip. The gallery entry is `#gal-table-joined`
 * — State, and Site joined to it.
 */
test.describe('joined pill sets', () => {
    test.use(anonymous);

    const JOINED = '#gal-table-joined';
    const block = (page) => page.locator(JOINED)
        .locator('xpath=ancestor::div[contains(@class,"table-block")]');
    const shown = (page) => page.locator(`${JOINED} tbody tr:not(.tt-out):not(.tt-page-out)`);

    test('the second set sits in the same strip, captioned, and the two filters combine', async ({ page }) => {
        await page.goto('/dev/components');

        await expect(block(page).locator('.tt-pills')).toHaveCount(1);
        const sets = block(page).locator('.tt-pills-set');
        await expect(sets).toHaveCount(2);
        await expect(sets.nth(0).locator('.tt-pills-label')).toHaveText('State');
        await expect(sets.nth(1).locator('.tt-pills-label')).toHaveText('Site');
        await expect(sets.nth(1).locator('.tt-pill')).toHaveCount(5);

        // Every fourth row is one site; 16 of 64.
        await sets.nth(1).locator('.tt-pill', { hasText: 'Total Mkuze' }).click();
        await expect(shown(page)).toHaveCount(16);

        // And with a state: the two narrow together, not one replacing the other.
        await sets.nth(0).locator('.tt-pill', { hasText: 'Short' }).click();
        const both = await shown(page).count();
        expect(both).toBeGreaterThan(0);
        expect(both).toBeLessThan(16);
        for (const text of await shown(page).locator('td:nth-child(2)').allTextContents()) {
            expect(text.trim()).toBe('Total Mkuze');
        }
    });
});

/**
 * Grouped rows (`data-tt-groups`, 7 Oct 2026): the stock recon proposals show
 * one row per chain that opens to its shifts. The gallery entry is
 * `#gal-table-grouped` — 64 rows in four site groups of 16, every row ticked.
 *
 * The line that matters is the same one as for paging: shutting a group is a
 * way of looking, so its rows stay in the submission; a FILTER takes rows out.
 */
test.describe('grouped rows', () => {
    test.use(anonymous);

    const GROUPED = '#gal-table-grouped';
    const heads = (page) => page.locator(`${GROUPED} tbody tr.tt-group:not(.tt-out):not(.tt-page-out)`);
    const members = (page) => page.locator(`${GROUPED} tbody tr[data-tt-group]:not(.tt-out):not(.tt-page-out):not(.tt-collapsed)`);
    const count = (page) => page.locator(GROUPED)
        .locator('xpath=ancestor::div[contains(@class,"table-block")]').locator('.table-count');

    test('shut on arrival, one row per group, counted as groups', async ({ page }) => {
        await page.goto('/dev/components');

        await expect(heads(page)).toHaveCount(4);
        await expect(members(page)).toHaveCount(0);
        await expect(count(page)).toHaveText('4 sites · 64 rows');
    });

    test('a head opens to its rows and shuts again; a shut row stays in the submission', async ({ page }) => {
        await page.goto('/dev/components');

        const first = heads(page).first();
        await first.click();
        await expect(first).toHaveAttribute('aria-expanded', 'true');
        await expect(members(page)).toHaveCount(16);

        await first.click();
        await expect(members(page)).toHaveCount(0);

        // Shut, not filtered: every box still enabled and ticked.
        await expect(page.locator(`${GROUPED} [data-check]:checked:not(:disabled)`)).toHaveCount(64);
    });

    test('a filter narrows the rows, and a head shows while any of its rows does', async ({ page }) => {
        await page.goto('/dev/components');

        const block = page.locator(GROUPED).locator('xpath=ancestor::div[contains(@class,"table-block")]');
        await block.locator('.tt-pill', { hasText: 'Blocked' }).click();

        // Blocked is every ninth row: 7 rows, over the sites they fall in.
        const shown = await heads(page).count();
        expect(shown).toBeGreaterThan(0);
        expect(shown).toBeLessThanOrEqual(4);
        await expect(page.locator(`${GROUPED} [data-check]:not(:disabled)`)).toHaveCount(7);

        await block.locator('.tt-expand-all').click();
        await expect(members(page)).toHaveCount(7);
    });

    test('the head box ticks and unticks its own rows', async ({ page }) => {
        await page.goto('/dev/components');

        const box = heads(page).first().locator('[data-tt-group-check]');
        await expect(box).toBeChecked();
        await box.uncheck();
        await expect(page.locator(`${GROUPED} [data-check]:checked`)).toHaveCount(48);
        await box.check();
        await expect(page.locator(`${GROUPED} [data-check]:checked`)).toHaveCount(64);
    });
});


/*
 * A head that opens its detail (`data-tt-group-detail`, 12 Oct 2026): the
 * stock recon proposals' chain row opens straight to the chain panel, with no
 * middle layer of shift rows (Ryan: "get rid of the middle drill down").
 * `#gal-table-group-detail` — the same 64 rows, each site head carrying a
 * template.
 */
test.describe('grouped rows that open a detail', () => {
    test.use(anonymous);

    const TABLE = '#gal-table-group-detail';
    const heads = (page) => page.locator(`${TABLE} tbody tr.tt-group:not(.tt-out):not(.tt-page-out)`);
    const members = (page) => page.locator(`${TABLE} tbody tr[data-tt-group]:not(.tt-out):not(.tt-page-out):not(.tt-collapsed)`);
    const panels = (page) => page.locator(`${TABLE} tbody tr.row-detail:not([hidden]):not(.tt-out):not(.tt-page-out)`);

    test('a head opens its panel, never its rows, and the rows stay in the submission', async ({ page }) => {
        await page.goto('/dev/components');

        await expect(heads(page)).toHaveCount(4);
        const first = heads(page).first();
        await first.click();

        await expect(panels(page)).toHaveCount(1);
        await expect(panels(page).first()).toContainText('the detail behind the head: 16 rows');
        await expect(first).toHaveAttribute('aria-expanded', 'true');
        await expect(members(page)).toHaveCount(0);
        await expect(page.locator(`${TABLE} [data-check]:checked:not(:disabled)`)).toHaveCount(64);

        // No expand-all: there is nothing to expand.
        const block = page.locator(TABLE).locator('xpath=ancestor::div[contains(@class,"table-block")]');
        await expect(block.locator('.tt-expand-all')).toHaveCount(0);

        await first.click();
        await expect(panels(page)).toHaveCount(0);
    });

    test('an open panel moves with its head when the groups are sorted', async ({ page }) => {
        await page.goto('/dev/components');

        const first = heads(page).first();
        const site = (await first.locator('td').nth(2).innerText()).trim();
        await first.click();
        await expect(panels(page)).toHaveCount(1);

        // Sort by Site twice, so the order is reversed whichever way it began.
        const sorter = page.locator(`${TABLE} thead th`, { hasText: 'Site' }).locator('button').first();
        await sorter.click();
        await sorter.click();

        const head = page.locator(`${TABLE} tbody tr.tt-group`, { hasText: site });
        const next = head.locator('xpath=following-sibling::tr[1]');
        await expect(next).toHaveClass(/row-detail/);
        await expect(next).toContainText(site);
    });
});
