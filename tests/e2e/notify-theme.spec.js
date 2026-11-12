import { anonymous, expect, test } from './support/fixtures.js';

test.use(anonymous);
// Each case visits the gallery and captures several real dialog states.
test.setTimeout(60_000);

function contrast(first, second) {
    const luminance = (color) => {
        const values = color.match(/[\d.]+/g).slice(0, 3).map((part) => {
            const value = Number(part) / 255;
            return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
        });
        return values[0] * 0.2126 + values[1] * 0.7152 + values[2] * 0.0722;
    };
    const values = [luminance(first), luminance(second)].sort((a, b) => b - a);
    return (values[0] + 0.05) / (values[1] + 0.05);
}

async function palette(popup) {
    return popup.evaluate((element) => {
        const style = getComputedStyle(element);
        const probe = document.createElement('span');
        element.append(probe);
        const token = (name) => {
            probe.style.color = `var(--${name})`;
            return getComputedStyle(probe).color;
        };
        const result = {
            background: style.backgroundColor,
            surface: token('surface'),
            title: getComputedStyle(element.querySelector('.swal2-title')).color,
            ink: token('ink'),
            icon: getComputedStyle(element.querySelector('.swal2-icon')).color,
        };
        probe.remove();
        return result;
    });
}

async function assertPalette(popup) {
    const colors = await palette(popup);
    expect(colors.background).toBe(colors.surface);
    expect(colors.title).toBe(colors.ink);
    expect(contrast(colors.title, colors.background)).toBeGreaterThanOrEqual(4.5);
    expect(contrast(colors.icon, colors.background)).toBeGreaterThanOrEqual(3);
}

async function assertFocus(button, popup) {
    await expect(button).toBeFocused();
    const style = await button.evaluate((element) => {
        const computed = getComputedStyle(element);
        return { color: computed.color, background: computed.backgroundColor,
            outline: computed.outlineColor, width: computed.outlineWidth, style: computed.outlineStyle };
    });
    const colors = await palette(popup);
    expect(style.style).toBe('solid');
    expect(parseFloat(style.width)).toBeGreaterThanOrEqual(2);
    expect(contrast(style.outline, colors.background)).toBeGreaterThanOrEqual(3);
    expect(contrast(style.color, style.background)).toBeGreaterThanOrEqual(4.5);
}

for (const theme of ['light', 'dark']) {
    test(`confirm, error and toast use the ${theme} palette and keyboard controls`, async ({ page }, testInfo) => {
        await page.goto('/dev/components');
        await page.evaluate((value) => document.documentElement.setAttribute('data-theme', value), theme);
        await page.waitForFunction(() => window.Agora?.notify);
        await page.evaluate(() => {
            window.Agora.notify.confirm('Reverse reconciliation?', {
                text: 'The selected changes will be reversed.\n\nOriginal counts will be restored.',
                action: 'Reverse', danger: true,
            }).then((result) => { window.notificationResult = result; });
        });
        const popup = page.locator('.ag-dialog');
        await expect(popup).toBeVisible();
        await assertPalette(popup);
        const body = popup.locator('.ag-dialog-body');
        await expect(body).toHaveCSS('white-space', 'pre-line');
        expect(await body.textContent()).toContain('\n\n');
        const cancel = popup.getByRole('button', { name: 'Cancel', exact: true });
        const confirm = popup.getByRole('button', { name: 'Reverse', exact: true });
        await assertFocus(cancel, popup);
        await page.keyboard.press('Tab');
        await assertFocus(confirm, popup);
        await page.screenshot({ path: testInfo.outputPath(`confirm-${theme}.png`) });
        await page.keyboard.press('Escape');
        await expect(popup).toBeHidden();
        expect(await page.evaluate(() => window.notificationResult)).toBe(false);

        await page.evaluate(() => { void window.Agora.notify.error('Unable to reconcile', 'No records changed.\n\nReview the counts and try again.'); });
        await expect(popup).toBeVisible();
        await assertPalette(popup);
        await assertFocus(popup.getByRole('button', { name: 'OK', exact: true }), popup);
        await expect(popup.locator('.swal2-x-mark-line-left')).toHaveCSS('background-color', await popup.locator('.swal2-icon').evaluate((element) => getComputedStyle(element).color));
        await page.screenshot({ path: testInfo.outputPath(`error-${theme}.png`) });
        await page.keyboard.press('Enter');
        await expect(popup).toBeHidden();

        // Inspect the short-lived toast without racing its real 2600ms timer.
        await page.clock.install();
        await page.clock.pauseAt(new Date());
        await page.evaluate(() => { void window.Agora.notify.toast('Reconciliation saved'); });
        await page.clock.runFor(100);
        const toast = page.locator('.ag-toast');
        await expect(toast).toBeVisible();
        await assertPalette(toast);
        const successColor = await toast.locator('.swal2-icon').evaluate((element) => getComputedStyle(element).color);
        await expect(toast.locator('.swal2-success-line-tip')).toHaveCSS('background-color', successColor);
        await expect(toast.locator('.swal2-success-ring')).toHaveCSS('border-top-color', successColor);
        const progressColor = await toast.locator('.swal2-timer-progress-bar').evaluate((element) => getComputedStyle(element).backgroundColor);
        expect(contrast(progressColor, (await palette(toast)).background)).toBeGreaterThanOrEqual(3);
        await page.screenshot({ path: testInfo.outputPath(`toast-${theme}.png`) });
        await page.clock.runFor(3_000);
        await expect(toast).toBeHidden({ timeout: 5_000 });
    });

    test(`a ${theme} reason prompt stays interactive inside a native dialog`, async ({ page }, testInfo) => {
        await page.goto('/dev/components');
        await page.waitForFunction(() => window.Agora?.notify);
        await page.evaluate((value) => {
            document.documentElement.setAttribute('data-theme', value);
            const host = document.createElement('dialog');
            host.id = 'notify-host';
            host.innerHTML = '<h2>Reconciliation details</h2><button>Keep editing</button>';
            document.body.append(host);
            host.showModal();
            window.Agora.notify.ask('Explain the correction', { text: 'A reason is saved in the audit trail.', action: 'Save reason' })
                .then((result) => { window.reasonResult = result; });
        }, theme);
        const popup = page.locator('#notify-host .ag-dialog');
        await expect(popup).toBeVisible();
        await assertPalette(popup);
        const input = popup.getByRole('textbox', { name: 'Explain the correction' });
        await input.press('Enter');
        await expect(popup.locator('.swal2-validation-message')).toContainText('A reason is required.');
        await expect(input).toBeFocused();
        await expect(input).toHaveCSS('outline-style', 'solid');
        await input.fill('Counts verified against the signed sheet');
        await page.screenshot({ path: testInfo.outputPath(`reason-${theme}.png`) });
        await input.press('Enter');
        await expect(popup).toBeHidden();
        expect(await page.evaluate(() => window.reasonResult)).toBe('Counts verified against the signed sheet');
        await expect(page.locator('#notify-host')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    });
}
