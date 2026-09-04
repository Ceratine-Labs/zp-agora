import { anonymous, expect, test } from './support/fixtures.js';

/**
 * The PHP number formatter and its JavaScript twin must agree exactly.
 *
 * This is the reason the styleguide carries a parity table. Two separate test
 * suites — one for each language — would both pass while quietly disagreeing
 * with each other, and the disagreement would first show up as a figure in a
 * table not matching the same figure in a chart label beside it.
 *
 * The page is public in local and testing only, so this runs without a session.
 */
test.describe('number formatting', () => {
    test.use(anonymous);

    test('every case renders identically in PHP and JavaScript', async ({ page }) => {
        await page.goto('/dev/theme');

        const rows = page.locator('#parity tbody tr');
        await expect(rows.first()).toBeVisible();

        const count = await rows.count();
        expect(count, 'the parity table should carry the awkward cases, not a handful').toBeGreaterThan(20);

        // Wait for the module to have filled the JS column at all.
        await expect(rows.first().locator('.js-out')).not.toBeEmpty();

        const mismatches = await page.evaluate(() =>
            [...document.querySelectorAll('#parity tbody tr')]
                .map((row) => ({
                    call: row.querySelector('code').textContent,
                    php: row.querySelector('.php-out').textContent,
                    js: row.querySelector('.js-out').textContent,
                }))
                .filter((r) => r.php !== r.js)
        );

        expect(mismatches, 'PHP and JavaScript must format every case the same way').toEqual([]);
    });

    test('the formats are the ones the design specifies', async ({ page }) => {
        await page.goto('/dev/theme');

        // Spot-check the shapes rather than every case: the parity test above
        // covers agreement, this covers being right in the first place. Both
        // matter — two implementations can agree and both be wrong.
        const outputs = await page.evaluate(() =>
            Object.fromEntries(
                [...document.querySelectorAll('#parity tbody tr')].map((row) => [
                    row.querySelector('code').textContent,
                    row.querySelector('.php-out').textContent,
                ])
            )
        );

        expect(outputs['R(1234.5)']).toBe('R1 234.50');
        expect(outputs['R(-1234.5)']).toBe('-R1 234.50');
        expect(outputs['Rk(2208437)']).toBe('R2.21m');
        expect(outputs['Rk(999)']).toBe('R999');
        expect(outputs['Rk(1000)']).toBe('R1k');
        expect(outputs['Lk(847300)']).toBe('847k L');
        expect(outputs['delta(0.02)']).toBe('0.0%');
        expect(outputs['delta(4.23)']).toBe('▲ +4.2%');
        expect(outputs['R(null)']).toBe('—');
    });

    test('a missing figure is an em dash, never a zero', async ({ page }) => {
        await page.goto('/dev/theme');

        const nulls = await page.evaluate(() =>
            [...document.querySelectorAll('#parity tbody tr')]
                .filter((row) => row.dataset.args.includes('null'))
                .map((row) => row.querySelector('.php-out').textContent)
        );

        expect(nulls.length).toBeGreaterThan(0);
        nulls.forEach((value) => expect(value).toBe('—'));
    });
});

test.describe('the theme', () => {
    test.use(anonymous);

    test('no element falls back to an unstyled Bootstrap colour', async ({ page }) => {
        await page.goto('/dev/theme');

        // Bootstrap's own defaults, which the bridge should have repointed at
        // Agora's tokens. Finding one means a component reached for a Bootstrap
        // utility instead of a token.
        const bootstrapBlue = ['rgb(13, 110, 253)', 'rgb(110, 168, 254)'];

        const offenders = await page.evaluate((blues) => {
            const found = [];
            document.querySelectorAll('body *').forEach((el) => {
                const s = getComputedStyle(el);
                for (const prop of ['color', 'backgroundColor', 'borderTopColor']) {
                    if (blues.includes(s[prop])) {
                        found.push(el.tagName.toLowerCase() + '.' + el.className + ' → ' + prop);
                    }
                }
            });

            return found.slice(0, 10);
        }, bootstrapBlue);

        expect(offenders).toEqual([]);
    });

    test('every token resolves to a real colour in both themes', async ({ page }) => {
        await page.goto('/dev/theme');

        for (const theme of ['light', 'dark']) {
            await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);

            const empty = await page.evaluate(() =>
                [...document.querySelectorAll('.sg-swatch code')]
                    .map((c) => c.textContent.trim())
                    .filter((name) => getComputedStyle(document.documentElement)
                        .getPropertyValue(name).trim() === '')
            );

            expect(empty, `every token must be defined in the ${theme} theme`).toEqual([]);
        }
    });
});
