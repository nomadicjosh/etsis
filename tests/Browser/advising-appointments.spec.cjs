'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction, workspaceTool } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
const student = id(1), person = id(2), pursuit = id(3), program = id(4), term = id(5), host = id(6);
const time = (start = '2026-08-11T10:00', end = '2026-08-11T10:30') => ({ start, end, timezone: 'America/Los_Angeles', starts_at: start + ':00Z', ends_at: end + ':00Z' });
const original = () => ({ id: id(10), status: 'scheduled', version: 0, time: time(), message: 'Fictional <b>meeting</b> instructions.',
    host_name: 'Fictional Advisor', can_act: true, can_reschedule: true, cancel_only: false, new_operation: id(20),
    changes: [{ action: 'schedule', time: time(), message: 'Fictional <b>meeting</b> instructions.', at: '2026-08-10T17:00:00Z' }] });
const availabilityWindow = () => ({ id: id(90), status: 'open', version: 0,
    time: time('2026-08-11T13:00', '2026-08-11T15:00'), slot_minutes: 30, message: 'Fictional office <b>meeting</b> instructions.',
    created_at: '2026-08-10T17:00:00Z', closed_at: null, close_reason: null });
const defaultRules = () => ({ maximum_active: 20, notice_minutes: 0, horizon_days: 366 });
async function fixture(page, channel = 'staff', options = {}) {
    const calls = [], errors = [];
    let windows = options.availabilitySeed ? [availabilityWindow()] : [], bookingRejected = options.staleBooking, closeRejected = options.staleClosure;
    const booked = new Map(), series = new Map();
    let seriesRejected = options.staleSeries, seriesCloseRejected = options.staleSeriesClose;
    let items = options.seed ? [original()] : channel === 'staff' ? [] : [original()], stale = options.stale, deny = false, sequence = 30;
    const policies = [], policyReceipts = new Map(); let lostPolicyReply = options.lostPolicyReply;
    const policy = () => ({ publication_id: policies.at(-1)?.id || null, revision: policies.length, rules: policies.at(-1)?.rules || defaultRules() });
    if (options.policy) items.forEach(i => { i.changes[0].booking_policy = policy(); });
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
        else if (path === '/portal/api/advising-availability/context/') result = { can_manage: channel === 'advisor' };
        else if (path === '/sis/advising-availability/candidates/') result = { items: [{ id: host, given_name: 'Fictional', family_name: 'Advisor' }], next_after: null };
        else if (path.includes('/advising-booking-policy/')) {
            if (deny) return route.fulfill({ status: 403, json: { error: 'Fictional policy access ended.' } });
            if (path.endsWith('/view/')) result = { current: policy(), defaults: defaultRules() };
            else if (path.endsWith('/history/')) {
                const rows = [...policies].reverse().filter(p => data.before == null || p.revision < data.before);
                result = { items: rows.slice(0, 10), next_before: rows.length > 10 ? rows[9].revision : null };
            } else if (path.endsWith('/review/')) result = { id: id(sequence++), version: data.version, before: policy(),
                rules: Object.fromEntries(Object.keys(defaultRules()).map(k => [k, data[k]])), reason: data.reason, review_token: 'fictional-policy-proof' };
            else if (path.endsWith('/save/')) {
                if (!policyReceipts.has(data.id)) {
                    policies.push({ id: data.id, revision: policies.length + 1, rules: Object.fromEntries(Object.keys(defaultRules()).map(k => [k, data[k]])), reason: data.reason, at: '2026-08-10T17:00:00Z' });
                    policyReceipts.set(data.id, { id: data.id, revision: policies.length });
                }
                if (lostPolicyReply) { lostPolicyReply = false; return route.fulfill({ status: 503, json: { error: 'Fictional lost policy reply. Retry the same confirmation.' } }); }
                result = policyReceipts.get(data.id);
            }
        }
        else if (path.includes('/advising-availability/')) {
            if (deny) return route.fulfill({ status: 403, json: { error: 'Fictional availability access ended.' } });
            if (path.endsWith('/series-list/')) result = { items: [...series.values()].map(s => ({ id: s.id, from: s.pattern.from, through: s.pattern.through, count: s.windows.length, status: s.status })), next_after: null, can_create: true };
            else if (path.endsWith('/review-series/')) {
                const pattern = Object.fromEntries(['from', 'through', 'start_time', 'end_time', 'weekdays', 'interval_weeks', 'excluded_dates', 'timezone'].map(key => [key, data[key]]));
                const slots = ['2026-08-11', '2026-08-18', '2026-08-25'].filter(date => !data.excluded_dates.includes(date))
                    .map(date => ({ id: id(sequence++), time: time(date + 'T' + data.start_time, date + 'T' + data.end_time) }));
                result = { pattern, series_id: id(sequence++), window_ids: slots.map(w => w.id), windows: slots,
                    slot_minutes: data.slot_minutes, message: data.message, revision: 'fictional-revision', review_token: 'fictional-review-token' };
            } else if (path.endsWith('/create-series/')) {
                if (seriesRejected) { seriesRejected = false; return route.fulfill({ status: 409, json: { error: 'Fictional recurring offer changed. Review it again.' } }); }
                if (!series.has(data.series_id)) {
                    const dates = ['2026-08-11', '2026-08-18', '2026-08-25'].filter(date => !data.excluded_dates.includes(date));
                    const members = data.window_ids.map((value, i) => ({ ...availabilityWindow(), id: value, series_id: data.series_id,
                        time: time(dates[i] + 'T' + data.start_time, dates[i] + 'T' + data.end_time), message: data.message, slot_minutes: data.slot_minutes }));
                    windows.push(...members);
                    series.set(data.series_id, { id: data.series_id, pattern: Object.fromEntries(['from', 'through', 'start_time', 'end_time', 'weekdays', 'interval_weeks', 'excluded_dates', 'timezone'].map(key => [key, data[key]])),
                        message: data.message, slot_minutes: data.slot_minutes, windows: members, version: 0, status: 'open' });
                }
                result = { id: data.series_id, version: 0, status: 'open', window_ids: data.window_ids };
            } else if (path.endsWith('/series-view/')) {
                const s = series.get(data.series_id);
                result = { series: s, windows: s.windows, remaining_ids: s.status === 'open' ? s.windows.filter(w => w.status === 'open').map(w => w.id) : [] };
            } else if (path.endsWith('/review-series-close/')) {
                const s = series.get(data.series_id), remaining = s.windows.filter(w => w.status === 'open');
                result = { series_id: s.id, version: s.version, window_ids: remaining.map(w => w.id), reason: data.message,
                    windows: remaining, revision: 'fictional-close-revision', review_token: 'fictional-close-token' };
            } else if (path.endsWith('/close-series/')) {
                if (seriesCloseRejected) { seriesCloseRejected = false; return route.fulfill({ status: 409, json: { error: 'Fictional remaining windows changed.' } }); }
                const s = series.get(data.series_id);
                for (const w of s.windows.filter(w => data.window_ids.includes(w.id))) { w.status = 'closed'; w.version = 1; w.close_reason = data.message; }
                s.status = 'closed'; s.version = 1; result = { id: s.id, version: 1, status: 'closed', window_ids: data.window_ids };
            } else if (path.endsWith('/list/')) result = { items: windows.filter(w => data.finished ? w.status === 'closed' : w.status === 'open'),
                next_after: null, host_name: 'Fictional Advisor', can_create: true, new_id: id(sequence++), timezone: 'America/Los_Angeles' };
            else if (path.endsWith('/create/')) {
                const w = { ...availabilityWindow(), id: data.window_id, time: time(data.start, data.end), slot_minutes: data.slot_minutes, message: data.message };
                windows.push(w); result = { id: w.id, version: 0, status: 'open' };
            } else if (path.endsWith('/close/')) {
                if (closeRejected) { closeRejected = false; return route.fulfill({ status: 409, json: { error: 'Fictional availability changed. Refresh it.' } }); }
                const w = windows.find(w => w.id === data.window_id); w.status = 'closed'; w.version = 1; w.close_reason = data.message;
                result = { id: w.id, version: 1, status: 'closed' };
            }
        }
        else if (path.endsWith('/appointments/available-times/')) {
            if (deny) return route.fulfill({ status: 403, json: { error: 'Fictional booking access ended.' } });
            result = { hosts: [{ id: host, name: 'Fictional Advisor' }], today: '2026-08-10', timezone: 'America/Los_Angeles', new_id: id(sequence++), windows: [], slots: [] };
            if (options.policy) { result.booking_policy = policy(); result.capacity_reached = Boolean(options.capacity); }
            if (data.window_id) {
                result.window = windows.find(w => w.id === data.window_id);
                result.slots = ['13:00', '13:30', '14:00', '14:30'].map((hour, i) => time('2026-08-11T' + hour, '2026-08-11T' + ['13:30','14:00','14:30','15:00'][i]));
            } else if (data.date) result.windows = windows.filter(w => w.status === 'open');
        }
        else if (path.endsWith('/appointments/book/')) {
            if (bookingRejected) { bookingRejected = false; return route.fulfill({ status: 409, json: { error: 'Fictional available times changed. Review the source.' } }); }
            if (booked.has(data.appointment_id)) result = booked.get(data.appointment_id);
            else {
                const w = windows.find(w => w.id === data.window_id);
                const item = { ...original(), id: data.appointment_id, time: time(data.start, '2026-08-11T13:30'), message: w.message };
                item.changes[0] = { ...item.changes[0], time: item.time, message: item.message }; items.push(item);
                result = { id: item.id, status: 'scheduled', version: 0 }; booked.set(item.id, result);
            }
        }
        else if (path.endsWith('/standing/')) result = { items: [], as_of: '2026-08-10' };
        else if (path.endsWith('/progress/')) return route.fulfill({ status: 409, json: { error: 'Fictional degree review unavailable.' } });
        else if (path === '/portal/api/timetable/context/') {
            if (options.classFailure) return route.fulfill({ status: 409, json: { error: 'Fictional class timetable failure.' } });
            result = { today: '2026-08-11', timezone: 'America/Los_Angeles', recommended_term: null, terms: [], next_after: null };
        }
        else if (path.endsWith('/appointments/calendar/') || path === '/portal/api/advising-appointments/calendar/') {
            if (deny) return route.fulfill({ status: 403, json: { error: 'Fictional calendar access ended.' } });
            const period = data.view || 'week';
            const from = data.date || '2026-08-10';
            const values = items.filter(i => data.include_cancelled || i.status !== 'cancelled').map(i => ({
                id: i.id, student_id: student, pursuit_id: pursuit, host_name: i.host_name,
                program_code: 'BS.CS', program_title: 'Computing', starts_at: '2026-08-11T17:00:00Z',
                ends_at: '2026-08-11T17:30:00Z', status: i.status,
            }));
            result = { from, through: period === 'month' ? '2026-08-31' : '2026-08-16', view: period,
                previous: '2026-08-03', next: '2026-08-17', timezone: 'America/Los_Angeles',
                include_cancelled: Boolean(data.include_cancelled), items: values,
                days: [{ date: '2026-08-11', items: values.map(i => ({ id: i.id })) }] };
        }
        else if (path.endsWith('/appointments/list/')) {
            if (deny) return route.fulfill({ status: 403, json: { error: 'Fictional access ended.' } });
            result = { items: items.filter(i => data.appointment_id ? i.id === data.appointment_id : data.finished ? i.status !== 'scheduled' : i.status === 'scheduled').map(i => ({ ...i,
                can_reschedule: i.status === 'scheduled' && channel !== 'owner', can_act: i.status === 'scheduled', can_correct: channel === 'staff' && ['held', 'no_show'].includes(i.status), cancel_only: channel === 'owner', new_operation: id(sequence++) })),
                next_after: null, new_id: id(sequence++), can_schedule: !data.appointment_id && channel !== 'owner', can_book: !data.appointment_id && channel === 'owner',
                hosts: channel === 'owner' ? [] : [{ advisor_person_id: host, name: 'Fictional Advisor', starts_on: '2026-01-01', ends_on: null }], timezone: 'America/Los_Angeles' };
            if (options.policy) { result.booking_policy = policy(); result.capacity_reached = Boolean(options.capacity); if (options.capacity) { result.can_schedule = false; result.can_book = false; } }
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
            else item.status = data.action === 'cancel' ? 'cancelled' : data.action.replace(/^correct_/, '');
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

async function openPolicy(page) {
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await workspaceTool(page, 'Appointment booking policy');
    await expect(page.getByLabel('Maximum active appointments per program pursuit', { exact: true })).toHaveValue('20');
}

test('booking policy reviews defaults, preserves drafts and retries the original confirmation', async ({ page }) => {
    const f = await fixture(page, 'staff', { lostPolicyReply: true }); await openPolicy(page);
    await page.getByLabel('Maximum active appointments per program pursuit', { exact: true }).fill('3');
    await page.getByLabel('Minimum advance notice in minutes', { exact: true }).fill('60');
    await page.getByLabel('Reason for this policy change', { exact: true }).fill('Fictional <b>private</b> policy reason.');
    await page.getByRole('button', { name: 'Review booking policy', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Proposed rules');
    await page.getByRole('button', { name: 'Keep editing', exact: true }).click();
    await expect(page.getByLabel('Reason for this policy change', { exact: true })).toHaveValue('Fictional <b>private</b> policy reason.');
    await page.getByRole('button', { name: 'Review booking policy', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm policy change', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional lost policy reply.');
    await page.getByRole('button', { name: 'Confirm policy change', exact: true }).click();
    await expect(page.getByLabel('Maximum active appointments per program pursuit', { exact: true })).toHaveValue('3');
    const saves = f.calls.filter(c => c.path.endsWith('/advising-booking-policy/save/'));
    expect(saves[0].data).toEqual(saves[1].data);
    await page.getByRole('button', { name: 'Booking policy history', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional <b>private</b> policy reason.');
    expect(await page.locator('#drawer-body b').count()).toBe(0);
    await page.getByRole('button', { name: 'Back to booking policy', exact: true }).click();
    await page.getByRole('button', { name: 'Use default values', exact: true }).click();
    await expect(page.getByLabel('Maximum active appointments per program pursuit', { exact: true })).toHaveValue('20');
    expect(f.calls.filter(c => c.path.endsWith('/save/'))).toHaveLength(2);
    expect(f.calls.filter(c => c.path.includes('/advising-booking-policy/')).every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(f.errors).toEqual([]);
});

test('booking policy phone and keyboard controls preserve the draft through history', async ({ page }) => {
    await fixture(page); await openPolicy(page);
    await page.getByLabel('Reason for this policy change', { exact: true }).fill('Fictional saved draft.');
    await page.getByRole('button', { name: 'Booking policy history', exact: true }).click();
    await page.getByRole('button', { name: 'Back to booking policy', exact: true }).click();
    await expect(page.getByLabel('Reason for this policy change', { exact: true })).toHaveValue('Fictional saved draft.');
    await accessible(page);
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    const review = page.getByRole('button', { name: 'Review booking policy', exact: true });
    await review.scrollIntoViewIfNeeded(); await review.focus(); await page.keyboard.press('Enter');
    await expect(page.getByRole('button', { name: 'Confirm policy change', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: '/tmp/etsis-booking-policy-phone.png', fullPage: true });
});

test('booking policy denies detached or changed reviews and clears private details on lost access', async ({ page }) => {
    const f = await fixture(page); await openPolicy(page);
    await page.getByLabel('Reason for this policy change', { exact: true }).fill('Fictional first draft.');
    let release, began;
    const gate = new Promise(resolve => { release = resolve; }), started = new Promise(resolve => { began = resolve; });
    await page.route('**/advising-booking-policy/review/', async route => { began(); await gate; await route.fallback(); });
    await page.getByRole('button', { name: 'Review booking policy', exact: true }).click(); await started;
    await page.getByLabel('Reason for this policy change', { exact: true }).fill('Fictional changed private draft.');
    release(); await expect(page.locator('#drawer-body')).toContainText('Your draft changed while the review loaded.');
    await expect(page.getByRole('button', { name: 'Confirm policy change', exact: true })).toHaveCount(0);
    f.deny(); await page.getByRole('button', { name: 'Refresh current policy', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Booking policy access is unavailable.');
    await expect(page.getByLabel('Reason for this policy change', { exact: true })).toHaveCount(0);
    expect(f.calls.some(c => c.path.endsWith('/save/'))).toBe(false); expect(f.errors).toEqual([]);
});

test('booking policy current and accepted limits appear privately with capacity explanations', async ({ page }) => {
    const f = await fixture(page, 'owner', { policy: true, capacity: true }); await openPortal(page, 'owner');
    await expect(page.locator('#campus-service-content')).toContainText('Current booking limits');
    await expect(page.getByRole('button', { name: 'Book an available time', exact: true })).toHaveCount(0);
    await page.locator('summary').filter({ hasText: 'Appointment changes' }).click();
    await expect(page.locator('#campus-service-content')).toContainText('Booking limits used for this change');
    await accessible(page); expect(f.errors).toEqual([]);
});

test('booking policy capacity refresh removes offered choices while preserving the return path', async ({ page }) => {
    const options = { policy: true, availabilitySeed: true };
    const f = await fixture(page, 'owner', options); await openPortal(page, 'owner');
    await page.getByRole('button', { name: 'Book an available time', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('Current booking limits');
    options.capacity = true;
    await page.getByLabel('Availability date', { exact: true }).fill('2026-08-11');
    await page.getByRole('button', { name: 'Find availability', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('The active-appointment limit has been reached.');
    await expect(page.getByRole('button', { name: 'View available times', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Back to appointments', exact: true }).click();
    expect(f.calls.some(c => c.path.endsWith('/book/'))).toBe(false); expect(f.errors).toEqual([]);
});

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

test('office corrects held attendance while retaining the original outcome and reason', async ({ page }) => {
    const { calls, errors } = await fixture(page, 'staff', { seed: true });
    await openStaff(page);
    await page.getByRole('button', { name: 'Record as held', exact: true }).click();
    await page.getByLabel('Appointment details or reason visible to the student', { exact: true }).fill('Fictional initial held outcome.');
    await page.getByRole('button', { name: 'Save appointment change', exact: true }).click();
    await page.getByRole('button', { name: 'Finished appointments', exact: true }).click();
    await page.getByRole('button', { name: 'Correct attendance to no-show', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('The original outcome stays in Appointment changes.');
    await page.getByLabel('Appointment details or reason visible to the student', { exact: true }).fill('Fictional evidence corrected attendance.');
    await page.getByRole('button', { name: 'Save appointment change', exact: true }).click();
    await page.getByText('Appointment changes', { exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional initial held outcome.');
    await expect(page.locator('#drawer-body')).toContainText('Attendance corrected to no-show');
    await expect(page.locator('#drawer-body')).toContainText('Fictional evidence corrected attendance.');
    expect(calls.filter(c => c.path.endsWith('/appointments/act/')).at(-1).data.action).toBe('correct_no_show');
    expect(errors).toEqual([]);
});

test('appointment calendar opens the exact record and restores its period and keyboard focus', async ({ page }) => {
    const { calls, errors } = await fixture(page, 'staff', { seed: true });
    await openStaff(page);
    await page.getByRole('button', { name: 'Appointment calendar', exact: true }).click();
    await page.getByLabel('Appointment calendar view', { exact: true }).selectOption('month');
    await page.getByLabel('Advising date', { exact: true }).fill('2026-08-01');
    await page.getByRole('button', { name: 'Refresh appointment calendar', exact: true }).click();
    const open = page.getByRole('button', { name: 'View appointment · Fictional Advisor', exact: true });
    await open.click();
    expect(calls.filter(c => c.path.endsWith('/appointments/list/')).at(-1).data.appointment_id).toBe(id(10));
    await expect(page.getByRole('button', { name: 'Schedule an appointment', exact: true })).toBeHidden();
    await page.getByRole('button', { name: 'Back to appointment calendar', exact: true }).click();
    await expect(page.getByLabel('Appointment calendar view', { exact: true })).toHaveValue('month');
    await expect(page.getByLabel('Advising date', { exact: true })).toHaveValue('2026-08-01');
    await expect(open).toBeFocused();
    await accessible(page);
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: '/tmp/etsis-appointment-calendar-phone.png' });
    await page.getByRole('button', { name: 'Back to appointment list', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Refresh appointments', exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});

test('student My timetable shows advising without class memberships and supports exact cancellation', async ({ page }) => {
    const { calls, errors } = await fixture(page, 'owner');
    await page.goto('/portal/timetable/');
    await expect(page.locator('#campus-service-content')).toContainText('Your timetable will appear here');
    await page.getByRole('button', { name: 'View appointment · Fictional Advisor', exact: true }).click();
    await page.getByRole('button', { name: 'Cancel appointment', exact: true }).click();
    await page.getByLabel('Appointment details or reason visible to the student', { exact: true }).fill('Fictional timetable cancellation.');
    await page.getByRole('button', { name: 'Save appointment change', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('Fictional timetable cancellation.');
    await page.getByRole('button', { name: 'Back to appointment calendar', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('No advising appointments in this period.');
    await page.getByLabel('Show cancelled appointments', { exact: true }).check();
    await page.getByRole('button', { name: 'Refresh appointment calendar', exact: true }).click();
    await expect(page.getByRole('button', { name: 'View appointment · Fictional Advisor', exact: true })).toBeVisible();
    expect(calls.filter(c => c.path.includes('appointments')).every(c => c.csrf === 'fictional-test-token')).toBe(true);
    await accessible(page);
    expect(errors).toEqual([]);
});

test('class timetable failure leaves the advising calendar available', async ({ page }) => {
    const { errors } = await fixture(page, 'owner', { classFailure: true });
    await page.goto('/portal/timetable/');
    await expect(page.locator('#campus-service-content')).toContainText('The class timetable is unavailable.');
    await expect(page.getByRole('button', { name: 'View appointment · Fictional Advisor', exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});

test('a slow class timetable does not delay rendering the independent advising calendar', async ({ page }) => {
    const { errors } = await fixture(page, 'owner');
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    await page.route('**/timetable/context/', async route => { await gate; await route.fallback(); });
    try {
        await page.goto('/portal/timetable/');
        await expect(page.locator('#campus-service-content')).toContainText('Loading class times…');
        await expect(page.getByRole('button', { name: 'View appointment · Fictional Advisor', exact: true })).toBeVisible();
    } finally { release(); }
    await expect(page.locator('#campus-service-content')).toContainText('Your timetable will appear here');
    expect(errors).toEqual([]);
});

test('calendar access loss clears summaries', async ({ page }) => {
    const f = await fixture(page, 'owner');
    await page.goto('/portal/timetable/');
    await expect(page.getByRole('button', { name: 'View appointment · Fictional Advisor', exact: true })).toBeVisible();
    f.deny();
    await page.getByRole('button', { name: 'Refresh appointment calendar', exact: true }).click();
    await expect(page.locator('#campus-service-content')).not.toContainText('Fictional Advisor');
    await expect(page.locator('#campus-service-content')).toContainText('Appointment access is unavailable.');
});

test('a delayed calendar period cannot replace the student record after Back to programs', async ({ page }) => {
    const f = await fixture(page, 'staff', { seed: true });
    await openStaff(page);
    await page.getByRole('button', { name: 'Appointment calendar', exact: true }).click();
    await expect(page.getByRole('button', { name: 'View appointment · Fictional Advisor', exact: true })).toBeVisible();
    let release, began;
    const gate = new Promise(resolve => { release = resolve; });
    const started = new Promise(resolve => { began = resolve; });
    await page.route('**/appointments/calendar/', async route => { began(); await gate; await route.fallback(); });
    await page.getByRole('button', { name: 'Refresh appointment calendar', exact: true }).click();
    await started;
    await page.getByRole('button', { name: 'Back to programs', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Student programs', exact: true })).toBeVisible();
    const accepted = page.waitForResponse(response => response.url().includes('/appointments/calendar/'));
    release(); await accepted;
    await expect(page.getByRole('region', { name: 'Student programs', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Appointment calendar', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('office offers availability and preserves a stale closure draft through refresh', async ({ page }) => {
    const f = await fixture(page, 'staff', { staleClosure: true });
    await page.goto('/admin/'); await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await workspaceTool(page, 'Advisor availability');
    await page.getByRole('button', { name: 'Manage availability', exact: true }).click();
    await page.getByRole('button', { name: 'Add availability', exact: true }).click();
    await page.getByLabel('Availability starts at', { exact: true }).fill('2026-08-11T13:00');
    await page.getByLabel('Availability ends at', { exact: true }).fill('2026-08-11T15:00');
    await page.getByLabel('Meeting instructions visible to students', { exact: true }).fill('Fictional office <b>meeting</b> instructions.');
    await page.getByRole('button', { name: 'Save availability', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Availability saved.');
    await page.getByText('Close availability', { exact: true }).click();
    await page.getByLabel('Reason for closing availability', { exact: true }).fill('Fictional office closure.');
    await page.getByRole('button', { name: 'Confirm closure', exact: true }).click();
    await page.getByRole('button', { name: 'Refresh availability', exact: true }).click();
    await expect(page.getByLabel('Reason for closing availability', { exact: true })).toHaveValue('Fictional office closure.');
    await page.getByRole('button', { name: 'Confirm closure', exact: true }).click();
    await page.getByRole('button', { name: 'Finished availability', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional office closure.');
    await expect(page.locator('#drawer-body b')).toHaveCount(0);
    await page.getByRole('button', { name: 'Back to advisor search', exact: true }).click();
    await expect(page.getByLabel('Find an advisor', { exact: true })).toBeVisible();
    expect(f.errors).toEqual([]);
});

test('office availability access loss clears drafts and keeps the return to advisor search', async ({ page }) => {
    const f = await fixture(page, 'staff', { availabilitySeed: true });
    await page.goto('/admin/'); await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await workspaceTool(page, 'Advisor availability');
    await page.getByRole('button', { name: 'Manage availability', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional office <b>meeting</b> instructions.');
    await page.getByRole('button', { name: 'Add availability', exact: true }).click();
    await page.getByLabel('Meeting instructions visible to students', { exact: true }).fill('Fictional private draft.');
    f.deny();
    await page.getByRole('button', { name: 'Refresh availability', exact: true }).click();
    await expect(page.locator('#drawer-body')).not.toContainText('Fictional office <b>meeting</b> instructions.');
    await expect(page.getByLabel('Meeting instructions visible to students', { exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Back to advisor search', exact: true }).click();
    await expect(page.getByLabel('Find an advisor', { exact: true })).toBeVisible();
    expect(f.errors).toEqual([]);
});

test('advisor manages personal availability within the campus theme', async ({ page }) => {
    const f = await fixture(page, 'advisor');
    await page.goto('/portal/student/advising/');
    await page.getByRole('button', { name: 'Manage my availability', exact: true }).click();
    await page.getByRole('button', { name: 'Add availability', exact: true }).click();
    await page.getByLabel('Availability starts at', { exact: true }).fill('2026-08-11T13:00');
    await page.getByLabel('Availability ends at', { exact: true }).fill('2026-08-11T15:00');
    await page.getByLabel('Meeting instructions visible to students', { exact: true }).fill('Fictional personal office instructions.');
    await accessible(page);
    await page.getByRole('button', { name: 'Save availability', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('Availability saved.');
    expect(f.calls.some(c => c.path === '/portal/api/advising-availability/create/')).toBe(true);
    expect(f.errors).toEqual([]);
});

async function recurringDraft(page, channel = 'staff') {
    if (channel === 'staff') {
        await page.goto('/admin/'); await page.locator('.workspace-nav-link[data-workspace="students"]').click();
        await workspaceTool(page, 'Advisor availability');
        await page.getByRole('button', { name: 'Manage availability', exact: true }).click();
    } else {
        await page.goto('/portal/student/advising/');
        await page.getByRole('button', { name: 'Manage my availability', exact: true }).click();
    }
    await page.getByRole('button', { name: 'Recurring availability', exact: true }).click();
    await page.getByRole('button', { name: 'Add recurring availability', exact: true }).click();
    await page.getByLabel('First pattern date', { exact: true }).fill('2026-08-11');
    await page.getByLabel('Last pattern date', { exact: true }).fill('2026-08-25');
    await page.getByLabel('Tuesday', { exact: true }).check();
    await page.getByLabel('Meeting instructions visible to students', { exact: true }).fill('Fictional recurring <b>instructions</b>.');
}

test('office reviews recurring windows, keeps the draft and retries the exact confirmed offer', async ({ page }) => {
    const f = await fixture(page, 'staff', { staleSeries: true });
    await recurringDraft(page);
    await page.getByLabel('Skipped availability dates', { exact: true }).fill('2026-08-18');
    await page.getByRole('button', { name: 'Review dated windows', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm recurring offer', exact: true })).toBeVisible();
    await expect(page.locator('#drawer-body .list-group-item')).toHaveCount(2);
    await page.getByRole('button', { name: 'Keep editing', exact: true }).click();
    await expect(page.getByLabel('Skipped availability dates', { exact: true })).toHaveValue('2026-08-18');
    await expect(page.getByLabel('Tuesday', { exact: true })).toBeChecked();
    await page.getByRole('button', { name: 'Review dated windows', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm recurring offer', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional recurring offer changed.');
    await page.getByRole('button', { name: 'Confirm recurring offer', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Recurring availability offered.');
    const commands = f.calls.filter(c => c.path.endsWith('/create-series/'));
    expect(commands).toHaveLength(2); expect(commands[0].data).toEqual(commands[1].data);
    expect(commands.every(c => c.csrf === 'fictional-test-token')).toBe(true);
    await page.getByRole('button', { name: 'Back to recurring offers', exact: true }).click();
    await expect(page.getByRole('button', { name: 'View recurring offer', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Back to availability', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Add availability', exact: true })).toBeVisible();
    expect(f.errors).toEqual([]);
});

test('advisor reviews and closes remaining windows with branded keyboard and phone controls', async ({ page }) => {
    const f = await fixture(page, 'advisor', { staleSeriesClose: true });
    await recurringDraft(page, 'advisor');
    await accessible(page);
    await page.getByRole('button', { name: 'Review dated windows', exact: true }).click();
    await expect(page.locator('#campus-service-content b')).toHaveCount(0);
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: '/tmp/etsis-recurring-availability-phone.png', fullPage: true });
    await page.getByRole('button', { name: 'Confirm recurring offer', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#campus-service-content')).toContainText('Recurring availability offered.');
    await page.getByLabel('Reason for closing availability', { exact: true }).fill('Fictional remaining closure.');
    await page.getByRole('button', { name: 'Review remaining-window closure', exact: true }).click();
    await page.getByRole('button', { name: 'Close listed windows', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('Fictional remaining windows changed.');
    await page.getByRole('button', { name: 'Keep editing', exact: true }).click();
    await expect(page.getByLabel('Reason for closing availability', { exact: true })).toHaveValue('Fictional remaining closure.');
    await page.getByRole('button', { name: 'Review remaining-window closure', exact: true }).click();
    await page.getByRole('button', { name: 'Close listed windows', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('Existing appointments remain unchanged.');
    await expect(page.getByRole('button', { name: 'Review remaining-window closure', exact: true })).toHaveCount(0);
    await accessible(page); expect(f.errors).toEqual([]);
});

test('a changed pattern draft rejects its delayed review', async ({ page }) => {
    const f = await fixture(page, 'staff');
    await recurringDraft(page);
    let release, began;
    const gate = new Promise(resolve => { release = resolve; }), started = new Promise(resolve => { began = resolve; });
    await page.route('**/review-series/', async route => { began(); await gate; await route.fallback(); });
    await page.getByRole('button', { name: 'Review dated windows', exact: true }).click(); await started;
    await page.getByLabel('Meeting instructions visible to students', { exact: true }).fill('Fictional changed draft.');
    release();
    await expect(page.locator('#drawer-body')).toContainText('Your draft changed while the review loaded.');
    await expect(page.getByRole('button', { name: 'Confirm recurring offer', exact: true })).toHaveCount(0);
    expect(f.calls.some(c => c.path.endsWith('/create-series/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('recurring availability access loss removes draft and review details', async ({ page }) => {
    const f = await fixture(page, 'staff');
    await recurringDraft(page);
    await page.getByRole('button', { name: 'Review dated windows', exact: true }).click();
    f.deny();
    await page.getByRole('button', { name: 'Confirm recurring offer', exact: true }).click();
    await expect(page.locator('#drawer-body')).not.toContainText('Fictional recurring <b>instructions</b>.');
    await expect(page.getByRole('button', { name: 'Confirm recurring offer', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Back to advisor search', exact: true }).click();
    await expect(page.getByLabel('Find an advisor', { exact: true })).toBeVisible();
    expect(f.errors).toEqual([]);
});

async function chooseOfferedTime(page) {
    await page.getByRole('button', { name: 'Book an available time', exact: true }).click();
    await page.getByLabel('Availability date', { exact: true }).fill('2026-08-11');
    await page.getByRole('button', { name: 'Find availability', exact: true }).click();
    await page.getByRole('button', { name: 'View available times', exact: true }).click();
    await page.getByRole('button', { name: /Choose this time.*13:00/ }).click();
}

test('student books an offered time with exact retry identity and returns to the confirmed appointment', async ({ page }) => {
    const f = await fixture(page, 'owner', { availabilitySeed: true, staleBooking: true });
    await openPortal(page, 'owner'); await chooseOfferedTime(page);
    await accessible(page);
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    const save = page.getByRole('button', { name: 'Confirm booking', exact: true });
    await save.scrollIntoViewIfNeeded(); await save.focus(); await expect(save).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: '/tmp/etsis-self-booking-phone.png', fullPage: true });
    await save.press('Space');
    await expect(page.locator('#campus-service-content')).toContainText('Fictional available times changed.');
    await save.click();
    await expect(page.locator('#campus-service-content')).toContainText('Your appointment is booked.');
    const requests = f.calls.filter(c => c.path.endsWith('/appointments/book/'));
    expect(requests[0].data).toEqual(requests[1].data);
    expect(requests.every(c => c.csrf === 'fictional-test-token')).toBe(true);
    await page.getByRole('button', { name: 'Back to appointments', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('2026-08-11 13:00');
    expect(f.errors).toEqual([]);
});

test('booking access loss clears private instructions and preserves Back to appointments', async ({ page }) => {
    const f = await fixture(page, 'owner', { availabilitySeed: true });
    await openPortal(page, 'owner');
    await page.getByRole('button', { name: 'Book an available time', exact: true }).click();
    await page.getByLabel('Availability date', { exact: true }).fill('2026-08-11');
    await page.getByRole('button', { name: 'Find availability', exact: true }).click();
    f.deny();
    await page.getByRole('button', { name: 'View available times', exact: true }).click();
    await expect(page.locator('#campus-service-content')).not.toContainText('Fictional office <b>meeting</b> instructions.');
    await expect(page.getByRole('button', { name: 'Back to appointments', exact: true })).toBeVisible();
    expect(f.errors).toEqual([]);
});

test('a changed booking date discards a delayed offered-slot response', async ({ page }) => {
    const f = await fixture(page, 'owner', { availabilitySeed: true });
    await openPortal(page, 'owner');
    await page.getByRole('button', { name: 'Book an available time', exact: true }).click();
    await page.getByLabel('Availability date', { exact: true }).fill('2026-08-11');
    await page.getByRole('button', { name: 'Find availability', exact: true }).click();
    let release, began;
    const gate = new Promise(resolve => { release = resolve; });
    const started = new Promise(resolve => { began = resolve; });
    await page.route('**/appointments/available-times/', async route => { began(); await gate; await route.fallback(); });
    await page.getByRole('button', { name: 'View available times', exact: true }).click(); await started;
    await page.getByLabel('Availability date', { exact: true }).fill('2026-08-12');
    const response = page.waitForResponse(r => r.url().includes('/available-times/')); release(); await response;
    await expect(page.getByRole('button', { name: /Choose this time/ })).toHaveCount(0);
    await expect(page.getByLabel('Availability date', { exact: true })).toHaveValue('2026-08-12');
    expect(f.errors).toEqual([]);
});
