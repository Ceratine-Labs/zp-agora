import { expect } from '@playwright/test';
import { test } from './support/fixtures.js';

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
