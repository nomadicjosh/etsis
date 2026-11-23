const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
const employee = id(1), base = `/sis/employees/${employee}`;
const body = page => page.locator('#drawer-body');
const control = (page, name) => body(page).getByRole('button', { name, exact: true });
const assignments = page => body(page).getByRole('list', { name: 'Employee position assignments', exact: true });
const decisions = page => body(page).getByRole('list', { name: 'Primary designation decisions', exact: true });
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(row => ({ id: row.id, nodes: row.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await body(page).evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
}
async function fixture(page, { dense = false } = {}) {
    const f = { calls: [], errors: [], fail: null, deny: null, delay: null };
    let release; const pending = new Promise(resolve => { release = resolve; }); f.release = release;
    f.job = { id: id(4), code: 'LIB.ASST', title: 'Fictional library assistant', currency: 'USD', basis: 'monthly', rate_minor: 350050, weekly_minutes: 2250, active: true, version: 2, pay_grade_id: id(5) };
    f.grade = { id: id(5), code: 'PAY.1', title: 'Fictional pay grade', currency: 'USD', basis: 'monthly', minimum_minor: 100000, maximum_minor: 500000, active: true, version: 1 };
    f.positions = Array.from({ length: dense ? 20 : 2 }, (_, n) => ({
        id: id(100 + n), job: { ...f.job, title: dense ? `Fictional ${'LibraryAppointment'.repeat(7)} ${n}` : `Fictional appointment ${n + 1}` }, grade: f.grade,
        employee_id: employee, valid_from: '2026-08-01', valid_through: n === 0 ? '2026-11-01' : null,
        rate_minor: 350050, currency_digits: 2, weekly_minutes: n === 0 ? 0 : 2250, version: 3, voided: false,
        replaces_id: n === 1 ? id(100) : null, recorded_at: '2026-08-01T12:00:00Z', recorded_by: 'Fictional HR recorder',
    }));
    f.history = [1, 2].map(version => ({ version, selection: { code: 'LIB.ASST', title: `Retained appointment ${version}` },
        effective_at: '2026-08-01T12:00:00Z', recorded_at: '2026-08-01T12:00:01Z', recorded_by: 'Fictional HR recorder', reason: `Fictional decision ${version} <script>reference</script>` }));
    const profile = { id: employee, person_id: id(2), given_name: 'Alex', family_name: 'Example', institutional_id: 'P-FICTIONAL',
        job_title: 'Fictional librarian', active: true, is_self: false, revision: 'fictional-profile', earlier_history_unknown: true };
    page.on('pageerror', error => f.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const req = route.request(), path = new URL(req.url()).pathname;
        const data = req.postData() ? JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json')) : {};
        f.calls.push({ path, data, method: req.method(), csrf: req.headers()['x-csrf-token'] });
        if (path === f.deny) return route.fulfill({ status: 403, json: { error: 'Fictional position access denied.' } });
        if (path === f.fail) { f.fail = null; return route.fulfill({ status: 503, json: { error: 'Fictional page unavailable.' } }); }
        const delayed = path === f.delay;
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/employees/') result.items = [profile];
        else if (path === `${base}/`) result = profile;
        else if (path === `${base}/positions/page/`) result = {
            items: (dense ? f.positions : [f.positions[data.after ? 1 : 0]]).map(p => ({ ...p, title: p.job.title, job_code: p.job.code, currency: p.job.currency, basis: p.job.basis })),
            next_after: dense || data.after ? null : id(100), new_id: id(300), today: '2026-11-22',
        };
        else if (path === `${base}/primary-position/`) result = { selection: null, status: 'unrecorded', version: f.history.length, operation_id: id(900) };
        else if (path === `${base}/primary-position/history/`) {
            const through = data.through_version ?? f.history.length;
            result = { items: f.history.slice(data.after, Math.min(data.after + 1, through)), through_version: through, next_after: data.after + 1 < through ? data.after + 1 : null };
        } else if (path === '/sis/hr/jobs/page/') result = { items: [f.job], currencies: [{ code: 'USD', digits: 2 }], next_after: null, new_id: id(301) };
        else if (path === `/sis/hr/jobs/${f.job.id}/`) result = f.job;
        else if (path === `/sis/hr/pay-grades/${f.grade.id}/`) result = f.grade;
        else if (path === `${base}/positions/assign/`) { f.saved = data; result = { id: id(101) }; }
        else if (path.startsWith(`${base}/positions/`)) result = f.positions.find(p => path === `${base}/positions/${p.id}/`) || {};
        if (delayed) await pending;
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/'); await page.locator('.workspace-nav-link[data-workspace="employees"]').click();
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    f.open = () => page.getByRole('button', { name: 'Position assignments', exact: true }).click();
    f.second = async () => { await f.open(); await control(page, 'Next assignments').click(); await expect(assignments(page)).toContainText('Fictional appointment 2'); await control(page, 'Open position assignment').click(); };
    return f;
}

test('assignment edit cancellation and record returns preserve the accepted page without writes', async ({ page }) => {
    const f = await fixture(page); await f.second();
    await recordAction(page, 'Change end date');
    await expect(body(page)).toContainText('Fictional appointment 2');
    await control(page, 'Back to assignment').click();
    await expect(page.getByRole('region', { name: 'Retained position terms', exact: true })).toContainText('Fictional appointment 2');
    await control(page, 'Change position terms').click();
    await page.getByLabel('Job definition', { exact: true }).selectOption(f.job.id);
    await expect(page.getByLabel('Agreed rate', { exact: true })).toHaveValue('3500.50');
    await page.getByLabel('Reason for this agreement', { exact: true }).fill('Fictional unsaved agreement.');
    await page.getByLabel('Search job definitions', { exact: true }).fill('LIB');
    await page.getByLabel('Search job definitions', { exact: true }).press('Enter');
    await expect.poll(() => f.calls.some(call => call.path === '/sis/hr/jobs/page/' && call.data.search === 'LIB')).toBe(true);
    await expect(control(page, 'Confirm assignment')).toHaveCount(0);
    await control(page, 'Back to assignment').click();
    await control(page, 'Back to assignments').click();
    await expect(assignments(page)).toContainText('Fictional appointment 2');
    await expect(control(page, 'Next assignments')).toBeDisabled();
    expect(f.calls.some(call => /\/(assign|end|void|confirm)\/$/.test(call.path))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('previous agreements return to their originating record and primary history retains an unsaved reason', async ({ page }) => {
    const f = await fixture(page); await f.second();
    await recordAction(page, 'Open previous assignment');
    await expect(page.getByRole('region', { name: 'Retained position terms', exact: true })).toContainText('Fictional appointment 1');
    await control(page, 'Back to assignment').click();
    await expect(page.getByRole('region', { name: 'Retained position terms', exact: true })).toContainText('Fictional appointment 2');
    await control(page, 'Designate as primary position').click();
    await page.getByLabel('Reason for primary designation', { exact: true }).fill('Unsaved fictional reason.');
    await control(page, 'Primary designation history').click(); await expect(decisions(page)).toContainText('Fictional decision 1');
    await control(page, 'Back to primary position').click();
    await expect(page.getByLabel('Reason for primary designation', { exact: true })).toHaveValue('Unsaved fictional reason.');
    await control(page, 'Back to assignment').click(); await control(page, 'Back to assignments').click();
    await expect(assignments(page)).toContainText('Fictional appointment 2');
    expect(f.errors).toEqual([]);
});

test('assignment paging retries failed cursors and owns unavailable page controls', async ({ page }) => {
    const f = await fixture(page); await f.open(); await expect(assignments(page)).toBeVisible();
    f.fail = `${base}/positions/page/`; await control(page, 'Next assignments').click();
    await expect(body(page)).toContainText('previous page remains displayed');
    await expect(assignments(page)).toContainText('Fictional appointment 1'); await expect(control(page, 'Previous assignments')).toBeDisabled();
    await control(page, 'Next assignments').click(); await expect(assignments(page)).toContainText('Fictional appointment 2');
    await control(page, 'Refresh assignments').click(); await expect(control(page, 'Next assignments')).toBeDisabled();
    await control(page, 'Previous assignments').click(); await expect(assignments(page)).toContainText('Fictional appointment 1');
    await expect(control(page, 'Previous assignments')).toBeDisabled(); expect(f.errors).toEqual([]);
});

test('designation history preserves its frozen boundary across paging failure until refreshed', async ({ page }) => {
    const f = await fixture(page); await f.open(); await control(page, 'Primary position').click();
    await control(page, 'Primary designation history').click(); await expect(decisions(page)).toContainText('Fictional decision 1');
    f.history.push({ ...f.history[1], version: 3, reason: 'New fictional decision 3' });
    f.fail = `${base}/primary-position/history/`; await control(page, 'Next decisions').click();
    await expect(decisions(page)).toContainText('Fictional decision 1'); await expect(control(page, 'Previous decisions')).toBeDisabled();
    await control(page, 'Next decisions').click(); await expect(decisions(page)).toContainText('Fictional decision 2');
    await expect(control(page, 'Next decisions')).toBeDisabled();
    await control(page, 'Previous decisions').click(); await expect(decisions(page)).not.toContainText('decision 3');
    await control(page, 'Refresh decisions').click(); await expect(body(page)).toContainText('Through decision 3.');
    const reads = f.calls.filter(call => call.path.endsWith('/primary-position/history/')).map(call => call.data);
    expect(reads).toEqual([{ after: 0, through_version: null }, { after: 1, through_version: 2 }, { after: 1, through_version: 2 }, { after: 0, through_version: 2 }, { after: 0, through_version: null }]);
    expect(await decisions(page).locator('script, img').count()).toBe(0); expect(f.errors).toEqual([]);
});

for (const phase of ['assignments', 'history', 'job']) {
    test(`a denied ${phase} read clears affected position controls`, async ({ page }) => {
        const f = await fixture(page); await f.open(); await expect(assignments(page)).toBeVisible();
        if (phase === 'assignments') { f.deny = `${base}/positions/page/`; await control(page, 'Refresh assignments').click(); }
        else if (phase === 'history') { await control(page, 'Primary position').click(); f.deny = `${base}/primary-position/history/`; await control(page, 'Primary designation history').click(); }
        else { await control(page, 'Record position assignment').click(); f.deny = `/sis/hr/jobs/${f.job.id}/`; await page.getByLabel('Job definition', { exact: true }).selectOption(f.job.id); }
        await expect(body(page)).toContainText('Access to this position view is no longer available.');
        await expect(control(page, 'Open position assignment')).toHaveCount(0);
        await expect(control(page, 'Review assignment')).toHaveCount(0);
        await expect(body(page)).not.toContainText('Fictional appointment');
        expect(f.errors).toEqual([]);
    });
}

test('position cards, action menus, grouped forms and reviews reflow with long retained names', async ({ page }) => {
    const f = await fixture(page, { dense: true }); await f.open(); await expect(assignments(page).locator(':scope > li')).toHaveCount(20);
    await accessible(page); await page.screenshot({ path: '/tmp/etsis-positions-desktop.png' });
    await page.setViewportSize({ width: 320, height: 900 }); await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await accessible(page);
    await assignments(page).locator(':scope > li').last().scrollIntoViewIfNeeded(); await expect(assignments(page).locator(':scope > li').last()).toBeInViewport();
    await assignments(page).locator(':scope > li').first().getByRole('button', { name: 'Open position assignment', exact: true }).click();
    await accessible(page);
    const menu = control(page, 'More assignment actions'); await menu.click();
    const popup = page.locator('#' + await menu.getAttribute('aria-controls'));
    await expect(popup.getByRole('button', { name: 'Change end date', exact: true })).toBeVisible();
    expect(await popup.locator('.dropdown-item').evaluateAll(items => items.every(item => getComputedStyle(item).justifyContent !== 'center'))).toBe(true);
    const bounds = await popup.boundingBox(); expect(bounds.x).toBeGreaterThanOrEqual(0); expect(bounds.x + bounds.width).toBeLessThanOrEqual(321);
    await page.keyboard.press('Escape'); await expect(menu).toBeFocused();
    await control(page, 'Change position terms').click(); await page.getByLabel('Job definition', { exact: true }).selectOption(f.job.id);
    await expect(page.getByLabel('Agreed rate', { exact: true })).toHaveValue('3500.50');
    await page.getByLabel('Reason for this agreement', { exact: true }).fill('Fictional revised terms.');
    await accessible(page); await control(page, 'Review assignment').click();
    await accessible(page); await page.screenshot({ path: '/tmp/etsis-position-review-phone.png' });
    await control(page, 'Back to assignment form').click();
    await expect(page.getByLabel('Reason for this agreement', { exact: true })).toHaveValue('Fictional revised terms.');
    await expect(control(page, 'Back to assignment')).toBeVisible();
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0); expect(f.errors).toEqual([]);
});

for (const phase of ['assignment read', 'history read', 'job read', 'accepted assignment save']) {
    test(`closing a pending ${phase} cannot reopen its position drawer`, async ({ page }) => {
        const f = await fixture(page);
        if (phase === 'assignment read') { f.delay = `${base}/positions/page/`; await f.open(); }
        else {
            await f.open(); await expect(assignments(page)).toBeVisible();
            if (phase === 'history read') { await control(page, 'Primary position').click(); f.delay = `${base}/primary-position/history/`; await control(page, 'Primary designation history').click(); }
            else {
                await control(page, 'Record position assignment').click(); f.delay = `/sis/hr/jobs/${f.job.id}/`;
                if (phase === 'accepted assignment save') f.delay = `${base}/positions/assign/`;
                await page.getByLabel('Job definition', { exact: true }).selectOption(f.job.id);
                if (phase === 'accepted assignment save') {
                    await expect(page.getByLabel('Agreed rate', { exact: true })).toHaveValue('3500.50');
                    await page.getByLabel('Reason for this agreement', { exact: true }).fill('Fictional accepted agreement.');
                    await control(page, 'Review assignment').click(); await control(page, 'Confirm assignment').click();
                }
            }
        }
        await expect.poll(() => f.calls.some(call => call.path === f.delay)).toBe(true);
        const gradeReads = f.calls.filter(call => call.path.includes('/sis/hr/pay-grades/')).length;
        await page.getByRole('button', { name: 'Close record', exact: true }).click(); await expect(body(page)).toBeEmpty();
        f.release(); await page.waitForLoadState('networkidle'); await expect(body(page)).toBeEmpty();
        if (phase === 'job read') expect(f.calls.filter(call => call.path.includes('/sis/hr/pay-grades/'))).toHaveLength(gradeReads);
        if (phase === 'accepted assignment save') expect(f.saved.reason).toBe('Fictional accepted agreement.');
        expect(f.errors).toEqual([]);
    });
}
