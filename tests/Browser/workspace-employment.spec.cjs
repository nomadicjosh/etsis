const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
const employee = id(1), person = id(2), entry = id(3);

async function setup(page, self = false) {
    const calls = [], errors = [];
    let active = true, title = 'Fictional librarian', status = 'pending', version = 0;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        const profile = { id: employee, person_id: person, given_name: 'Alex', family_name: 'Example',
            institutional_id: 'S-EXAMPLE', job_title: title, active, is_self: self, revision: 'fictional-employment-revision' };
        const time = { ...profile, id: entry, employee_id: employee, status, version,
            starts_at: '2026-08-11T09:00:00+00:00', ends_at: '2026-08-11T10:00:00+00:00', duration_seconds: 3600 };
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/employees/') result = { items: [profile], next_after: null };
        else if (path === '/sis/ui/data/timesheets/') result = { items: [time], next_after: null };
        else if (path.startsWith('/sis/ui/data/')) result = { items: [], next_after: null };
        else if (path.startsWith('/sis/ui/context/audit/')) result = { items: [
            { action: 'employee.updated', recorded_at: '2026-08-11 10:00:00', actor_id: id(9) },
        ], next_after: null };
        else if (path === `/sis/employees/${employee}/`) result = profile;
        else if (path === `/sis/employees/${employee}/edit/`) { active = data.active; title = data.job_title; }
        else if (path === `/sis/employees/${employee}/time-entries/`) result = {
            items: data.after ? [time] : Array.from({ length: 50 }, (_, index) => ({ ...time, id: index ? id(100 + index) : entry })),
            next_after: data.after ? null : id(149),
        };
        else if (path === `/sis/timesheets/${entry}/`) result = time;
        else if (path.endsWith('/decision/')) { status = data.approve ? 'approved' : 'rejected'; version++; }
        else if (path === '/sis/timesheets/') { status = 'pending'; version = 0; result = { id: entry, status }; }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="employees"]').click();
    return { calls, errors };
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
}

test('staff maintain employment and review paged time entries without leaving the connected workspace', async ({ page }) => {
    const { calls, errors } = await setup(page);
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('S-EXAMPLE');
    await expect(page.locator('#drawer-body tbody tr')).toHaveCount(50);
    await page.getByRole('button', { name: 'Next time page' }).click();
    await expect(page.locator('#drawer-body tbody tr')).toHaveCount(1);
    await page.getByRole('button', { name: 'Previous time page' }).click();
    await expect(page.locator('#drawer-body tbody tr')).toHaveCount(50);
    await page.getByRole('button', { name: 'View audit history' }).click();
    await expect(page.locator('#drawer-body')).toContainText('employee.updated');
    await page.getByRole('button', { name: 'Back to record' }).click();
    await expect(page.locator('#drawer-body')).toContainText('Recent time entries');
    await page.getByRole('button', { name: 'Review time entry' }).first().click();
    await expect(page.getByRole('button', { name: 'Back to employment', exact: true })).toBeVisible();
    await expect(page.locator('#drawer-body')).toContainText('1h 0m');
    await page.getByRole('button', { name: 'Approve time entry', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm time approval' }).click();
    await expect(page.locator('#drawer-body .status-badge')).toHaveText('approved');
    expect(calls.find(call => call.path.endsWith('/decision/'))?.data).toEqual({ approve: true, version: 0 });
    await page.getByRole('button', { name: 'Back to employment' }).click();
    await page.getByRole('button', { name: 'Edit employment' }).click();
    await page.getByLabel('Job title').fill('Fictional archivist');
    await page.getByLabel('Active employment').uncheck();
    await accessible(page);
    await page.getByRole('button', { name: 'Save employment' }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional archivist');
    await expect(page.getByRole('button', { name: 'Submit time for this employee' })).toHaveCount(0);
    expect(calls.find(call => call.path.endsWith('/edit/'))?.data).toEqual({
        job_title: 'Fictional archivist', active: false, revision: 'fictional-employment-revision',
    });
    await page.getByRole('button', { name: 'Review time entry' }).first().click();
    await expect(page.getByRole('button', { name: 'Back to employment', exact: true })).toBeVisible();
    await expect(page.locator('#drawer-body .status-badge')).toHaveText('approved');
    await accessible(page);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    expect(errors).toEqual([]);
    expect(calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('time submission validates elapsed hours and self review offers no approval action', async ({ page }) => {
    const { calls, errors } = await setup(page, true);
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await page.getByRole('button', { name: 'Submit time for this employee' }).click();
    await page.getByLabel(/^Started/).fill('2026-08-11T09:00');
    await page.getByLabel(/^Finished/).fill('2026-08-12T10:00');
    await page.getByRole('button', { name: 'Submit time entry', exact: true }).click();
    expect(await page.getByLabel(/^Finished/).evaluate(input => input.validationMessage)).toContain('24 elapsed hours');
    expect(calls.filter(call => call.path === '/sis/timesheets/')).toHaveLength(0);
    await page.getByLabel(/^Finished/).fill('2026-08-11T10:00');
    await page.getByRole('button', { name: 'Submit time entry', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Another authorized staff member');
    await expect(page.getByRole('button', { name: 'Approve time entry', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Reject time entry', exact: true })).toHaveCount(0);
    expect(calls.find(call => call.path === '/sis/timesheets/')?.data).toMatchObject({ employee_id: employee });
    await accessible(page);
    expect(errors).toEqual([]);
});
