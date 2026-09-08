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
