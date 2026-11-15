const { test, expect } = require('@playwright/test');
const person = String(2).padStart(26, '0');

async function setup(page) {
    const state = { status: 200, mutations: [] };
    await page.route('**/sis/**', async route => {
        const request = route.request();
        const path = new URL(request.url()).pathname;
        if (state.status !== 200) {
            await route.fulfill({ status: state.status, json: { error: state.status === 401
                ? 'Sign in again to continue.' : 'Access denied.' } });
            return;
        }
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        if (path === '/sis/ui/data/people/') result = { items: [{ id: person, given_name: 'Fictional', family_name: 'Colleague', institutional_id: 'F-EXAMPLE' }], next_after: null };
        if (path === '/sis/employees/') state.mutations.push(request.postData());
        await route.fulfill({ json: result });
    });
    await page.goto('/admin/');
    return state;
}

test('expired workspace navigation opens sign-in without exposing a permission error', async ({ page }) => {
    const state = await setup(page);
    state.status = 401;
    await page.locator('.workspace-nav-link[data-workspace="employees"]').click();
    await expect(page).toHaveURL(/\/login\/$/);
    expect(state.mutations).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('an expired form goes to sign-in without forwarding or replaying its private values', async ({ page }) => {
    const state = await setup(page);
    await page.locator('.workspace-nav-link[data-workspace="employees"]').click();
    await page.getByRole('button', { name: 'Add employment', exact: true }).click();
    await page.getByLabel(/^Person/).selectOption(person);
    await page.getByLabel(/^Job title/).fill('Fictional private title');
    state.status = 401;
    await page.locator('#drawer-body button[type="submit"]').click();
    await expect(page).toHaveURL(/\/login\/$/);
    expect(state.mutations).toEqual([]);
    expect(page.url()).not.toContain('Fictional');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('a permission denial keeps the signed-in workspace and its form values', async ({ page }) => {
    const state = await setup(page);
    await page.locator('.workspace-nav-link[data-workspace="employees"]').click();
    await page.getByRole('button', { name: 'Add employment', exact: true }).click();
    await page.getByLabel(/^Person/).selectOption(person);
    await page.getByLabel(/^Job title/).fill('Fictional retained title');
    state.status = 403;
    await page.locator('#drawer-body button[type="submit"]').click();
    await expect(page.locator('#drawer-body')).toContainText('This task needs additional access.');
    await expect(page.getByLabel(/^Job title/)).toHaveValue('Fictional retained title');
    await expect(page).toHaveURL(/\/admin\/workspace\/employees\/$/);
});
