const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
async function fixture(page, options = {}) {
    const report = id(1), base = `/sis/report-studio/${report}/`;
    const state = { calls: [], errors: [], mode: options.static ? 'static' : 'dynamic', version: 0,
        expires: null, fail: options.fail || 0, operation: 0 };
    const lifecycle = () => ({ mode: state.mode, expires_at: state.expires,
        captured_at: state.mode === 'static' ? '2026-11-14T12:00:00+00:00' : null,
        row_count: state.mode === 'static' ? 1 : null });
    page.on('pageerror', e => state.errors.push(e.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        state.calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = { items: [{ id: report, name: 'Fictional advising report' }], next_after: null };
        else if (path === '/sis/ui/operation-id/') result = { id: id(80 + ++state.operation) };
        else if (path === `/sis/reports/${report}/`) result = { id: report, relational: true };
        else if (path === base) result = { id: report, name: 'Fictional advising report', version: state.version,
            can_manage: !options.recipient, can_select_students: false, lifecycle: lifecycle(),
            definition: { dataset: 'students', columns: [{ field: 'institutional_id', label: 'Institutional ID', aggregate: 'none' }] } };
        else if (path === `${base}settings/`) {
            if (data) {
                if (state.fail) {
                    const status = state.fail; state.fail = 0;
                    return route.fulfill({ status, json: { error: status === 409 ? 'This report changed. Reload its settings.' : 'Unable to complete this request.' } });
                }
                state.mode = data.mode; state.expires = data.expires_after_days ? '2026-12-14T12:00:00+00:00' : null;
                state.version++;
                result = { id: report, version: state.version, lifecycle: lifecycle() };
            } else result = { name: 'Fictional advising report', version: state.version, lifecycle: lifecycle() };
        } else if (path === `${base}run/`) result = { columns: ['Institutional ID'], items: [{ c0: 'Fictional-S-1' }],
            offset: 0, next_offset: null, limit: 50, grouped: false, lifecycle: lifecycle() };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(options.recipient ? '/admin/report-fixture/' : '/admin/');
    await page.locator(`.workspace-nav-link[data-workspace="${options.recipient ? 'sharedreports' : 'reports'}"]`).click();
    await page.getByRole('button', { name: 'Fictional advising report', exact: true }).click();
    return state;
}

test('owners independently choose static results and automatic removal in accessible report settings', async ({ page }) => {
    const f = await fixture(page);
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('button', { name: 'Report settings', exact: true }).click();
    await studio.getByRole('radio', { name: 'Static one-time report', exact: true }).check();
    await studio.getByRole('checkbox', { name: /temporary \/ one-off report/ }).check();
    await studio.getByLabel('Remove after this many days', { exact: true }).fill('30');
    await page.setViewportSize({ width: 375, height: 812 });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await studio.getByRole('button', { name: 'Save report settings', exact: true }).click();
    await expect(studio).toContainText('Static report · 1 captured rows');
    await expect(studio).toContainText('Automatic removal: 2026-12-14');
    await expect(studio.getByRole('button', { name: 'Edit report in Studio', exact: true })).toHaveCount(0);
    await expect(studio.getByRole('button', { name: 'Create a new report from this definition', exact: true })).toBeVisible();
    await expect(studio).toContainText('Fictional-S-1');
    await expect(studio).toContainText('Saved static results · page 1.');
    const save = f.calls.find(c => c.path.endsWith('/settings/') && c.data);
    expect(save.data).toEqual({ operation_id: id(81), version: 0, mode: 'static', expires_after_days: '30' });
    expect(save.csrf).toBe('fictional-test-token');
    await studio.getByRole('button', { name: 'Report settings', exact: true }).click();
    await expect(studio.getByRole('radio', { name: 'Dynamic report', exact: true })).toBeDisabled();
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('an interrupted settings save retries the original operation and dynamic reports may also expire', async ({ page }) => {
    const f = await fixture(page, { fail: 500 });
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('button', { name: 'Report settings', exact: true }).click();
    await studio.getByRole('checkbox', { name: /temporary \/ one-off report/ }).check();
    await studio.getByLabel('Remove after this many days', { exact: true }).fill('7');
    await studio.getByRole('button', { name: 'Save report settings', exact: true }).click();
    await expect(studio).toContainText('No completed change was confirmed.');
    await expect(studio.getByLabel('Remove after this many days', { exact: true })).toBeDisabled();
    await studio.getByRole('button', { name: 'Save report settings', exact: true }).click();
    await expect(studio).toContainText('Dynamic report · results reflect current data.');
    await expect(studio).toContainText('Automatic removal:');
    const saves = f.calls.filter(c => c.path.endsWith('/settings/') && c.data);
    expect(saves).toHaveLength(2);
    expect(saves[0].data).toEqual(saves[1].data);
    expect(saves[0].data).toMatchObject({ mode: 'dynamic', expires_after_days: '7' });
    expect(f.operation).toBe(1);
    expect(f.errors).toEqual([]);
});

test('conflicts preserve the entered settings and shared recipients cannot alter static results', async ({ page }) => {
    const f = await fixture(page, { fail: 409 });
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('button', { name: 'Report settings', exact: true }).click();
    await studio.getByRole('radio', { name: 'Static one-time report', exact: true }).check();
    await studio.getByRole('button', { name: 'Save report settings', exact: true }).click();
    await expect(studio).toContainText('No completed change was confirmed.');
    await expect(studio.getByRole('radio', { name: 'Static one-time report', exact: true })).toBeChecked();
    await expect(studio.getByRole('radio', { name: 'Static one-time report', exact: true })).toBeEnabled();
    f.version = 2;
    await studio.getByRole('button', { name: 'Reload report settings', exact: true }).click();
    await expect(studio.getByRole('radio', { name: 'Dynamic report', exact: true })).toBeChecked();
    await page.getByRole('button', { name: 'Back to reports', exact: true }).click();
    const recipient = await fixture(page, { static: true, recipient: true });
    await expect(studio).toContainText('Static report · 1 captured rows');
    await expect(studio.getByRole('button', { name: 'Report settings', exact: true })).toHaveCount(0);
    await expect(studio.getByRole('button', { name: 'Edit report in Studio', exact: true })).toHaveCount(0);
    await expect(studio).toContainText('Fictional-S-1');
    expect(recipient.calls.filter(c => c.path.endsWith('/settings/'))).toEqual([]);
    expect(f.errors.concat(recipient.errors)).toEqual([]);
});
