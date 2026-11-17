const { recordAction } = require('./support/workspace-actions.cjs');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

test('staff plan a room, check availability, edit it and approve or cancel a current booking', async ({ page }) => {
    const room = id(1), booking = id(2), calls = [], errors = [];
    let code = 'LIBRARY.101', capacity = 30, status = 'requested', version = 0;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/rooms/') result = { items: [{ id: room, code, capacity }], next_after: null };
        else if (path === '/sis/ui/data/bookings/') result = { items: [{ id: booking, title: 'Fictional seminar', status }], next_after: null };
        else if (path.startsWith('/sis/ui/data/')) result = { items: [], next_after: null };
        else if (path.startsWith('/sis/ui/context/audit/')) result = { items: [
            { action: 'room.updated', recorded_at: '2026-08-11 10:00:00', actor_id: id(9) },
        ], next_after: null };
        else if (path === '/sis/ui/data/buildings/') result = { items: [{ id: id(8), code: 'LIB', name: 'Campus library' }], next_after: null };
        else if (path === `/sis/rooms/${room}/`) result = { id: room, code, name: 'Seminar room', building_id: id(8), building: { id: id(8), code: 'LIB', name: 'Campus library' }, capacity, revision: 'fictional-room-revision' };
        else if (path === `/sis/rooms/${room}/edit/`) { code = data.code; capacity = data.capacity; }
        else if (path === `/sis/rooms/${room}/availability/`) result = { available: false };
        else if (path === `/sis/rooms/${room}/agenda/`) result = { items: [{ id: id(3), booking_id: booking, title: 'Fictional seminar', status,
            starts_at: '2026-08-11T09:00:00+00:00', ends_at: '2026-08-11T10:00:00+00:00' }], next_after: data.after ? null : id(3) };
        else if (path === '/sis/bookings/') result = { id: booking, status };
        else if (path === `/sis/bookings/${booking}/`) result = { id: booking, room_id: room, title: 'Fictional seminar', status, version,
            occurrences: [{ id: id(50), status, starts_at: '2026-08-11T09:00:00+00:00', ends_at: '2026-08-11T10:00:00+00:00' }] };
        else if (path.endsWith('/approve/')) { status = 'approved'; version++; }
        else if (path.endsWith('/cancel/')) { status = 'cancelled'; version++; }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    const accessible = async () => {
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    };
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="bookings"]').click();
    await page.locator('#directory-tabs').getByRole('link', { name: 'Rooms & spaces' }).click();
    await page.getByRole('button', { name: code, exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional seminar');
    await recordAction(page, 'View audit history');
    await expect(page.locator('#drawer-body')).toContainText('room.updated');
    await page.getByRole('button', { name: 'Back to record' }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional seminar');
    await page.getByRole('button', { name: 'Next agenda page' }).click();
    await expect(page.getByRole('button', { name: 'Next agenda page' })).toBeDisabled();
    await page.getByRole('button', { name: 'Previous agenda page' }).click();
    await page.getByRole('button', { name: 'Check availability', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('An approved booking overlaps');
    await accessible();
    await page.getByRole('button', { name: 'Edit room details' }).click();
    await page.getByLabel('Room code').fill('LIBRARY.102');
    await page.getByLabel(/^Capacity/).fill('35');
    await page.getByRole('button', { name: 'Save room details' }).click();
    await expect(page.locator('#drawer-body')).toContainText('Capacity: 35');
    expect(calls.find(item => item.path.endsWith('/edit/'))?.data).toMatchObject({ code: 'LIBRARY.102', capacity: 35, revision: 'fictional-room-revision' });
    await page.getByRole('button', { name: 'Request this room' }).click();
    await page.getByLabel('Meeting purpose').fill('Fictional seminar');
    await page.getByLabel(/^Starts/).fill('2026-08-11T10:00');
    await page.getByLabel(/^Ends/).fill('2026-08-11T09:00');
    const checksBefore = calls.filter(item => item.path.endsWith('/availability/')).length;
    await page.getByRole('button', { name: 'Check availability', exact: true }).click();
    expect(await page.getByLabel(/^Ends/).evaluate(input => input.validationMessage)).toContain('after the start');
    expect(calls.filter(item => item.path.endsWith('/availability/'))).toHaveLength(checksBefore);
    await page.getByLabel(/^Starts/).fill('2026-08-11T09:00');
    await page.getByLabel(/^Ends/).fill('2026-08-11T10:00');
    await page.getByRole('button', { name: 'Check availability', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('An approved booking overlaps');
    await page.getByRole('button', { name: 'Submit meeting request' }).click();
    await expect(page.getByRole('button', { name: 'Approve space request', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Approve space request', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm space approval', exact: true }).click();
    await expect(page.locator('#drawer-body .status-badge').first()).toHaveText('approved');
    expect(calls.find(item => item.path.endsWith('/approve/'))?.data).toEqual({ version: 0 });
    await page.getByRole('button', { name: 'Cancel space request', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm space cancellation', exact: true }).click();
    await expect(page.locator('#drawer-body .status-badge').first()).toHaveText('cancelled');
    expect(calls.find(item => item.path.endsWith('/cancel/'))?.data).toEqual({ version: 1 });
    expect(calls.every(item => item.csrf === 'fictional-test-token')).toBe(true);
    await accessible();
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible();
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

async function recurringFixture(page) {
    const room = id(1), booking = id(2);
    const state = { calls: [], errors: [], saved: false, reject: true, slotCount: 0 };
    const occurrences = [];
    for (let day = new Date('2026-03-02T12:00:00Z'); day <= new Date('2026-08-12T12:00:00Z'); day.setUTCDate(day.getUTCDate() + 1)) {
        const date = day.toISOString().slice(0, 10);
        if (![1, 3].includes(day.getUTCDay()) || date === '2026-03-11') continue;
        const winter = date < '2026-03-08';
        occurrences.push({ starts_at: `${date}T${winter ? '18' : '17'}:00:00Z`,
            ends_at: `${date}T${winter ? '19' : '18'}:00:00Z`,
            local_start: `${date} 10:00 ${winter ? '-08:00' : '-07:00'}`,
            local_end: `${date} 11:00 ${winter ? '-08:00' : '-07:00'}`, available: true });
    }
    occurrences[25].available = false;
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) state.calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/rooms/') result = { items: [{ id: room, code: 'LIB.101', capacity: 30 }], next_after: null };
        else if (path === '/sis/ui/data/bookings/' && state.saved) result = {
            items: [{ id: booking, title: '<script>Fictional seminar</script>', status: 'requested' }], next_after: null,
        };
        else if (path === '/sis/bookings/planning/') result = { new_id: booking, today: '2026-03-02', timezone: 'America/Los_Angeles' };
        else if (path === '/sis/bookings/planning/review/') result = {
            room: { id: room, code: 'LIB.101', capacity: 30 }, items: occurrences, conflicts: 1,
            revision: 'fictional-reviewed-series', timezone: 'America/Los_Angeles',
        };
        else if (path === '/sis/bookings/planning/request/') {
            if (state.reject) {
                state.reject = false;
                return route.fulfill({ status: 409, json: { error: 'Fictional room availability changed. Review again.' } });
            }
            state.saved = true; state.slotCount = occurrences.length;
            result = { id: booking, status: 'requested' };
        }
        else if (path === `/sis/rooms/${room}/`) result = { id: room, code: 'LIB.101', capacity: 30, revision: 'fictional-room' };
        else if (path === `/sis/bookings/${booking}/`) result = { id: booking, room_id: room,
            title: '<script>Fictional seminar</script>', status: 'requested', version: 0, occurrences: occurrences.map((item, i) => ({ ...item, id: id(100 + i), status: 'requested' })) };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="bookings"]').click();
    await page.locator('#directory-create').click();
    await page.getByRole('combobox', { name: 'Room', exact: true }).selectOption(room);
    await page.getByLabel(/^Meeting purpose/).fill('<script>Fictional seminar</script>');
    await page.getByRole('button', { name: 'Plan recurring meetings', exact: true }).click();
    return state;
}

test('recurring room requests review paged meetings, preserve drafts after a stale check and retain explicit choices', async ({ page }) => {
    const state = await recurringFixture(page);
    await expect(page.getByLabel(/^Meeting purpose/)).toHaveValue('<script>Fictional seminar</script>');
    await page.getByLabel('First date', { exact: false }).fill('2026-03-02');
    await page.getByLabel('Last date', { exact: false }).fill('2026-08-12');
    await page.getByLabel(/^Meeting starts/).fill('10:00');
    await page.getByLabel(/^Meeting ends/).fill('11:00');
    await page.getByLabel('Monday', { exact: true }).check();
    await page.getByLabel('Wednesday', { exact: true }).check();
    await page.getByLabel('Dates to skip', { exact: true }).fill('2026-03-11');
    await page.getByRole('button', { name: 'Review meetings', exact: true }).focus();
    await page.keyboard.press('Enter');
    const meetings = page.getByRole('table', { name: 'Meetings to request', exact: true });
    await expect(meetings.locator('tbody tr')).toHaveCount(20);
    await expect(page.locator('#drawer-body')).toContainText('1 conflicts with approved bookings.');
    await page.getByRole('button', { name: 'Show first conflict', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Meetings 21–40');
    await expect(meetings).toContainText('Conflict');
    await page.getByRole('button', { name: 'Submit recurring request', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional room availability changed. Review again.');
    await expect(meetings.locator('tbody tr')).toHaveCount(20);
    await page.getByRole('button', { name: 'Back to meeting pattern', exact: true }).click();
    await expect(page.getByLabel('Last date', { exact: false })).toHaveValue('2026-08-12');
    await expect(page.getByLabel('Wednesday', { exact: true })).toBeChecked();
    await expect(page.getByLabel('Dates to skip', { exact: true })).toHaveValue('2026-03-11');
    await page.getByRole('button', { name: 'Review meetings', exact: true }).click();
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Submit recurring request', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Booking meetings', exact: true }).locator('tbody tr')).toHaveCount(20);
    await expect(page.locator('#drawer-body')).toContainText(`${state.slotCount} active meetings.`);
    await page.getByRole('button', { name: 'Next booking meetings', exact: true }).click();
    await page.getByRole('button', { name: 'Next booking meetings', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Booking meetings', exact: true }).locator('tbody tr')).toHaveCount(state.slotCount - 40);
    const submitted = state.calls.filter(call => call.path.endsWith('/planning/request/'));
    expect(submitted[0].data).toEqual(submitted[1].data);
    expect(submitted[1].data).toMatchObject({ id: id(2), pattern: { weekdays: [1, 3], excluded_dates: ['2026-03-11'],
        interval_weeks: 1, overnight: false, timezone: 'America/Los_Angeles' } });
    expect(await page.locator('#drawer-body script, #drawer-body img').count()).toBe(0);
    expect(state.calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(state.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('recurring patterns need a meeting day and delayed reviews cannot reopen a closed panel', async ({ page }) => {
    const state = await recurringFixture(page);
    await page.getByLabel('Monday', { exact: true }).uncheck();
    await page.getByRole('button', { name: 'Review meetings', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Choose at least one meeting day.' })).toBeVisible();
    expect(state.calls.filter(call => call.path.endsWith('/planning/review/'))).toHaveLength(0);
    await page.getByLabel('Monday', { exact: true }).check();
    let release;
    await page.route('**/sis/bookings/planning/review/', async route => {
        await new Promise(resolve => { release = resolve; });
        await route.fulfill({ json: { room: { id: id(1), code: 'LIB.101' }, items: [], conflicts: 0,
            revision: 'fictional-delayed-review', timezone: 'America/Los_Angeles' } });
    });
    await page.getByRole('button', { name: 'Review meetings', exact: true }).click();
    await expect.poll(() => typeof release).toBe('function');
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await expect(page.locator('#record-drawer')).not.toBeVisible();
    release();
    await expect(page.getByRole('table', { name: 'Meetings to request' })).toHaveCount(0);
    expect(state.errors).toEqual([]);
});
