const { recordAction, revealRecordAction } = require('./support/workspace-actions.cjs');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
const employee = id(1), person = id(2), entry = id(3);

async function setup(page, self = false, rejectFirstEdit = false) {
    const calls = [], errors = [];
    const dates = { periods: [], version: 0, reject: false };
    const positions = { items: [], position_required: false, timezone: 'America/Los_Angeles', reject: false };
    let selectedPosition = null;
    let active = true, title = 'Fictional librarian', status = 'pending', version = 0;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        const profile = { id: employee, person_id: person, given_name: 'Alex', family_name: 'Example',
            institutional_id: 'S-EXAMPLE', job_title: title, active, is_self: self,
            revision: 'fictional-employment-revision', earlier_history_unknown: true };
        const time = { ...profile, id: entry, employee_id: employee, status, version, position: selectedPosition,
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
        else if (path === `/sis/employees/${employee}/work-positions/`) result = positions;
        else if (path === `/sis/employees/${employee}/employment-dates/`) result = {
            employee_id: employee, periods: dates.periods, version: dates.version,
            new_id: id(200 + dates.periods.length), today: '2026-11-15', earlier_dates_unknown: true,
        };
        else if (path === `/sis/employees/${employee}/employment-dates/review/`) result = {
            position_revision: 'fictional-reviewed-position-revision', positions: data.through ? [
                { id: id(300), code: 'LIB.ASST', title: '<script>Fictional librarian</script>',
                    action: 'Close assignment', through: data.through },
                { id: id(301), code: 'LAB', title: 'Fictional future assignment', action: 'Void assignment', through: null },
            ] : [],
        };
        else if (path === `/sis/employees/${employee}/employment-dates/confirm/`) {
            if (dates.reject) {
                dates.reject = false;
                return route.fulfill({ status: 409, json: { error: 'Fictional positions changed. Review again.' } });
            }
            const period = { id: data.period_id, from: data.from, through: data.through, voided: data.voided };
            const index = dates.periods.findIndex(p => p.id === period.id);
            if (index < 0) dates.periods.push(period); else dates.periods[index] = period;
            dates.version++;
            result = { status: 'saved' };
        }
        else if (path === `/sis/employees/${employee}/edit/`) {
            if (rejectFirstEdit) {
                rejectFirstEdit = false;
                await route.fulfill({ status: 422, json: { errors: [
                    { field: 'reason', message: 'Fictional review needed. Please try again.' },
                ] } });
                return;
            }
            active = data.active; title = data.job_title;
        }
        else if (path === `/sis/employees/${employee}/time-entries/`) result = {
            items: data.after ? [time] : Array.from({ length: 50 }, (_, index) => ({ ...time, id: index ? id(100 + index) : entry })),
            next_after: data.after ? null : id(149),
        };
        else if (path === `/sis/employees/${employee}/time-week/`) {
            const week = !data.week || data.week === '2026-08-12' ? '2026-08-10' : data.week;
            const shifted = days => {
                const date = new Date(`${week}T12:00:00Z`);
                date.setUTCDate(date.getUTCDate() + days);
                return date.toISOString().slice(0, 10);
            };
            const present = week === '2026-08-10';
            result = { week, through: shifted(6), previous: shifted(-7), next: shifted(7),
                timezone: 'America/Los_Angeles', employee: profile,
                totals: Object.fromEntries(['pending', 'approved', 'rejected'].map(key => [key,
                    { entries: present && key === status ? 1 : 0, seconds: present && key === status ? 3600 : 0 }])),
                days: Array.from({ length: 7 }, (_, index) => ({ date: shifted(index),
                    pending_seconds: present && status === 'pending' && index === 1 ? 3600 : 0,
                    approved_seconds: present && status === 'approved' && index === 1 ? 3600 : 0,
                    rejected_seconds: 0 })), items: present ? [{ ...time, week_seconds: 3600 }] : [],
            };
        }
        else if (path === `/sis/timesheets/${entry}/`) result = time;
        else if (path.endsWith('/decision/')) { status = data.approve ? 'approved' : 'rejected'; version++; }
        else if (path === '/sis/timesheets/') {
            if (positions.reject) {
                positions.reject = false;
                return route.fulfill({ status: 409, json: { error: 'Fictional work position changed. Refresh choices.' } });
            }
            const source = positions.items.find(item => item.id === data.position_id);
            selectedPosition = source ? { ...source, accepted_version: data.position_version,
                current_version: data.position_version, voided: false } : null;
            status = 'pending'; version = 0; result = { id: entry, status };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="employees"]').click();
    return { calls, errors, dates, positions };
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
}

test('staff maintain employment and review paged time entries without leaving the connected workspace', async ({ page }) => {
    const { calls, errors } = await setup(page, false, true);
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('S-EXAMPLE');
    await expect(page.locator('#drawer-body')).toContainText('Earlier employment history is unknown.');
    await expect(page.locator('#drawer-body tbody tr')).toHaveCount(50);
    await page.getByRole('button', { name: 'Next time page' }).click();
    await expect(page.locator('#drawer-body tbody tr')).toHaveCount(1);
    await page.getByRole('button', { name: 'Previous time page' }).click();
    await expect(page.locator('#drawer-body tbody tr')).toHaveCount(50);
    await recordAction(page, 'View audit history');
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
    await recordAction(page, 'Edit employment');
    await page.getByLabel('Job title').fill('Fictional archivist');
    await page.getByLabel('Time submissions enabled').uncheck();
    await page.getByRole('button', { name: 'Save employment' }).click();
    expect(calls.filter(call => call.path.endsWith('/edit/'))).toHaveLength(0);
    await page.getByLabel('Reason for employment change').fill('Fictional staffing decision');
    await accessible(page);
    await page.getByRole('button', { name: 'Save employment' }).click();
    await expect(page.getByLabel('Reason for employment change')).toHaveValue('Fictional staffing decision');
    await expect(page.getByLabel('Job title')).toHaveValue('Fictional archivist');
    await expect(page.getByLabel('Time submissions enabled')).not.toBeChecked();
    await expect(page.locator('#drawer-body')).toContainText('Fictional review needed.');
    await page.getByRole('button', { name: 'Save employment' }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional archivist');
    await expect(page.getByRole('button', { name: 'Submit time for this employee' })).toHaveCount(0);
    expect(calls.find(call => call.path.endsWith('/edit/'))?.data).toEqual({
        job_title: 'Fictional archivist', active: false, revision: 'fictional-employment-revision',
        reason: 'Fictional staffing decision',
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

test('weekly review keeps daily totals, empty weeks and approval connected with private accessible controls', async ({ page }) => {
    const { calls, errors } = await setup(page);
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await page.getByRole('button', { name: 'Weekly time review', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('2026-08-10 through 2026-08-16');
    await expect(page.locator('#drawer-body')).toContainText('America/Los_Angeles');
    await expect(page.getByRole('table', { name: 'Daily time totals' }).locator('tbody tr')).toHaveCount(7);
    await accessible(page);
    await page.getByRole('button', { name: 'Next week', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('No time entries overlap this week.');
    await page.getByRole('button', { name: 'Previous week', exact: true }).click();
    await page.getByLabel('Any date in the week').fill('2026-08-12');
    await page.getByRole('button', { name: 'Review week', exact: true }).click();
    await expect(page.getByLabel('Any date in the week')).toHaveValue('2026-08-10');
    await page.getByRole('button', { name: 'Review weekly time entry', exact: true }).click();
    await page.getByRole('button', { name: 'Approve time entry', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm time approval', exact: true }).click();
    await page.getByRole('button', { name: 'Back to weekly time review', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Weekly time entries' })).toContainText('approved');
    await page.route(`**/sis/employees/${employee}/time-week/`, route => route.fulfill({
        status: 409, json: { error: 'Fictional weekly review unavailable.' },
    }));
    await page.getByRole('button', { name: 'Refresh week', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional weekly review unavailable.');
    await expect(page.getByRole('table', { name: 'Weekly time entries' })).toContainText('approved');
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    expect(errors).toEqual([]);
    expect(calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('employment change history shares frozen paging, escaped cards and retained pages after an error', async ({ page }) => {
    const { calls, errors } = await setup(page);
    const reviews = [];
    let head = 22, rejectOlder = true, delay = false, release;
    await page.route(`**/sis/employees/${employee}/history/`, async route => {
        const data = JSON.parse(new URLSearchParams(route.request().postData()).get('_workspace_json'));
        reviews.push({ data, csrf: route.request().headers()['x-csrf-token'] });
        if (data.after && rejectOlder) {
            rejectOlder = false;
            return route.fulfill({ status: 409, json: { error: 'Fictional history temporarily unavailable.' } });
        }
        if (delay) await new Promise(resolve => { release = resolve; });
        const through = data.through_version ?? head;
        const first = through - data.after;
        const sequences = Array.from({ length: Math.min(20, first) }, (_, index) => first - index);
        await route.fulfill({ json: {
            items: sequences.map(sequence => ({ sequence, action: sequence === 1
                ? 'Existing details first recorded' : 'Employment updated',
                fields: sequence === 1 ? [] : ['Job title'], earlier_details_unknown: sequence === 1,
                job_title: '<img src=x onerror=alert(1)> Fictional historical title', active: sequence % 2 === 0,
                recorded_at: '2026-11-15T10:00:00.000000Z', effective_at: '2026-11-15T10:00:00.000000Z',
                recorded_by: '<script>FictionalRecorder</script>' })),
            through_version: through, next_after: first > 20 ? data.after + 20 : null, earlier_history_unknown: true,
        }, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    expect(reviews).toHaveLength(0);
    await (await revealRecordAction(page, 'Employment change history')).focus();
    await page.keyboard.press('Enter');
    const list = page.getByRole('list', { name: 'Recorded employment changes' });
    await expect(list.locator('li')).toHaveCount(20);
    await expect(page.locator('#drawer-body')).toContainText('Showing 20 of 22 recorded changes.');
    await expect(page.locator('#drawer-body')).toContainText('Earlier employment history is unknown.');
    expect(await page.locator('#drawer-body img, #drawer-body script').count()).toBe(0);
    head = 23;
    await page.getByRole('button', { name: 'Load older changes', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional history temporarily unavailable.');
    await expect(list.locator('li')).toHaveCount(20);
    await page.getByRole('button', { name: 'Load older changes', exact: true }).click();
    await expect(list.locator('li')).toHaveCount(22);
    expect(reviews[1].data).toEqual({ after: 20, through_version: 22 });
    expect(reviews[2].data).toEqual(reviews[1].data);
    await page.getByRole('button', { name: 'Refresh history', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Showing 20 of 23 recorded changes.');
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    delay = true;
    await page.getByRole('button', { name: 'Refresh history', exact: true }).click();
    await expect.poll(() => typeof release).toBe('function');
    await page.getByRole('button', { name: 'Back to employment', exact: true }).click();
    release();
    await expect(page.locator('#drawer-body')).toContainText('Recent time entries');
    await expect(list).toHaveCount(0);
    expect(errors).toEqual([]);
    expect([...calls, ...reviews].every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});


test('hire, end and rehire share a reviewed accessible screen with retained drafts and private requests', async ({ page }) => {
    const { calls, errors, dates } = await setup(page);
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await page.getByRole('button', { name: 'Employment dates', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('No hire or end dates have been recorded.');
    await page.getByRole('button', { name: 'Record hire or rehire', exact: true }).click();
    await page.getByLabel(/^Hire \/ employment start/).fill('2026-01-01');
    await page.getByLabel(/^Reason for the employment decision/).fill('Fictional hire decision');
    await accessible(page);
    await page.getByRole('button', { name: 'Review employment decision', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('No position assignments need to change.');
    await page.getByRole('button', { name: 'Confirm employment decision', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Recorded employment periods' })).toContainText('Current');
    await page.getByRole('button', { name: 'End employment', exact: true }).click();
    await page.getByLabel('Last employment day', { exact: true }).fill('2026-11-30');
    await page.getByLabel(/^Reason for the employment decision/).fill('Fictional employment ending');
    await page.getByRole('button', { name: 'Review employment decision', exact: true }).click();
    const changes = page.getByRole('table', { name: 'Position changes to confirm', exact: true });
    await expect(changes).toContainText('Close assignment');
    await expect(changes).toContainText('Void assignment');
    expect(await page.locator('#drawer-body script, #drawer-body img').count()).toBe(0);
    dates.reject = true;
    await page.getByRole('button', { name: 'Confirm employment decision', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional positions changed. Review again.');
    await expect(changes).toBeVisible();
    await page.getByRole('button', { name: 'Back to dates form', exact: true }).click();
    await expect(page.getByLabel('Last employment day', { exact: true })).toHaveValue('2026-11-30');
    await expect(page.getByLabel(/^Reason for the employment decision/)).toHaveValue('Fictional employment ending');
    await page.getByRole('button', { name: 'Review employment decision', exact: true }).focus();
    await page.keyboard.press('Enter');
    await page.getByRole('button', { name: 'Confirm employment decision', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Recorded employment periods' })).toContainText('2026-11-30');
    await page.getByRole('button', { name: 'Record hire or rehire', exact: true }).click();
    await page.getByLabel(/^Hire \/ employment start/).fill('2027-02-01');
    await page.getByLabel(/^Reason for the employment decision/).fill('Fictional rehire decision');
    await page.getByRole('button', { name: 'Review employment decision', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm employment decision', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Recorded employment periods' }).locator('tbody tr')).toHaveCount(2);
    expect(dates.periods).toHaveLength(2);
    expect(calls.filter(call => call.path.endsWith('/employment-dates/confirm/')).map(call => call.data.version)).toEqual([0, 1, 1, 2]);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    expect(errors).toEqual([]);
    expect(calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('employment dates ignore delayed reads after leaving the record panel', async ({ page }) => {
    const { errors } = await setup(page);
    let release;
    await page.route(`**/sis/employees/${employee}/employment-dates/`, async route => {
        await new Promise(resolve => { release = resolve; });
        await route.fulfill({ json: { periods: [], version: 0, new_id: id(200), today: '2026-11-15' } });
    });
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await page.getByRole('button', { name: 'Employment dates', exact: true }).click();
    await expect.poll(() => typeof release).toBe('function');
    await page.getByRole('button', { name: 'Back to employment', exact: true }).click();
    release();
    await expect(page.locator('#drawer-body')).toContainText('Recent time entries');
    await expect(page.getByRole('table', { name: 'Recorded employment periods' })).toHaveCount(0);
    expect(errors).toEqual([]);
});


test('time submission requires a choice across jobs and preserves the draft after a stale assignment', async ({ page }) => {
    const { calls, errors, positions } = await setup(page, true);
    positions.position_required = true;
    positions.items = [
        { id: id(400), code: 'LIB', title: '<script>Fictional library assistant</script>', version: 1 },
        { id: id(401), code: 'LAB', title: 'Fictional lab assistant', version: 2 },
    ];
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await page.getByRole('button', { name: 'Submit time for this employee', exact: true }).click();
    await page.getByLabel(/^Started/).fill('2026-08-11T09:00');
    await page.getByLabel(/^Finished/).fill('2026-08-11T10:00');
    await expect(page.getByLabel('Position worked', { exact: true })).toBeEnabled();
    await page.getByRole('button', { name: 'Submit time entry', exact: true }).click();
    expect(calls.filter(call => call.path === '/sis/timesheets/')).toHaveLength(0);
    await page.getByLabel('Position worked', { exact: true }).selectOption(id(400));
    positions.reject = true;
    await page.getByRole('button', { name: 'Submit time entry', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional work position changed. Refresh choices.');
    await expect(page.getByLabel('Position worked', { exact: true })).toHaveValue(id(400));
    await expect(page.getByLabel(/^Started/)).toHaveValue('2026-08-11T09:00');
    positions.items[0].version = 3;
    await page.getByRole('button', { name: 'Refresh work positions', exact: true }).click();
    await accessible(page);
    await page.getByRole('button', { name: 'Submit time entry', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Position worked: LIB');
    expect(calls.filter(call => call.path === '/sis/timesheets/').at(-1).data).toMatchObject({
        position_id: id(400), position_version: 3,
    });
    expect(await page.locator('#drawer-body img, #drawer-body script').count()).toBe(0);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    expect(errors).toEqual([]);
    expect(calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('one matching job is preselected and delayed choices cannot replace another employee review', async ({ page }) => {
    const { calls, errors, positions } = await setup(page, true);
    positions.position_required = true;
    positions.items = [{ id: id(400), code: 'LIB', title: 'Fictional library assistant', version: 1 }];
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await page.getByRole('button', { name: 'Submit time for this employee', exact: true }).click();
    await page.getByLabel(/^Started/).fill('2026-08-11T09:00');
    await page.getByLabel(/^Finished/).fill('2026-08-11T10:00');
    await expect(page.getByLabel('Position worked', { exact: true })).toHaveValue(id(400));
    await page.getByRole('button', { name: 'Submit time entry', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Position worked: LIB');
    expect(calls.filter(call => call.path === '/sis/timesheets/').at(-1).data).toMatchObject({
        position_id: id(400), position_version: 1,
    });
    await page.getByRole('button', { name: 'Back to employment', exact: true }).click();
    await page.getByRole('button', { name: 'Submit time for this employee', exact: true }).click();
    let release;
    await page.route(`**/sis/employees/${employee}/work-positions/`, async route => {
        await new Promise(resolve => { release = resolve; });
        await route.fulfill({ json: positions });
    });
    await page.getByLabel(/^Started/).fill('2026-08-12T09:00');
    await page.getByLabel(/^Finished/).fill('2026-08-12T10:00');
    await expect.poll(() => typeof release).toBe('function');
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await expect(page.locator('#record-drawer')).not.toBeVisible();
    release();
    await expect(page.getByLabel('Position worked', { exact: true })).toHaveCount(0);
    expect(errors).toEqual([]);
});
