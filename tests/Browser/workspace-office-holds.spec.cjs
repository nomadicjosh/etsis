const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
const data = request => request.postData() ? JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json')) : {};
const accessible = async page => {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
};

test('office delegates place and end holds through a private worklist without student-record access', async ({ page }) => {
    const office = id(1), person = id(2), hold = id(3), calls = [], errors = [];
    let operation = 100, ended = false;
    const record = () => ({ id: hold, person_id: person, code: 'FICTIONAL', severity: 99,
        starts_on: '2026-08-01', ends_on: ended ? '2026-08-09' : null, blocks_staff: true,
        office_id: office, office_name: 'Library', state: ended ? 'ended' : 'active', revision: ended ? 'new' : 'original',
        today: '2026-08-10', timezone: 'America/Los_Angeles', person_name: 'Alex Example', institutional_id: 'P-EXAMPLE' });
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, body = data(route.request());
        calls.push({ path, body, method: route.request().method(), csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/offices/') result = { items: [{ id: office, code: 'LIB', name: 'Library', active: 1 }], can_administer: false };
        else if (path === '/sis/ui/operation-id/') result = { id: id(operation++) };
        else if (path === '/sis/offices/people/') result = { items: [{ id: person, given_name: 'Alex', family_name: 'Example', institutional_id: 'P-EXAMPLE' }] };
        else if (path === '/sis/restrictions/queue/') result = { items: [record()], next_after: null };
        else if (path === `/sis/restrictions/${hold}/`) result = record();
        else if (path.endsWith('/notes/')) result = { items: [{ id: id(20), actor_id: id(21), actor_name: 'Jamie Example', content: 'Fictional private note.', created_at: '2026-08-10T17:00:00Z' }], next_after: null };
        else if (path.endsWith('/end/')) { ended = true; result = { status: 'saved' }; }
        else if (path === '/sis/restrictions/') result = { id: body.operation_id };
        else if (/\/sis\/restrictions\/[^/]+\/$/.test(path)) result = { ...record(), id: path.split('/')[3] };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/hold-office-fixture/');
    await expect(page.locator('.workspace-nav-link[data-workspace="students"]')).toHaveCount(0);
    await expect(page.locator('.workspace-nav-link[data-workspace="registration"]')).toHaveCount(0);
    await page.locator('.workspace-nav-link[data-workspace="holds"]').click();
    await expect(page.getByRole('table', { name: 'Office hold worklist' })).toContainText('Alex Example');
    await page.getByRole('button', { name: 'Review hold', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Owning office: Library');
    await expect(page.getByRole('button', { name: 'Back to student' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Set or shorten end date' }).click();
    await page.getByLabel(/^Ends on/).fill('2026-08-09');
    await page.getByLabel(/^Reason for ending the hold/).fill('Fictional resolution.');
    await accessible(page);
    await page.getByRole('button', { name: 'Save restriction end date' }).click();
    await expect(page.locator('#drawer-body .status-badge')).toHaveText('ended');
    await page.getByRole('button', { name: 'Back to holds' }).click();
    await page.getByRole('button', { name: 'Place a hold', exact: true }).click();
    await page.getByRole('searchbox', { name: 'Find a person for a hold' }).fill('Alex');
    await page.getByRole('button', { name: 'Search people' }).click();
    await page.getByRole('button', { name: 'Alex Example · P-EXAMPLE' }).click();
    await expect(page.getByRole('combobox', { name: /^Owning office/ })).toHaveValue(office);
    await page.getByLabel('Restriction code').fill('LIBRARY');
    await page.getByLabel(/^Starts on/).fill('2026-08-10');
    await page.getByLabel('Initial staff note').fill('Fictional placement reason.');
    await accessible(page);
    await page.getByRole('button', { name: 'Place restriction', exact: true }).click();
    expect(calls.find(call => call.path === '/sis/restrictions/')?.body.office_id).toBe(office);
    expect(calls.some(call => /^\/sis\/(students|accounts|grades)\//.test(call.path))).toBe(false);
    await page.setViewportSize({ width: 320, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    expect(errors).toEqual([]);
    expect(calls.filter(call => call.method !== 'GET').every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('administrators configure offices and dated delegates without assigning roles or deleting records', async ({ page }) => {
    const office = id(100), user = id(101), calls = [], errors = [];
    let row = null, member = null;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, body = data(route.request());
        calls.push({ path, body });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/operation-id/') result = { id: office };
        else if (path === '/sis/offices/') result = { items: row ? [row] : [], can_administer: true, today: '2026-08-10' };
        else if (path === `/sis/offices/${office}/save/`) { row = { ...body, id: office, active: body.active ? 1 : 0, revision: body.revision + 1 }; }
        else if (path === `/sis/offices/${office}/delegates/`) result = { office: row, items: member ? [member] : [], next_after: null, today: '2026-08-10' };
        else if (path === '/sis/offices/accounts/') result = { items: [{ id: id(102), owner_user_id: user, given_name: 'Jamie', family_name: 'Example', institutional_id: 'P-JAMIE' }] };
        else if (path === `/sis/offices/${office}/delegates/save/`) { member = { ...body, id: id(103), first_name: 'Jamie', last_name: 'Example', active: body.active ? 1 : 0, revision: body.revision + 1 }; }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/office-admin-fixture/');
    await expect(page.locator('#workspace-navigation a[href="/portal/timetable/"]')).toHaveCount(0);
    await expect(page.locator('#workspace-navigation a[href="/portal/bookings/"]')).toHaveCount(0);
    await page.getByRole('button', { name: 'Account menu' }).click();
    await expect(page.getByRole('link', { name: 'Timetable', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Bookings', exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    await page.locator('.workspace-nav-link[data-workspace="holds"]').click();
    await page.getByRole('button', { name: 'Offices & delegation' }).click();
    await page.getByRole('button', { name: 'Add office', exact: true }).click();
    await page.getByLabel('Office code').fill('LIB');
    await page.getByLabel('Office name').fill('Library');
    await page.getByRole('button', { name: 'Create office', exact: true }).click();
    await page.getByRole('button', { name: 'Manage delegates' }).click();
    await page.getByRole('button', { name: 'Add delegate', exact: true }).click();
    await page.getByRole('searchbox', { name: 'Find an enabled account' }).fill('Jamie');
    await page.getByRole('button', { name: 'Search people' }).click();
    await page.getByRole('button', { name: 'Jamie Example · P-JAMIE' }).click();
    await expect(page.getByLabel('Delegation starts')).toHaveValue('2026-08-10');
    await page.getByLabel('Delegation ends').fill('2026-08-20');
    await accessible(page);
    await page.getByRole('button', { name: 'Save office delegation' }).click();
    await expect(page.getByRole('list', { name: 'Office delegates' })).toContainText('Jamie Example');
    await page.getByRole('button', { name: 'Edit delegation' }).click();
    await page.getByLabel('Enable this delegation').uncheck();
    await page.getByRole('button', { name: 'Save office delegation' }).click();
    expect(calls.filter(call => call.path.endsWith('/delegates/save/')).map(call => call.body)).toMatchObject([
        { user_id: user, revision: -1, starts_on: '2026-08-10', ends_on: '2026-08-20', active: true },
        { user_id: user, revision: 0, active: false },
    ]);
    expect(calls.some(call => call.path.includes('/sis/access/'))).toBe(false);
    await page.getByRole('button', { name: 'Back to offices' }).click();
    await recordAction(page, 'Edit office');
    await page.getByLabel('Active office').uncheck();
    await page.getByRole('button', { name: 'Save office', exact: true }).click();
    await expect(page.getByRole('list', { name: 'Institution offices' })).toContainText('Retired');
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
