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
