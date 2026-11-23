const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function fixture(page, options = {}) {
    const report = id(1), base = `/sis/report-studio/${report}/`;
    const state = { job: null, calls: [], errors: [], operation: 0, fail: options.fail || false, denied: false };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        state.calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = { items: [{ id: report, name: 'Fictional complete report' }], next_after: null };
        else if (path === '/sis/ui/operation-id/') result = { id: id(80 + ++state.operation) };
        else if (path === `/sis/reports/${report}/`) result = { id: report, relational: true };
        else if (path === base) result = { id: report, name: 'Fictional complete report', version: 3,
            can_manage: true, student_actions: [], lifecycle: { mode: options.static ? 'static' : 'dynamic' },
            definition: { dataset: 'students', columns: [{ field: 'institutional_id', label: 'Student ID', aggregate: 'none' }] } };
        else if (path === `${base}run/`) result = { columns: ['Student ID'], items: [{ c0: 'Fictional S-1' }],
            offset: 0, next_offset: null, limit: 50, grouped: false };
        else if (path === `${base}exports/`) {
            if (data) {
                if (state.fail) {
                    state.fail = false;
                    return route.fulfill({ status: 500, json: { error: 'Unable to confirm the request. Try again.' } });
                }
                state.job = { id: data.operation_id, status: options.ready ? 'ready' : 'queued', rows: options.ready ? 1 : null,
                    expires_at: '2026-12-20T12:00:00+00:00' };
            }
            result = { export: state.job };
        } else if (path.endsWith('/cancel/')) {
            state.job = { ...state.job, status: 'cancelled' };
            result = { export: state.job };
        } else if (path.endsWith('/download/')) {
            if (state.denied) return route.fulfill({ status: 403, json: { error: 'Report access has changed.' } });
            return route.fulfill({ contentType: 'text/csv; charset=UTF-8', body: 'Student ID\nFictional S-1\n',
                headers: { 'Cache-Control': 'private, no-store', 'Content-Disposition': 'attachment; filename="report-results.csv"' } });
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="reports"]').click();
    await page.getByRole('button', { name: 'Fictional complete report', exact: true }).click();
    return state;
}

test('complete exports survive navigation and provide private downloads without browser storage', async ({ page }) => {
    const state = await fixture(page);
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('button', { name: 'Prepare CSV', exact: true }).click();
    await expect(studio).toContainText('Queued.');
    await expect(page.locator('#workspace-status')).toContainText('Export queued.');
    await expect(studio.getByRole('button', { name: 'Prepare CSV', exact: true })).toBeDisabled();
    await expect(studio.getByRole('progressbar', { name: 'Complete export preparation' })).toBeVisible();
    await studio.getByRole('button', { name: 'Back to reports', exact: true }).click();
    state.job.status = 'running';
    await page.getByRole('button', { name: 'Fictional complete report', exact: true }).click();
    await expect(studio).toContainText('Preparing complete results.');
    state.job.status = 'ready'; state.job.rows = 12000;
    await studio.getByRole('button', { name: 'Check status', exact: true }).click();
    await expect(studio).toContainText('12,000 rows');
    await expect(page.locator('#workspace-status')).toContainText('Your report export is ready.');
    await expect(page.locator('#workspace-status')).not.toContainText('Fictional S-1');
    const downloaded = page.waitForEvent('download');
    await studio.getByRole('button', { name: 'Download CSV', exact: true }).click();
    expect((await downloaded).suggestedFilename()).toBe('report-results.csv');
    await studio.getByRole('button', { name: 'Cancel export', exact: true }).click();
    await expect(studio).toContainText('Export cancelled.');
    await expect(page.locator('#workspace-status')).toContainText('Report export cancelled.');
    await expect(studio.getByRole('button', { name: 'Download CSV', exact: true })).toHaveCount(0);
    const mutations = state.calls.filter(call => call.data && /exports\//.test(call.path));
    expect(mutations.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(mutations.find(call => call.path.endsWith('/exports/')).data.version).toBe(3);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(state.errors).toEqual([]);
});

test('interrupted requests retain their operation and the export controls work on narrow screens', async ({ page }) => {
    const state = await fixture(page, { fail: true, static: true });
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('button', { name: 'Prepare CSV', exact: true }).click();
    await expect(studio).toContainText('Unable to confirm the request.');
    await studio.getByRole('button', { name: 'Prepare CSV', exact: true }).click();
    await expect(studio).toContainText('Queued.');
    const requests = state.calls.filter(call => call.path.endsWith('/exports/') && call.data);
    expect(requests).toHaveLength(2);
    expect(requests[0].data).toEqual(requests[1].data);
    expect(state.operation).toBe(1);
    await page.setViewportSize({ width: 375, height: 812 });
    await studio.getByRole('button', { name: 'Cancel export', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(studio).toContainText('Export cancelled.');
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(state.errors).toEqual([]);
});

test('download revocation removes the stale ready action', async ({ page }) => {
    const state = await fixture(page);
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('button', { name: 'Prepare CSV', exact: true }).click();
    await expect(studio).toContainText('Queued.');
    state.job.status = 'ready'; state.job.rows = 1;
    await studio.getByRole('button', { name: 'Check status', exact: true }).click();
    state.denied = true;
    await studio.getByRole('button', { name: 'Download CSV', exact: true }).click();
    await expect(studio).toContainText('This export is no longer available.');
    await expect(studio.getByRole('button', { name: 'Download CSV', exact: true })).toHaveCount(0);
    expect(state.errors).toEqual([]);
});
test('an already completed preparation acknowledgement announces readiness without claiming queued work', async ({ page }) => {
    const state = await fixture(page, { ready: true });
    await page.locator('dialog.report-studio').getByRole('button', { name: 'Prepare CSV', exact: true }).click();
    await expect(page.locator('#workspace-status')).toContainText('Your report export is ready.');
    await expect(page.locator('#workspace-status')).not.toContainText('Export queued.');
    await expect(page.locator('.workspace-notification-toast')).toHaveCount(1);
    expect(state.errors).toEqual([]);
});
