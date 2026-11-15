const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function setup(page) {
    const state = { grades: [], jobs: [], calls: [], rejectJob: false, errors: [] };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) state.calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        const match = path.match(/^\/sis\/hr\/(jobs|pay-grades)\/(page|save|[A-Z0-9]{26})\/$/);
        if (match) {
            const rows = match[1] === 'jobs' ? state.jobs : state.grades;
            if (match[2] === 'page') result = {
                items: rows.filter(item => !data.search || `${item.code} ${item.title}`.toLowerCase().includes(data.search.toLowerCase())),
                next_after: null, new_id: id(match[1] === 'jobs' ? 100 + rows.length : 200 + rows.length),
                currencies: [{ code: 'USD', digits: 2 }, { code: 'JPY', digits: 0 }, { code: 'BHD', digits: 3 }],
            };
            else if (match[2] === 'save') {
                if (match[1] === 'jobs' && state.rejectJob) {
                    state.rejectJob = false;
                    return route.fulfill({ status: 422, json: { error: 'Please correct the highlighted fields.', errors: [
                        { field: 'rate_minor', message: 'Fictional default needs review.' },
                    ] } });
                }
                result = { ...data, version: data.version == null ? 0 : data.version + 1 };
                const index = rows.findIndex(row => row.id === data.id);
                if (index === -1) rows.push(result); else rows[index] = result;
            } else result = rows.find(row => row.id === match[2]);
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="employees"]').click();
    await page.getByRole('button', { name: 'HR setup', exact: true }).click();
    return state;
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
}

test('HR can create a pay grade inside an unsaved job draft and retain values after a rejected save', async ({ page }) => {
    const state = await setup(page);
    await page.getByRole('button', { name: 'Add job definition', exact: true }).click();
    await page.getByLabel('Code', { exact: true }).fill('LIB.ASST');
    await page.getByLabel('Title', { exact: true }).fill('Fictional library assistant');
    await page.getByLabel('Default rate', { exact: true }).fill('25.50');
    await page.getByLabel('Weekly hours', { exact: true }).fill('37');
    await page.getByLabel('Additional minutes', { exact: true }).fill('30');
    await page.getByRole('button', { name: 'Create pay grade', exact: true }).click();
    await page.getByLabel('Code', { exact: true }).fill('H.1');
    await page.getByLabel('Title', { exact: true }).fill('Fictional hourly band');
    await page.getByLabel('Minimum rate', { exact: true }).fill('15');
    await page.getByLabel('Maximum rate', { exact: true }).fill('50');
    await page.getByRole('button', { name: 'Save pay grade', exact: true }).click();
    await expect(page.getByLabel('Title', { exact: true })).toHaveValue('Fictional library assistant');
    await expect(page.getByLabel('Default rate', { exact: true })).toHaveValue('25.50');
    await expect(page.getByLabel('Weekly hours', { exact: true })).toHaveValue('37');
    await expect(page.getByLabel('Additional minutes', { exact: true })).toHaveValue('30');
    await expect(page.getByLabel('Pay grade', { exact: true })).toHaveValue(state.grades[0].id);
    state.rejectJob = true;
    await page.getByRole('button', { name: 'Save job definition', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional default needs review.');
    await expect(page.getByLabel('Title', { exact: true })).toHaveValue('Fictional library assistant');
    await page.getByRole('button', { name: 'Save job definition', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Job definitions', exact: true })).toContainText('Fictional library assistant');
    expect(state.jobs[0]).toMatchObject({ rate_minor: 2550, weekly_minutes: 2250, version: 0,
        pay_grade_id: state.grades[0].id, currency: 'USD', basis: 'hourly' });
    await page.getByRole('button', { name: 'Edit definition', exact: true }).click();
    await page.getByLabel('Active definition', { exact: true }).uncheck();
    await page.getByRole('button', { name: 'Save job definition', exact: true }).click();
    expect(state.jobs[0]).toMatchObject({ active: false, version: 1 });
    await page.getByLabel('Search job definitions', { exact: true }).fill('LIB');
    await page.getByRole('button', { name: 'Find definitions', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Job definitions', exact: true })).toContainText('Retired');
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    expect(state.errors).toEqual([]);
    expect(state.calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('currency precision is explicit and invalid fractions do not become rounded saved amounts', async ({ page }) => {
    const state = await setup(page);
    await page.getByRole('button', { name: 'Pay grades', exact: true }).click();
    await page.getByRole('button', { name: 'Add pay grade', exact: true }).click();
    await page.getByLabel('Code', { exact: true }).fill('ANNUAL');
    await page.getByLabel('Title', { exact: true }).fill('Fictional annual band');
    await page.getByLabel('Currency', { exact: true }).selectOption('JPY');
    await page.getByLabel('Rate basis', { exact: true }).selectOption('annual');
    await page.getByLabel('Minimum rate', { exact: true }).fill('1500.5');
    await page.getByLabel('Maximum rate', { exact: true }).fill('5000');
    await page.getByRole('button', { name: 'Save pay grade', exact: true }).click();
    expect(state.grades).toHaveLength(0);
    expect(await page.getByLabel('Minimum rate', { exact: true }).evaluate(input => input.validationMessage)).toContain('0 decimal places');
    await page.getByLabel('Currency', { exact: true }).selectOption('BHD');
    await page.getByLabel('Minimum rate', { exact: true }).fill('15.125');
    await page.getByLabel('Maximum rate', { exact: true }).fill('50.999');
    await accessible(page);
    await page.getByRole('button', { name: 'Save pay grade', exact: true }).click();
    expect(state.grades[0]).toMatchObject({ currency: 'BHD', basis: 'annual', minimum_minor: 15125, maximum_minor: 50999 });
    expect(state.errors).toEqual([]);
});
