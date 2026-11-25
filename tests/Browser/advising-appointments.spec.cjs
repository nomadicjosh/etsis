'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
const student = id(1), person = id(2), pursuit = id(3), program = id(4), term = id(5), host = id(6);
const time = (start = '2026-08-11T10:00', end = '2026-08-11T10:30') => ({ start, end, timezone: 'America/Los_Angeles', starts_at: start + ':00Z', ends_at: end + ':00Z' });
const original = () => ({ id: id(10), status: 'scheduled', version: 0, time: time(), message: 'Fictional <b>meeting</b> instructions.',
    host_name: 'Fictional Advisor', can_act: true, can_reschedule: true, cancel_only: false, new_operation: id(20),
    changes: [{ action: 'schedule', time: time(), message: 'Fictional <b>meeting</b> instructions.', at: '2026-08-10T17:00:00Z' }] });
async function fixture(page, channel = 'staff', options = {}) {
    const calls = [], errors = [];
    let items = channel === 'staff' ? [] : [original()], stale = options.stale, deny = false, sequence = 30;
    page.on('pageerror', e => errors.push(e.message));
    await page.route(channel === 'staff' ? '**/sis/**' : '**/portal/api/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/students/') result = { items: [{ id: student, person_id: person, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true }], next_after: null };
        else if ((path === `/sis/students/${student}/` || path === `/sis/students/${student}/record/`)) result = { id: student, person_id: person, given_name: 'Alex', family_name: 'Example', active: true, primary_program: { id: program, code: 'BS.CS', title: 'Computing' }, entry_term: { id: term, code: '26/FA' }, revision: 'student' };
        else if (path.endsWith('/programs/') || path.endsWith('/degree-programs/')) result = { items: [{ id: pursuit, program_id: program, program_code: 'BS.CS', program_title: 'Computing', term_code: '26/FA', status: 'active', is_primary: true }], next_after: null };
        else if (path === '/portal/api/me/') result = { students: channel === 'owner' ? [{ id: student, primary_program_id: program }] : [] };
        else if (path === '/portal/api/advising/') result = { items: [{ student_id: student, pursuit_id: pursuit, name: 'Alex Example', institutional_id: 'S-FICTIONAL', program: { code: 'BS.CS', title: 'Computing' }, entry_term: { code: '26/FA' } }], next_after: null };
        else if (path.endsWith('/standing/')) result = { items: [], as_of: '2026-08-10' };
        else if (path.endsWith('/progress/')) return route.fulfill({ status: 409, json: { error: 'Fictional degree review unavailable.' } });
        else if (path.endsWith('/appointments/list/')) {
            if (deny) return route.fulfill({ status: 403, json: { error: 'Fictional access ended.' } });
            result = { items: items.filter(i => data.finished ? i.status !== 'scheduled' : i.status === 'scheduled').map(i => ({ ...i,
                can_reschedule: channel !== 'owner', cancel_only: channel === 'owner', new_operation: id(sequence++) })),
                next_after: null, new_id: id(sequence++), can_schedule: channel !== 'owner',
                hosts: channel === 'owner' ? [] : [{ advisor_person_id: host, name: 'Fictional Advisor', starts_on: '2026-01-01', ends_on: null }], timezone: 'America/Los_Angeles' };
        } else if (path.endsWith('/appointments/schedule/')) {
            if (options.invalid) { options.invalid = false; return route.fulfill({ status: 422, json: { error: 'Please correct the highlighted fields.', errors: [{ field: 'message', message: 'Fictional details need clarification.' }] } }); }
            const item = { ...original(), id: data.appointment_id, time: time(data.start, data.end), message: data.message };
            item.changes[0] = { ...item.changes[0], time: item.time, message: item.message }; items.push(item);
            result = { id: item.id, version: 0, status: 'scheduled' };
        } else if (path.endsWith('/appointments/act/')) {
            if (stale) { stale = false; return route.fulfill({ status: 409, json: { error: 'Fictional appointment changed. Refresh appointments.' } }); }
            const item = items.find(i => i.id === data.appointment_id);
            item.version++; item.message = data.message;
            if (data.action === 'reschedule') item.time = time(data.start, data.end);
            else item.status = data.action === 'cancel' ? 'cancelled' : data.action;
            item.changes.push({ action: data.action, time: data.action === 'reschedule' ? item.time : null, message: data.message, at: '2026-08-10T18:00:00Z' });
            result = { id: item.id, version: item.version, status: item.status };
        }
        return route.fulfill({ json: result });
    });
    return { calls, errors, deny: () => { deny = true; } };
}
async function openStaff(page) {
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator('#directory-search').fill('Alex'); await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await recordAction(page, 'Advising appointments');
    await expect(page.locator('#record-drawer')).toHaveClass(/\bshow\b/);
}
async function openPortal(page, channel) {
    await page.goto(channel === 'owner' ? '/portal/student/progress/' : '/portal/student/advising/');
    if (channel === 'owner') await page.getByRole('button', { name: /Review progress · BS.CS/ }).click();
    else await page.getByRole('button', { name: 'Program and degree progress', exact: true }).click();
    await page.getByRole('button', { name: 'Advising appointments', exact: true }).click();
}
async function create(page) {
    await page.getByRole('button', { name: 'Schedule an appointment', exact: true }).click();
    await page.getByLabel('Starts at', { exact: true }).fill('2026-08-11T10:00');
    await page.getByLabel('Ends at', { exact: true }).fill('2026-08-11T10:30');
    await page.getByLabel('Appointment details or reason visible to the student', { exact: true }).fill('Fictional <b>meeting</b> instructions.');
    await page.getByRole('button', { name: 'Save appointment', exact: true }).click();
}
async function accessible(page) {
    await page.setViewportSize({ width: 320, height: 812 });
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
}

