const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction, revealRecordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
const employee = id(1), jobId = id(4), gradeId = id(5);

async function setup(page, basis = 'hourly', path = '/admin/') {
    const state = { positions: [], calls: [], errors: [], reject: true, primary: null, primaryVersion: 0, primaryHistory: [], rejectPrimary: false };
    const job = { id: jobId, code: 'LIB.ASST', title: '<script>Fictional library assistant</script>', currency: 'USD',
        basis, active: true, pay_grade_id: gradeId, rate_minor: basis === 'monthly' ? 450050 : 2500, weekly_minutes: 2250, version: 0 };
    const grade = { id: gradeId, code: 'H.1', title: 'Fictional pay band', currency: 'USD', basis,
        minimum_minor: basis === 'monthly' ? 300000 : 1500,
        maximum_minor: basis === 'monthly' ? 800000 : 5000, active: true, version: 0 };
    const profile = { id: employee, person_id: id(2), given_name: 'Alex', family_name: 'Example',
        institutional_id: 'S-EXAMPLE', job_title: 'Fictional librarian', active: true, is_self: false,
        revision: 'fictional-employment-revision', earlier_history_unknown: true };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) state.calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/employees/') result = { items: [profile], next_after: null };
        else if (path === `/sis/employees/${employee}/`) result = profile;
        else if (path === `/sis/employees/${employee}/primary-position/`) result = {
            selection: state.primary, version: state.primaryVersion, operation_id: id(999),
            status: state.primary ? 'current' : state.primaryVersion ? 'cleared' : 'unrecorded',
        };
        else if (path === `/sis/employees/${employee}/primary-position/review/`) {
            const p = state.positions.find(item => item.id === data.position_id);
            result = { before: state.primary, after: p ? { id: p.id, code: p.job.code, title: p.job.title } : null,
                review_token: 'fictional-actor-bound-primary-proof' };
        }
        else if (path === `/sis/employees/${employee}/primary-position/confirm/`) {
            if (state.rejectPrimary) {
                state.rejectPrimary = false;
                return route.fulfill({ status: 409, json: { error: 'Fictional primary appointment changed. Review again.' } });
            }
            const p = state.positions.find(item => item.id === data.position_id);
            state.primary = p ? { id: p.id, code: p.job.code, title: p.job.title } : null;
            state.primaryVersion++;
            state.primaryHistory.push({ selection: state.primary, version: state.primaryVersion,
                effective_at: '2026-11-15T10:00:00Z', recorded_by: 'Fictional HR recorder', reason: data.reason });
            result = { status: 'saved' };
        }
        else if (path === `/sis/employees/${employee}/primary-position/history/`) result = {
            items: state.primaryHistory, through_version: state.primaryVersion, next_after: null,
        };
        else if (path === '/sis/hr/jobs/page/') result = { items: [job], next_after: null,
            currencies: [{ code: 'USD', digits: 2 }], new_id: id(30) };
        else if (path === `/sis/hr/jobs/${jobId}/`) result = job;
        else if (path === `/sis/hr/pay-grades/${gradeId}/`) result = grade;
        else if (path === `/sis/employees/${employee}/positions/page/`) result = {
            items: state.positions.map(p => ({ ...p, job_code: p.job.code, title: p.job.title,
                currency: p.job.currency, basis: p.job.basis })), next_after: null,
            new_id: id(100 + state.positions.length), today: '2026-11-15',
        };
        else if (path === `/sis/employees/${employee}/positions/assign/`) {
            if (state.reject) {
                state.reject = false;
                return route.fulfill({ status: 409, json: { error: 'Fictional agreement needs another review.' } });
            }
            if (data.replaces_id) {
                const old = state.positions.find(p => p.id === data.replaces_id);
                const end = new Date(`${data.valid_from}T12:00:00Z`);
                end.setUTCDate(end.getUTCDate() - 1);
                old.valid_through = end.toISOString().slice(0, 10); old.version++;
            }
            const { reason, ...terms } = data;
            state.positions.push({ ...terms, job: { ...job }, grade: { ...grade }, employee_id: employee,
                version: 1, currency_digits: 2, voided: false,
                recorded_at: '2026-11-15T10:00:00Z', recorded_by: 'Fictional HR recorder' });
            result = { id: data.id };
        } else {
            const match = path.match(/\/positions\/([A-Z0-9]{26})\/(end\/|void\/)?$/);
            if (match) {
                const position = state.positions.find(p => p.id === match[1]);
                if (match[2]) {
                    if (match[2] === 'end/') position.valid_through = data.valid_through;
                    else position.voided = data.voided;
                    position.version++; result = { status: 'saved' };
                } else result = position;
            }
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(path);
    await page.locator('.workspace-nav-link[data-workspace="employees"]').click();
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await page.getByRole('button', { name: 'Position assignments', exact: true }).click();
    return state;
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
}
async function draft(page, from = '2026-09-01') {
    await page.getByLabel('Job definition', { exact: true }).selectOption(jobId);
    await expect(page.getByLabel('Agreed rate', { exact: true })).toHaveValue('25.00');
    await page.getByLabel('From date', { exact: true }).fill(from);
    await page.getByLabel('Agreed rate', { exact: true }).fill('27.50');
    await page.getByLabel('Reason for this agreement', { exact: true }).fill('Fictional reviewed HR decision');
}

