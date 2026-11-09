const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const fs = require('node:fs/promises');

async function fixture(page, manage = false, run = true) {
    const id = suffix => `01K000000000000000000000${suffix}`;
    const report = id('71'), first = id('72'), second = id('73'), program = id('74'), term = id('75');
    const sent = [], errors = [];
    let revoked = false;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) sent.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {}, status = 200;
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/report-studio/catalog/') result = { datasets: [] };
        else if (path.startsWith('/sis/ui/data/')) {
            const rows = {
                reports: [{ id: report, name: 'Fictional cohort' }], sharedreports: [{ id: report, name: 'Fictional cohort' }],
                programs: [{ id: program, code: 'BS.MATH', title: 'Mathematics' }], terms: [{ id: term, code: '26/FA' }],
            };
            result = { items: rows[path.split('/')[4]] || [], next_after: null };
        } else if (path === `/sis/reports/${report}/`) result = { id: report, name: 'Fictional cohort', active: false,
            program_code: 'BS.MATH', program_title: 'Mathematics', entry_term_code: '26/FA', can_run: run, can_manage: manage };
        else if ((path.endsWith('/run/') || path.endsWith('/export/')) && revoked) {
            status = 403; result = { error: 'Access denied.' };
        } else if (path.endsWith('/run/')) result = { items: [{ student_id: data.after ? second : first,
            institutional_id: data.after ? 'S-LAST' : 'S-FIRST<script>fictional</script>', program_code: 'BS.MATH', entry_term_code: '26/FA', active: false }],
            next_after: data.after ? null : first, limit: 50 };
        else if (path.endsWith('/export/')) {
            await route.fulfill({ body: 'Institutional ID,Primary program,Entry term,Student activity\r\nS-LAST,BS.MATH,26/FA,Inactive\r\n',
                contentType: 'text/csv; charset=UTF-8', headers: { 'Cache-Control': 'private, no-store', 'Content-Disposition': 'attachment; filename="student-report-page.csv"' } });
            return;
        } else if (path === '/sis/reports/') result = { id: report };
        await route.fulfill({ status, json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    return { report, first, second, program, term, sent, errors, revoke: () => { revoked = true; } };
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
}

test('shared reports replace result pages, export only the selected page and clear revoked results', async ({ page }) => {
    const f = await fixture(page);
    await page.goto('/admin/report-fixture/');
    await expect(page.locator('.workspace-nav-link[data-workspace="reports"]')).toHaveCount(0);
    await page.locator('.workspace-nav-link[data-workspace="sharedreports"]').click();
    await page.getByRole('button', { name: 'Fictional cohort', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('BS.MATH · Mathematics');
    await expect(page.locator('#drawer-body')).toContainText('Inactive only');
    await expect(page.getByRole('button', { name: 'Manage report sharing', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Download this results page (CSV)', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Run report', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Student report results', exact: true })).toContainText('S-FIRST<script>fictional</script>');
    await expect(page.locator('#drawer-body script')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Open student academic record', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Next results page', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Student report results', exact: true })).toContainText('S-LAST');
    await expect(page.locator('#drawer-body')).not.toContainText('S-FIRST');
    await expect(page.getByRole('button', { name: 'Next results page', exact: true })).toBeDisabled();
    const downloaded = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Download this results page (CSV)', exact: true }).click();
    const download = await downloaded;
    expect(download.suggestedFilename()).toBe('student-report-page.csv');
    expect(await fs.readFile(await download.path(), 'utf8')).toContain('S-LAST,BS.MATH,26/FA,Inactive');
    await download.delete();
    expect(f.sent.find(item => item.path.endsWith('/export/'))).toMatchObject({ data: { after: f.first }, csrf: 'fictional-test-token' });
    await page.getByRole('button', { name: 'Previous results page', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Student report results', exact: true })).toContainText('S-FIRST');
    await page.getByRole('button', { name: 'Run report', exact: true }).click();
    await expect(page.locator('#drawer-body table')).toHaveCount(1);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    f.revoke();
    await page.getByRole('button', { name: 'Download this results page (CSV)', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('additional access');
    await expect(page.locator('#drawer-body table')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Download this results page (CSV)', exact: true })).toBeDisabled();
    expect(f.sent.filter(item => item.path.endsWith('/run/')).every(item => item.csrf === 'fictional-test-token')).toBe(true);
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('new report selection preserves inactive false rather than treating it as all students', async ({ page }) => {
    const f = await fixture(page, true);
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="reports"]').click();
    await page.getByRole('button', { name: 'Create a report', exact: true }).click();
    await page.getByRole('button', { name: 'Create a simple student list', exact: true }).click();
    await page.getByLabel('Report name').fill('Inactive mathematicians');
    await page.getByLabel('Primary program (optional)', { exact: true }).selectOption(f.program);
    await page.getByLabel('Institutional entry term (optional)', { exact: true }).selectOption(f.term);
    await page.getByLabel('Student activity', { exact: false }).first().selectOption('Inactive only');
    await accessible(page);
    await page.getByRole('button', { name: 'Create saved report', exact: true }).click();
    await expect.poll(() => f.sent.find(item => item.path === '/sis/reports/')?.data).toEqual({ name: 'Inactive mathematicians', program_id: f.program, term_id: f.term, active: false });
    await expect(page.locator('#record-drawer')).not.toBeVisible();
    expect(f.errors).toEqual([]);
});

test('definition managers without execution permission cannot run or export a report', async ({ page }) => {
    const f = await fixture(page, true, false);
    await page.goto('/admin/report-manager-fixture/');
    await page.locator('.workspace-nav-link[data-workspace="reports"]').click();
    await page.getByRole('button', { name: 'Fictional cohort', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('requires the report execution permission');
    await expect(page.getByRole('button', { name: 'Run report', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Download this results page (CSV)', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Manage report sharing', exact: true })).toBeVisible();
    await accessible(page);
    expect(f.sent.some(item => item.path.endsWith('/run/') || item.path.endsWith('/export/'))).toBe(false);
    expect(f.errors).toEqual([]);
});
