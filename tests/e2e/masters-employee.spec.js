import { expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { signInThroughTheForm, test } from './support/fixtures.js';

/**
 * Setup → People and assets → Employees, Employee posts, Employee areas (T023).
 *
 *   npx playwright test tests/e2e/masters-employee.spec.js --project=desktop
 *   npx playwright test tests/e2e/masters-employee.spec.js --project=mobile
 *
 * What only a browser can show, and so what this asserts:
 *
 *  - the employee list names its procedure (feature-rules §3.4), opens on the
 *    ACTIVE people, and "Inactive" in the Status picker shows the others,
 *    with the choice carried in the URL;
 *  - the Post picker in the scope narrows the list to one post at one site,
 *    every row then carrying that post;
 *  - a name opens the employee (§3.7) with their counting areas and the
 *    shifts they worked — for an inactive person too;
 *  - the posts and the employee areas list with their procedures;
 *  - nothing scrolls the window sideways on a phone.
 *
 * READ-ONLY, and over whatever the local stub holds: the suite signs in as
 * the shared Playwright auditor (people.*.view through *.*.view, no edit), so
 * nothing here writes and no Edit control exists to be offered. The
 * procedures and the permission split are proved row for row in
 * tests/Feature/People/EmployeeMasterTest. Nothing in this module writes
 * PumpIT.
 *
 * THE EDIT (a named write to PumpIT, PM decision 01a1ceba-4d74) is the last
 * describe, and it is opt-in: AGORA_PEOPLE_EDITOR_E2E=1, with the server's
 * PumpIT connection pointed at the local container, because the fixture
 * (support/people-editor-fixture.php) refuses unless every connection is
 * loopback. It signs in a separate TEST- editor at TEST- branch 969, changes
 * the person and their areas through the screen, puts the change back from
 * the employee's page, and cleans everything up, ledger rows included. Each
 * write-back is confirmed in a SweetAlert2 dialog naming the PumpIT table, the
 * person and each field old → new (Ryan, 12 Nov 2026), and Cancel writes nothing.
 */

async function sidewaysOverflow(page) {
    return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

function rows(page, isMobile) {
    return isMobile ? page.locator('.dg-card') : page.locator('.data-grid tbody tr');
}

test.describe('employee master', () => {
    test('lists the active people with their procedure, and the inactive ones on asking', async ({ signedIn: page, isMobile }) => {
        await page.goto('/app/setup/employees');

        await expect(page.getByRole('heading', { name: 'Employees', level: 1 })).toBeVisible();
        await expect(page.getByText('usp_People_GridEmployees').first()).toBeVisible();
        expect(await rows(page, isMobile).count()).toBeGreaterThan(0);
        await expect(rows(page, isMobile).filter({ hasText: 'Inactive' })).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Edit', exact: true })).toHaveCount(0);

        await page.goto('/app/setup/employees?status=inactive');
        await expect(page.locator('#employees-status')).toHaveValue('inactive');
        expect(await rows(page, isMobile).count()).toBeGreaterThan(0);
        await expect(rows(page, isMobile).filter({ hasText: 'Active' }).filter({ hasNotText: 'Inactive' })).toHaveCount(0);

        expect(await sidewaysOverflow(page), 'the window does not scroll sideways').toBeLessThanOrEqual(1);
    });

    test('the post picker narrows one site to one post', async ({ signedIn: page, isMobile }) => {
        // Caltex Ulundi (8) has Fuel Cashiers on the customer's list and in the local stub.
        await page.goto('/app/setup/employees?' + new URLSearchParams({
            'branches[]': '8', 'f[Post][q]': 'Fuel Cashier', 'f[Post][eq]': '1',
        }));

        const count = await rows(page, isMobile).count();
        expect(count).toBeGreaterThan(0);
        await expect(rows(page, isMobile).filter({ hasText: 'Fuel Cashier' })).toHaveCount(count);
        await expect(rows(page, isMobile).filter({ hasText: 'Caltex Ulundi' })).toHaveCount(count);
        await expect(page.locator('#employees-post')).toHaveValue('Fuel Cashier');
    });

    test('a name opens the person, inactive ones with the shifts they worked', async ({ signedIn: page, isMobile }) => {
        test.skip(isMobile, 'The link is the same on a phone; the table is the desktop rendering.');

        await page.goto('/app/setup/employees?status=inactive');
        const link = page.locator('.data-grid tbody tr a[href*="/app/setup/employees/"]').first();
        const name = (await link.innerText()).trim();
        await link.click();

        await expect(page).toHaveURL(/\/app\/setup\/employees\/\d+\/[A-Za-z0-9_-]+$/);
        await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible();
        await expect(page.locator('main').getByText('Inactive in PumpIT.')).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Counting areas' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Shifts worked' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Back to employees' })).toBeVisible();
    });

    test('posts and employee areas list with their procedures', async ({ signedIn: page, isMobile }) => {
        await page.goto('/app/setup/employee-posts?branches[]=6');
        await expect(page.getByRole('heading', { name: 'Employee posts', level: 1 })).toBeVisible();
        await expect(page.getByText('usp_People_GridPosts').first()).toBeVisible();
        await expect(page.getByText(/The next post at .+ would be number \d+/)).toBeVisible();
        expect(await rows(page, isMobile).count()).toBeGreaterThan(0);

        await page.goto('/app/setup/employee-areas');
        await expect(page.getByRole('heading', { name: 'Employee areas', level: 1 })).toBeVisible();
        await expect(page.getByText('usp_People_GridEmployeeAreas').first()).toBeVisible();
        expect(await rows(page, isMobile).count()).toBeGreaterThan(0);

        expect(await sidewaysOverflow(page), 'the window does not scroll sideways').toBeLessThanOrEqual(1);
    });
});

test.describe('employee edit (named write, local only)', () => {
    test('the auditor is refused; an editor saves, sees the change recorded, and puts it back', async ({ signedIn: page, browser, baseURL, isMobile }) => {
        test.skip(process.env.AGORA_PEOPLE_EDITOR_E2E !== '1', 'This write-path test requires an explicit local fixture opt-in.');
        expect(['127.0.0.1', 'localhost', '[::1]']).toContain(new URL(baseURL).hostname);
        test.setTimeout(120_000);

        const token = randomBytes(6).toString('hex');
        const fixture = (action) => JSON.parse(execFileSync('php', [
            fileURLToPath(new URL('./support/people-editor-fixture.php', import.meta.url)), action, token,
        ], { encoding: 'utf8', env: process.env }));
        const prepared = fixture('prepare');
        expect(['127.0.0.1', 'localhost', '::1']).toContain(prepared.sql_host);
        let editorContext;

        try {
            // The read-only auditor: nothing to press, and the write route refuses.
            await page.goto(prepared.show);
            await expect(page.getByRole('heading', { name: prepared.name, level: 1 })).toBeVisible();
            await expect(page.getByRole('link', { name: 'Edit employee' })).toHaveCount(0);
            const denied = await page.request.put(prepared.show, { form: {
                _token: await page.locator('meta[name="csrf-token"]').getAttribute('content'),
                IsActive: '0', PostNo: '18', ExpectedIsActive: '1', ExpectedPostNo: '18', Reason: 'TEST-auditor must be refused',
            }, maxRedirects: 0 });
            expect(denied.status()).toBe(403);
            expect(fixture('snapshot').ledger).toHaveLength(0);

            editorContext = await browser.newContext({ baseURL, viewport: page.viewportSize(), storageState: { cookies: [], origins: [] } });
            const editor = await editorContext.newPage();
            await signInThroughTheForm(editor, { email: prepared.email, password: prepared.password });
            await editor.waitForURL('**/app/setup/employees**');

            await editor.goto(prepared.show);
            await editor.getByRole('link', { name: 'Edit employee' }).click();
            await expect(editor).toHaveURL(new RegExp(prepared.edit.replace(/\//g, '\\/') + '$'));

            // The person: inactive, a post change and a known-as name.
            await editor.locator('#f-IsActive').uncheck();
            await editor.locator('#f-PostNo').selectOption('46');
            await editor.locator('#f-NickName').fill('TEST-Bee');
            await editor.locator('#f-Reason').fill('TEST-browser edit of a person');

            // Ryan, 12 Nov 2026: every write-back is confirmed first, and the
            // confirmation names the PumpIT table, the person and each field
            // old → new. Cancel writes nothing.
            const dialog = editor.locator('.swal2-popup');
            await editor.getByRole('button', { name: 'Save employee' }).click();
            await expect(dialog).toContainText('Write this to PumpIT?');
            await expect(dialog).toContainText(`PumpIT BRN_Employee · ${prepared.code} ${prepared.name}`);
            await expect(dialog).toContainText('IsActive: Active → Inactive');
            await expect(dialog).toContainText('PostNo: TEST-Fuel Cashier (post 18) → TEST-Site Manager (post 46)');
            await expect(dialog).toContainText('NickName: blank → TEST-Bee');
            await editor.locator('.swal2-cancel').click();
            await expect(dialog).toHaveCount(0);
            expect(fixture('snapshot').ledger).toHaveLength(0);

            await editor.getByRole('button', { name: 'Save employee' }).click();
            await editor.locator('.swal2-confirm').click();
            await expect(editor.getByText(/change\(s\) written to PumpIT/)).toBeVisible();

            // The areas: assign the one counting area.
            await editor.getByLabel('TEST-Fuel bay').check();
            await editor.locator('#f-AreaReason').fill('TEST-browser edit of areas');
            await editor.getByRole('button', { name: 'Save areas' }).click();
            await expect(editor.locator('.swal2-popup')).toContainText(`PumpIT BRN_EmployeeArea · ${prepared.code} ${prepared.name}`);
            await expect(editor.locator('.swal2-popup')).toContainText('Area added: TEST-Fuel bay');
            await editor.locator('.swal2-confirm').click();
            await expect(editor.getByText(/area\(s\) added/)).toBeVisible();

            let snap = fixture('snapshot');
            expect([Number(snap.employee.IsActive), Number(snap.employee.PostNo), snap.employee.NickName]).toEqual([0, 46, 'TEST-Bee']);
            expect(snap.areas.map(Number)).toEqual([7]);
            expect(snap.ledger.map((r) => r.Field)).toEqual(['IsActive', 'PostNo', 'NickName', 'EmployeeArea']);

            if (isMobile) {
                const overflow = await editor.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
                expect(overflow, 'the edit screen does not scroll sideways').toBeLessThanOrEqual(1);
            }

            // Put back the newest press (the areas), from the employee's page.
            await editor.goto(prepared.show);
            await expect(editor.getByRole('heading', { name: 'Changes written to PumpIT' })).toBeVisible();
            await editor.locator('#f-Reason').fill('TEST-browser put back');
            await editor.getByRole('button', { name: 'Put back' }).click();
            await expect(editor.locator('.swal2-popup')).toContainText(`PumpIT BRN_EmployeeArea · ${prepared.code} ${prepared.name}`);
            await expect(editor.locator('.swal2-popup')).toContainText('Area added TEST-Fuel bay: assigned → —');
            await editor.locator('.swal2-confirm').click();
            await expect(editor.getByText(/put back in PumpIT/)).toBeVisible();

            snap = fixture('snapshot');
            expect(snap.areas).toEqual([]);
            expect(snap.ledger.filter((r) => r.State === 'reversed').map((r) => r.Field)).toEqual(['EmployeeArea']);
        } finally {
            await editorContext?.close();
            const cleaned = fixture('cleanup');
            expect(cleaned).toEqual({ users: 0, employees: 0 });
        }
    });
});