test('monthly position agreements retain and display monthly amounts throughout review and history', async ({ page }) => {
    const state = await setup(page, 'monthly');
    state.reject = false;
    await page.getByRole('button', { name: 'Record position assignment', exact: true }).click();
    await page.getByLabel('Job definition', { exact: true }).selectOption(jobId);
    await expect(page.getByLabel('Agreed rate', { exact: true })).toHaveValue('4500.50');
    await expect(page.locator('#drawer-body')).toContainText('Per month');
    await page.getByLabel('From date', { exact: true }).fill('2026-09-01');
    await page.getByLabel('Agreed rate', { exact: true }).fill('4600.25');
    await page.getByLabel('Reason for this agreement', { exact: true }).fill('Fictional monthly agreement');
    await page.getByRole('button', { name: 'Review assignment', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('per month');
    await page.getByRole('button', { name: 'Confirm assignment', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('per month');
    expect(state.positions[0]).toMatchObject({ rate_minor: 460025, weekly_minutes: 2250,
        job: { basis: 'monthly' }, grade: { basis: 'monthly' } });
    await accessible(page);
    expect(state.errors).toEqual([]);
    expect(state.calls.filter(call => call.path.endsWith('/assign/'))[0].data.rate_minor).toBe(460025);
});

test('recording, rejected confirmation and later term changes share one private accessible workflow', async ({ page }) => {
    const state = await setup(page);
    await expect(page.locator('#drawer-body')).toContainText('Earlier positions remain unknown.');
    await page.getByRole('button', { name: 'Record position assignment', exact: true }).click();
    await draft(page);
    await accessible(page);
    await page.getByRole('button', { name: 'Review assignment', exact: true }).focus();
    await page.keyboard.press('Enter');
    await page.getByRole('button', { name: 'Confirm assignment', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional agreement needs another review.');
    expect(state.positions).toHaveLength(0);
    await page.getByRole('button', { name: 'Back to assignment form', exact: true }).click();
    await expect(page.getByLabel('Agreed rate', { exact: true })).toHaveValue('27.50');
    await expect(page.getByLabel('Reason for this agreement', { exact: true })).toHaveValue('Fictional reviewed HR decision');
    await page.getByRole('button', { name: 'Review assignment', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm assignment', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional HR recorder');
    expect(await page.locator('#drawer-body script, #drawer-body img').count()).toBe(0);
    await page.getByRole('button', { name: 'Change position terms', exact: true }).click();
    await page.getByLabel('Job definition', { exact: true }).selectOption(jobId);
    await expect(page.getByLabel('Agreed rate', { exact: true })).toHaveValue('27.50');
    await page.getByLabel('Change effective on', { exact: true }).fill('2027-01-01');
    await page.getByLabel('Agreed rate', { exact: true }).fill('30');
    await page.getByLabel('Reason for this agreement', { exact: true }).fill('Fictional revised agreement');
    await page.getByRole('button', { name: 'Review assignment', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm assignment', exact: true }).click();
    await expect(await revealRecordAction(page, 'Open previous assignment')).toBeVisible();
    await page.keyboard.press('Escape');
    expect(state.positions).toHaveLength(2);
    expect(state.positions[0].valid_through).toBe('2026-12-31');
    expect(state.calls.filter(call => call.path.endsWith('/assign/')).at(-1).data).toMatchObject({
        replaces_id: state.positions[0].id, replaced_version: 1, rate_minor: 3000, weekly_minutes: 2250,
        job_version: 0, grade_version: 0, valid_from: '2027-01-01',
    });
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    expect(state.errors).toEqual([]);
    expect(state.calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('end dates and voids retain version checks while delayed navigation cannot replace the current screen', async ({ page }) => {
    const state = await setup(page);
    state.reject = false;
    await page.getByRole('button', { name: 'Record position assignment', exact: true }).click();
    await draft(page);
    await page.getByRole('button', { name: 'Review assignment', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm assignment', exact: true }).click();
    await recordAction(page, 'Change end date');
    await page.getByLabel('Through date', { exact: true }).fill('2027-06-30');
    await page.getByRole('textbox', { name: 'Reason', exact: true }).fill('Fictional scheduled closure');
    await page.getByRole('button', { name: 'Save assignment end date', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('2027-06-30');
    await recordAction(page, 'Change end date');
    await page.getByLabel('Through date', { exact: true }).fill('');
    await page.getByRole('textbox', { name: 'Reason', exact: true }).fill('Fictional canceled closure');
    await page.getByRole('button', { name: 'Save assignment end date', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Open ended');
    await recordAction(page, 'Void assignment');
    await page.getByRole('textbox', { name: 'Reason', exact: true }).fill('Fictional correction');
    await page.getByRole('button', { name: 'Confirm assignment void', exact: true }).click();
    await recordAction(page, 'Restore assignment');
    await page.getByRole('textbox', { name: 'Reason', exact: true }).fill('Fictional validation');
    await page.getByRole('button', { name: 'Confirm assignment restoration', exact: true }).click();
    await expect(await revealRecordAction(page, 'Void assignment')).toBeVisible();
    await page.keyboard.press('Escape');
    expect(state.calls.filter(call => /\/(end|void)\/$/.test(call.path)).map(call => call.data.version)).toEqual([1, 2, 3, 4]);
    let release;
    await page.route(`**/sis/employees/${employee}/positions/page/`, async route => {
        await new Promise(resolve => { release = resolve; });
        await route.fulfill({ json: { items: [], next_after: null, new_id: id(105), today: '2026-11-15' } });
    });
    await page.getByRole('button', { name: 'Change position terms', exact: true }).click();
    await expect.poll(() => typeof release).toBe('function');
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await expect(page.locator('#record-drawer')).not.toBeVisible();
    release();
    await expect(page.getByLabel('Job definition', { exact: true })).toHaveCount(0);
    expect(state.errors).toEqual([]);
});


test('primary designation supports review, revision, stale retry, history and clearing at narrow width', async ({ page }) => {
    const state = await setup(page);
    state.positions.push({ id: id(100), job: { id: jobId, code: 'LIB.ASST', title: '<script>Fictional librarian</script>', currency: 'USD', basis: 'hourly' },
        grade: null, valid_from: '2026-09-01', valid_through: null, rate_minor: 2500, weekly_minutes: 1200,
        currency_digits: 2, version: 1, voided: false, recorded_at: '2026-11-15T10:00:00Z', recorded_by: 'Fictional HR recorder' });
    await page.getByRole('button', { name: 'Back to employment', exact: true }).click();
    await page.getByRole('button', { name: 'Position assignments', exact: true }).click();
    await page.getByRole('button', { name: 'Open position assignment', exact: true }).click();
    await page.getByRole('button', { name: 'Designate as primary position', exact: true }).click();
    await page.getByLabel('Reason for primary designation').fill('Fictional reviewed main appointment');
    await page.getByRole('button', { name: 'Review primary designation', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Primary designation review', exact: true })).toContainText('LIB.ASST');
    await page.getByRole('button', { name: 'Revise primary designation', exact: true }).click();
    await expect(page.getByLabel('Reason for primary designation')).toHaveValue('Fictional reviewed main appointment');
    await page.getByRole('button', { name: 'Review primary designation', exact: true }).click();
    state.rejectPrimary = true;
    await page.getByRole('button', { name: 'Confirm primary designation', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional primary appointment changed. Review again.');
    await expect(page.locator('#drawer-body')).toContainText('Fictional reviewed main appointment');
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Confirm primary designation', exact: true }).click();
    await page.getByRole('button', { name: 'Primary designation history', exact: true }).click();
    await expect(page.getByRole('list', { name: 'Primary designation decisions' })).toContainText('Fictional reviewed main appointment');
    await accessible(page);
    await page.getByRole('button', { name: 'Back to primary position', exact: true }).click();
    await page.getByRole('button', { name: 'Clear primary designation', exact: true }).click();
    await page.getByLabel('Reason for primary designation').fill('Fictional leave unassigned');
    await page.getByRole('button', { name: 'Review primary designation', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Primary designation review', exact: true })).toContainText('No primary designation');
    await page.getByRole('button', { name: 'Confirm primary designation', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Explicitly cleared');
    expect(state.primaryHistory).toHaveLength(2);
    expect(state.calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(await page.locator('#drawer-body img, #drawer-body script').count()).toBe(0);
    expect(state.errors).toEqual([]);
});


test('HR without the independent primary duty reads context without designation controls', async ({ page }) => {
    const state = await setup(page, 'hourly', '/admin/hr-only-fixture/');
    await page.getByRole('button', { name: 'Primary position', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Not recorded; earlier designations are unknown');
    await expect(page.getByRole('button', { name: 'Choose from position assignments', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Clear primary designation', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Primary designation history', exact: true })).toHaveCount(0);
    await expect(page.getByLabel('Reason for primary designation')).toHaveCount(0);
    expect(state.calls.some(call => /primary-position\/(review|confirm)/.test(call.path))).toBe(false);
    expect(state.errors).toEqual([]);
});
