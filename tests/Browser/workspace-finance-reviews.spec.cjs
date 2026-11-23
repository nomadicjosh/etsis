const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K000000000000000000000${String(n).padStart(2, '0')}`;

test('finance queue supports claiming and completing a task with private reasons and no automatic postings', async ({ page }) => {
    const calls = [], errors = [], actions = [];
    let status = 'open', version = 0, assigned = false, operation = 10;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/operation-id/') result = { id: id(operation++) };
        else if (path === '/sis/finance-reviews/') result = { items: [{ id: id(1), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', status, created_at: '2026-11-07T12:00:00Z' }], next_after: null };
        else if (path === `/sis/finance-reviews/${id(1)}/`) result = { id: id(1), status, version, assigned, assigned_to_me: assigned,
            student: { id: id(2), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }, source: { id: id(3), starts_on: '2026-11-01', ends_on: null, voided: false } };
        else if (path.endsWith('/change/')) {
            const previous = status; status = data.action === 'claim' ? 'in_review' : 'completed'; assigned = true; version++;
            actions.unshift({ id: data.operation_id, note: data.note, action: data.action, previous_status: previous, status, actor_name: 'Taylor Example', created_at: '2026-11-07T13:00:00Z' });
            result = { id: id(1), status };
        } else if (path.endsWith('/history/')) result = { items: actions, next_after: null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.getByRole('button', { name: 'Finance reviews', exact: false }).click();
    await page.getByRole('button', { name: 'Review finance task', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Complete finance review', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Claim finance review', exact: true }).click();
    await page.getByLabel('Private finance reason / outcome').fill('Fictional private review <script>example</script>');
    await page.getByRole('button', { name: 'Save finance review change', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Assigned to you.');
    await expect(page.locator('#drawer-body script')).toHaveCount(0);
    await page.getByRole('button', { name: 'Complete finance review', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('closes the review task only');
    await page.getByLabel('Private finance reason / outcome').fill('Fictional outcome: no adjustment necessary');
    await page.setViewportSize({ width: 375, height: 812 });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    await page.getByRole('button', { name: 'Save finance review change', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('completed');
    await expect(page.getByRole('region', { name: 'Private finance review history', exact: true })).toContainText('no adjustment necessary');
    expect(calls.filter(call => call.path.endsWith('/change/'))).toMatchObject([{ data: { version: 0, action: 'claim' }, csrf: 'fictional-test-token' }, { data: { version: 1, action: 'complete' }, csrf: 'fictional-test-token' }]);
    expect(calls.some(call => /accounts|entries|refund|mail/.test(call.path))).toBe(false);
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

async function linkedFixture(page, canRead = true) {
    const state = { calls: [], errors: [], status: 'in_review', position: 2, reversed: false, history: [], version: 1 };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        state.calls.push({ path, data });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/operation-id/') result = { id: id(10) };
        else if (path === '/sis/finance-reviews/') result = { items: [{ id: id(1), given_name: 'Alex',
            family_name: 'Example', institutional_id: 'S-FICTIONAL', status: state.status,
            created_at: '2026-11-16T12:00:00Z' }], next_after: null };
        else if (path === `/sis/finance-reviews/${id(1)}/`) result = { id: id(1), status: state.status,
            version: state.version, assigned: true, assigned_to_me: true, can_view_accounts: canRead,
            student: { id: id(2), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' },
            source: { id: id(3), starts_on: '2026-11-01', ends_on: null, voided: false } };
        else if (path === `/sis/finance-reviews/${id(1)}/accounts/`) result = {
            items: [{ id: id(4), term_code: '26/FA', currency: 'USD' }], next_after: null,
        };
        else if (path === `/sis/accounts/${id(4)}/`) result = { id: id(4), student_id: id(2), term_id: id(5),
            institutional_id: 'S-FICTIONAL', balance_minor: -500, currency: 'USD', position: state.position,
            entries: Array.from({ length: 27 }, (_, i) => ({ operation_id: id(30 + i), type: i ? 'payment' : 'charge',
                minor: 100 + i, currency: 'USD', reversed: i === 0 && state.reversed })) };
        else if (path === `/sis/finance-reviews/${id(1)}/history/`) result = { items: state.history, next_after: null };
        else if (path === `/sis/finance-reviews/${id(1)}/change/`) {
            if ((data.postings || []).some(p => p.position !== state.position)) {
                return route.fulfill({ status: 409, json: { error: 'This record changed. Refresh linked entries.' } });
            }
            state.status = 'completed'; state.version++;
            state.history.unshift({ previous_status: 'in_review', status: 'completed', note: data.note,
                actor_name: 'Fictional reviewer', created_at: '2026-11-16T12:30:00Z',
                postings: (data.postings || []).map(p => ({ ...p, type: 'charge', minor: 100,
                    currency: 'USD', term_id: id(5), reversed: state.reversed })) });
            result = { id: id(1), status: state.status };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.getByRole('button', { name: 'Finance reviews', exact: false }).click();
    await page.getByRole('button', { name: 'Review finance task', exact: true }).click();
    return state;
}

test('a finance task connects statements and verified entries, preserving the note through a stale completion', async ({ page }) => {
    const state = await linkedFixture(page);
    const drawer = page.locator('#record-drawer');
    await drawer.getByRole('button', { name: 'Review student accounts', exact: true }).click();
    await drawer.getByRole('button', { name: 'Open 26/FA account', exact: true }).click();
    await expect(drawer.getByRole('table', { name: 'Account entries', exact: true }).locator('tbody tr')).toHaveCount(25);
    await drawer.getByRole('button', { name: 'Next account entries', exact: true }).click();
    await expect(drawer.getByRole('table', { name: 'Account entries', exact: true }).locator('tbody tr')).toHaveCount(2);
    await drawer.getByRole('button', { name: 'Back to billing accounts', exact: true }).click();
    await drawer.getByRole('button', { name: 'Back to finance task', exact: true }).click();
    await drawer.getByRole('button', { name: 'Complete finance review', exact: true }).click();
    await drawer.getByLabel('Private finance reason / outcome').fill('Fictional reviewed source entry.');
    await drawer.getByRole('button', { name: 'Show 26/FA · USD entries', exact: true }).click();
    await drawer.getByRole('checkbox').first().check();
    await expect(drawer.getByRole('region', { name: 'Linked account entries' })).toContainText('1 of 10 entries linked');
    state.position = 3; state.reversed = true;
    await drawer.getByRole('button', { name: 'Save finance review change', exact: true }).click();
    await expect(drawer.getByLabel('Private finance reason / outcome')).toHaveValue('Fictional reviewed source entry.');
    await expect(drawer.getByRole('checkbox').first()).toBeChecked();
    await drawer.getByRole('button', { name: 'Refresh linked entries', exact: true }).click();
    await expect(drawer.getByRole('region', { name: 'Linked account entries' })).toContainText('Linked entries refreshed');
    await page.setViewportSize({ width: 375, height: 812 });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    await drawer.getByRole('button', { name: 'Save finance review change', exact: true }).click();
    await expect(drawer.getByRole('region', { name: 'Private finance review history' })).toContainText('Reversed when linked');
    const attempts = state.calls.filter(c => c.path.endsWith('/change/')).map(c => c.data);
    expect(attempts).toEqual([
        { note: 'Fictional reviewed source entry.', version: 1, action: 'complete', operation_id: id(10),
            postings: [{ account_id: id(4), operation_id: id(30), position: 2 }] },
        { note: 'Fictional reviewed source entry.', version: 1, action: 'complete', operation_id: id(10),
            postings: [{ account_id: id(4), operation_id: id(30), position: 3 }] },
    ]);
    expect(state.calls.some(c => c.data && /\/entries\/|\/refund|\/billing-batches\//.test(c.path))).toBe(false);
    expect(state.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test('linked-entry choices remain bounded and task-only authority offers no account controls', async ({ page }) => {
    const state = await linkedFixture(page);
    const drawer = page.locator('#record-drawer');
    await drawer.getByRole('button', { name: 'Complete finance review', exact: true }).click();
    await drawer.getByRole('button', { name: 'Show 26/FA · USD entries', exact: true }).click();
    const checks = drawer.getByRole('checkbox');
    await expect(checks).toHaveCount(25);
    for (let i = 0; i < 10; i++) await checks.nth(i).check();
    await checks.nth(10).click();
    await expect(checks.nth(10)).not.toBeChecked();
    await expect(drawer.getByRole('region', { name: 'Linked account entries' })).toContainText('Link at most ten entries.');
    await drawer.getByRole('button', { name: 'Next entries to link', exact: true }).click();
    await expect(checks).toHaveCount(2);
    await drawer.getByRole('button', { name: 'Previous entries to link', exact: true }).click();
    await expect(checks.first()).toBeChecked();
    expect(state.calls.some(c => c.path.endsWith('/change/'))).toBe(false);
    const limited = await linkedFixture(page, false);
    await expect(page.getByRole('button', { name: 'Review student accounts', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Complete finance review', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Linked account entries' })).toHaveCount(0);
    expect(limited.calls.some(c => c.path.endsWith('/accounts/') || /^\/sis\/accounts\//.test(c.path))).toBe(false);
    expect(limited.errors).toEqual([]);
});