test('staff schedules, reschedules and reviews original changes with professional phone controls', async ({ page }) => {
    const { calls, errors } = await fixture(page, 'staff', { invalid: true });
    await openStaff(page); await create(page);
    await expect(page.getByLabel('Appointment details or reason visible to the student', { exact: true })).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByLabel('Appointment details or reason visible to the student', { exact: true })).toHaveValue('Fictional <b>meeting</b> instructions.');
    await page.getByRole('button', { name: 'Save appointment', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Appointment saved.');
    await page.getByRole('button', { name: 'Reschedule appointment', exact: true }).click();
    await page.getByLabel('Starts at', { exact: true }).fill('2026-08-12T11:00');
    await page.getByLabel('Ends at', { exact: true }).fill('2026-08-12T12:00');
    await page.getByLabel('Appointment details or reason visible to the student', { exact: true }).fill('Fictional new appointment time.');
    await page.getByRole('button', { name: 'Save appointment change', exact: true }).click();
    await page.getByText('Appointment changes', { exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('2026-08-11 10:00');
    await expect(page.locator('#drawer-body')).toContainText('2026-08-12 11:00');
    await expect(page.locator('#drawer-body b')).toHaveCount(0);
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-advising-appointments-phone.png' });
    await page.getByRole('button', { name: 'Back to programs', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Student programs', exact: true })).toBeVisible();
    expect(calls.filter(c => c.path.includes('/appointments/')).every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(errors).toEqual([]);
});

test('student reads and cancels independently of degree review without scheduling or attendance authority', async ({ page }) => {
    const { calls, errors } = await fixture(page, 'owner');
    await openPortal(page, 'owner');
    await expect(page.getByRole('button', { name: 'Schedule an appointment', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Reschedule appointment', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Record as held', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Cancel appointment', exact: true }).click();
    await page.getByLabel('Appointment details or reason visible to the student', { exact: true }).fill('Fictional student cancellation.');
    await accessible(page);
    await page.getByRole('button', { name: 'Save appointment change', exact: true }).click();
    await page.getByRole('button', { name: 'Finished appointments', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('Fictional student cancellation.');
    expect(calls.find(c => c.path.endsWith('/appointments/act/')).data.action).toBe('cancel');
    expect(errors).toEqual([]);
});

test('advisor records attendance with retained student-visible wording', async ({ page }) => {
    const { calls, errors } = await fixture(page, 'advisor');
    await openPortal(page, 'advisor');
    await page.getByRole('button', { name: 'Record as held', exact: true }).click();
    await page.getByLabel('Appointment details or reason visible to the student', { exact: true }).fill('Fictional appointment held.');
    await page.getByRole('button', { name: 'Save appointment change', exact: true }).click();
    await page.getByRole('button', { name: 'Finished appointments', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('Fictional appointment held.');
    expect(calls.find(c => c.path.endsWith('/appointments/act/')).data.action).toBe('held');
    expect(errors).toEqual([]);
});

test('stale action keeps the draft and refresh provides a fresh explicit revision', async ({ page }) => {
    const { calls } = await fixture(page, 'owner', { stale: true });
    await openPortal(page, 'owner');
    await page.getByRole('button', { name: 'Cancel appointment', exact: true }).click();
    const message = page.getByLabel('Appointment details or reason visible to the student', { exact: true });
    await message.fill('Fictional cancellation draft.');
    await page.getByRole('button', { name: 'Save appointment change', exact: true }).click();
    await expect(message).toHaveValue('Fictional cancellation draft.');
    await page.getByRole('button', { name: 'Refresh appointments', exact: true }).click();
    await expect(page.getByLabel('Appointment details or reason visible to the student', { exact: true })).toHaveValue('Fictional cancellation draft.');
    await page.getByRole('button', { name: 'Save appointment change', exact: true }).click();
    const actions = calls.filter(c => c.path.endsWith('/appointments/act/'));
    expect(actions[0].data.operation_id).not.toBe(actions[1].data.operation_id);
});

test('lost access removes displayed private facts and drafts', async ({ page }) => {
    const f = await fixture(page, 'owner');
    await openPortal(page, 'owner');
    await page.getByRole('button', { name: 'Cancel appointment', exact: true }).click();
    await page.getByLabel('Appointment details or reason visible to the student', { exact: true }).fill('Fictional private draft.');
    f.deny(); await page.getByRole('button', { name: 'Refresh appointments', exact: true }).click();
    await expect(page.locator('#campus-service-content')).not.toContainText('Fictional <b>meeting</b> instructions.');
    await expect(page.getByRole('textbox', { name: 'Appointment details or reason visible to the student', exact: true })).toHaveCount(0);
    await expect(page.locator('#campus-service-content')).toContainText('Appointment access is unavailable.');
});

test('staff time form reflows at 320 pixels and 200 percent text with keyboard controls', async ({ page }) => {
    await fixture(page);
    await openStaff(page);
    await page.getByRole('button', { name: 'Schedule an appointment', exact: true }).click();
    await page.getByLabel('Starts at', { exact: true }).fill('2026-08-11T10:00');
    await page.getByLabel('Ends at', { exact: true }).fill('2026-08-11T10:30');
    await accessible(page);
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    const input = page.getByLabel('Appointment details or reason visible to the student', { exact: true });
    await input.fill('Fictional keyboard appointment.');
    const submit = page.getByRole('button', { name: 'Save appointment', exact: true });
    await submit.scrollIntoViewIfNeeded(); await submit.focus();
    await expect(submit).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await submit.press('Space');
    await expect(page.locator('#drawer-body')).toContainText('Appointment saved.');
});

test('a delayed save cannot replace the student record after Back to programs', async ({ page }) => {
    const f = await fixture(page);
    let release, began;
    const started = new Promise(resolve => { began = resolve; });
    const gate = new Promise(resolve => { release = resolve; });
    await page.route('**/appointments/schedule/', async route => { began(); await gate; await route.fallback(); });
    await openStaff(page); await create(page); await started;
    await page.getByRole('button', { name: 'Back to programs', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Student programs', exact: true })).toBeVisible();
    const accepted = page.waitForResponse(response => response.url().includes('/appointments/schedule/'));
    release(); await accepted;
    await expect(page.getByRole('region', { name: 'Student programs', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Advising appointments', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});
