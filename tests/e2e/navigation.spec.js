import { test, expect } from './support/fixtures.js';

/**
 * The app bar and its expandable panels.
 *
 * The customer asked for a menu that drops down per section with children and
 * sub-children, and the depth is data rather than markup — so the assertions
 * here are about the tree the database produced, not about a fixed shape.
 */
test.describe('navigation', () => {
    test('every section from the database gets a button', async ({ signedIn }) => {
        for (const section of ['Today', 'Trade', 'Control', 'Setup']) {
            await expect(signedIn.getByRole('button', { name: new RegExp(`^${section}`) })).toBeVisible();
        }
    });

    test('a section button expands its panel and only its panel', async ({ signedIn }) => {
        const trade = signedIn.locator('[data-menu="trade"]');
        const tradePanel = signedIn.locator('[data-panel="trade"]');
        const todayPanel = signedIn.locator('[data-panel="today"]');

        await expect(tradePanel).toBeHidden();
        await expect(trade).toHaveAttribute('aria-expanded', 'false');

        await trade.click();
        await expect(tradePanel).toBeVisible();
        await expect(trade).toHaveAttribute('aria-expanded', 'true');
        await expect(todayPanel).toBeHidden();

        // Opening another closes the first — two panels open at once is the bug
        // a naive toggle produces.
        await signedIn.locator('[data-menu="today"]').click();
        await expect(todayPanel).toBeVisible();
        await expect(tradePanel).toBeHidden();
    });

    test('the panel carries children and sub-children', async ({ signedIn }) => {
        await signedIn.locator('[data-menu="trade"]').click();
        const panel = signedIn.locator('[data-panel="trade"]');
        await expect(panel).toBeVisible();

        // Depth 1: column headings.
        await expect(panel.locator('.mega-col')).toHaveCount(3);
        await expect(panel.getByRole('heading', { name: 'Reports' })).toBeVisible();

        // Depth 2: a group that is itself a parent, collapsed until asked.
        const category = panel.locator('details.mega-group', { hasText: 'Exco' }).first();
        await expect(category).toBeVisible();
        await expect(category.getByText('Report library')).toBeHidden();

        // Depth 3: opens in place.
        //
        // Asserted by text, not by role=link: these two screens are not built,
        // so they are deliberately seeded without a route and render as text.
        // Asserting a link here would pass only once someone built the report
        // library, which is not what this test is about.
        await category.locator('summary').click();
        await expect(category.getByText('Report library')).toBeVisible();
        await expect(category.getByText('Legacy catalogue')).toBeVisible();
    });

    test('a nested group opens from the keyboard', async ({ signedIn }) => {
        // Nesting is <details>, so keyboard behaviour comes from the browser
        // rather than from our JS. This asserts we have not broken that with a
        // click handler.
        await signedIn.locator('[data-menu="trade"]').click();
        const category = signedIn.locator('[data-panel="trade"] details.mega-group').first();

        await category.locator('summary').focus();
        await signedIn.keyboard.press('Enter');
        await expect(category).toHaveAttribute('open', '');
    });

    test('escape closes the panel and returns focus to its button', async ({ signedIn }) => {
        const control = signedIn.locator('[data-menu="control"]');
        await control.click();
        await expect(signedIn.locator('[data-panel="control"]')).toBeVisible();

        await signedIn.keyboard.press('Escape');
        await expect(signedIn.locator('[data-panel="control"]')).toBeHidden();
        await expect(control).toBeFocused();
    });

    test('tapping below the panel closes it', async ({ signedIn }) => {
        await signedIn.locator('[data-menu="setup"]').click();
        const panel = signedIn.locator('[data-panel="setup"]');
        await expect(panel).toBeVisible();

        // Not `scrim.click()`: Playwright clicks an element's centre, and the
        // scrim spans the whole viewport, so its centre is behind the open
        // panel. Tap where a person actually would — the strip below it. On a
        // phone that strip is 56px by design; on a desktop it is most of the
        // screen.
        const box = await panel.boundingBox();
        const viewport = signedIn.viewportSize();
        const y = Math.round(box.y + box.height + (viewport.height - box.y - box.height) / 2);

        expect(y, 'the panel must never cover the whole viewport').toBeLessThan(viewport.height);
        await signedIn.mouse.click(Math.round(viewport.width / 2), y);

        await expect(panel).toBeHidden();
    });

    test('a menu link navigates', async ({ signedIn }) => {
        await signedIn.locator('[data-menu="today"]').click();
        await signedIn.locator('[data-panel="today"]').getByRole('link', { name: 'Day close status' }).click();

        await expect(signedIn).toHaveURL(/\/app$/);
        await expect(signedIn.getByRole('heading', { name: 'Today', level: 1 })).toBeVisible();
    });

    test('a screen that does not exist yet is not a dead link', async ({ signedIn }) => {
        // Unbuilt items are seeded without a route on purpose: the menu is the
        // plan made visible. They must render as text, never as a link that
        // 404s.
        await signedIn.locator('[data-menu="today"]').click();
        const unbuilt = signedIn.locator('[data-panel="today"] .mega-link.is-dead').first();

        await expect(unbuilt).toBeVisible();
        await expect(unbuilt).toHaveAttribute('title', 'Not built yet');
        expect(await unbuilt.evaluate((el) => el.tagName)).toBe('SPAN');
    });
});
