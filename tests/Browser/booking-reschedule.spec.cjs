const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function fixture(page) {
    const state = { calls: [], errors: [], moved: false, conflict: true, stale: true,
        delay: false, release: null, newTimes: null };
    const source = id(1), originalRoom = id(2), targetRoom = id(3), replacement = id(4), occurrence = id(100);
    const rooms = [{ id: originalRoom, code: 'LIB.101', name: 'Seminar room', capacity: 30 },
        { id: targetRoom, code: 'SCI.201', name: 'Teaching laboratory', capacity: 20 }];
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) state.calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/bookings/') result = { items: [{ id: source,
            title: '<script>Fictional workshop</script>', status: 'approved' }], next_after: null };
        else if (path === '/sis/ui/data/rooms/') result = { items: rooms, next_after: null };
        else if (path === `/sis/rooms/${originalRoom}/`) result = rooms[0];
        else if (path === `/sis/rooms/${targetRoom}/`) result = rooms[1];
        else if (path === `/sis/bookings/${source}/`) result = { id: source, room_id: originalRoom,
            title: '<script>Fictional workshop</script>', status: 'approved', version: state.moved ? 2 : 1,
            source_booking_id: null, occurrences: [
                { id: occurrence, starts_at: '2026-08-10T09:00:00-07:00', ends_at: '2026-08-10T10:00:00-07:00',
                    status: state.moved ? 'cancelled' : 'approved', cancelled_at: state.moved ? '2026-08-01T10:00:00Z' : null,
                    replacement_booking_id: state.moved ? replacement : null },
                { id: id(101), starts_at: '2026-08-11T09:00:00-07:00', ends_at: '2026-08-11T10:00:00-07:00',
                    status: 'approved', cancelled_at: null, replacement_booking_id: null },
            ] };
        else if (path === `/sis/bookings/${replacement}/`) result = { id: replacement, room_id: targetRoom,
            title: '<script>Fictional workshop</script>', status: 'approved', version: 1, source_booking_id: source,
            occurrences: [{ id: id(102), ...state.newTimes, status: 'approved', cancelled_at: null, replacement_booking_id: null }] };
        else if (path.endsWith('/reschedule/review/')) {
            result = { new_id: replacement, revision: 'fictional-review', room: rooms[1], source_room: rooms[0],
                source_starts_at: '2026-08-10T09:00:00-07:00', source_ends_at: '2026-08-10T10:00:00-07:00',
                starts_at: data.starts_at, ends_at: data.ends_at, available: !state.conflict, status: 'approved', version: 1 };
            if (state.delay) await new Promise(resolve => { state.release = resolve; });
        } else if (path.endsWith('/reschedule/confirm/')) {
            if (state.stale) {
                state.stale = false;
                return route.fulfill({ status: 409, json: { error: 'Fictional availability changed. Review again.' } });
            }
            state.moved = true; state.newTimes = { starts_at: data.starts_at, ends_at: data.ends_at };
            result = { id: replacement, status: 'approved', version: 1 };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="bookings"]').click();
    await page.getByRole('button', { name: '<script>Fictional workshop</script>', exact: true }).click();
    await page.getByRole('button', { name: /^Reschedule meeting on / }).first().click();
    await page.getByRole('combobox', { name: 'New room', exact: true }).selectOption(targetRoom);
    await page.getByLabel(/^Starts/).fill('2026-08-12T11:00');
    await page.getByLabel(/^Ends/).fill('2026-08-12T12:00');
    return state;
}

test('reviewed room moves retain the original reservation through conflicts and preserve links after confirmation', async ({ page }) => {
    const state = await fixture(page);
    const body = page.locator('#drawer-body');
    await page.getByRole('button', { name: 'Review new meeting', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm reschedule', exact: true })).toBeDisabled();
    await expect(body).toContainText('The current reservation is unchanged.');
    expect(state.moved).toBe(false);
    await page.getByRole('button', { name: 'Back to new meeting', exact: true }).click();
    await expect(page.getByLabel(/^Starts/)).toHaveValue('2026-08-12T11:00');
    state.conflict = false;
    await page.getByRole('button', { name: 'Review new meeting', exact: true }).click();
    await expect(body).toContainText('SCI.201 · Teaching laboratory');
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    await page.getByRole('button', { name: 'Confirm reschedule', exact: true }).click();
    await expect(body).toContainText('Fictional availability changed. Review again.');
    expect(state.moved).toBe(false);
    await page.getByRole('button', { name: 'Back to new meeting', exact: true }).click();
    await expect(page.getByRole('combobox', { name: 'New room', exact: true })).toHaveValue(id(3));
    await page.getByRole('button', { name: 'Review new meeting', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm reschedule', exact: true }).click();
    await expect(body).toContainText('SCI.201 · Teaching laboratory');
    await expect(body).toContainText('1 active meeting.');
    await page.getByRole('button', { name: 'View previous booking', exact: true }).click();
    await expect(body).toContainText('LIB.101 · Seminar room');
    await expect(body.getByRole('button', { name: /^Reschedule meeting on / })).toHaveCount(1);
    await expect(body.locator('tbody .status-badge').filter({ hasText: /^cancelled$/ })).toHaveCount(1);
    await page.getByRole('button', { name: /^View replacement for / }).click();
    await expect(page.getByRole('button', { name: 'View previous booking', exact: true })).toBeVisible();
    const confirmations = state.calls.filter(call => call.path.endsWith('/reschedule/confirm/'));
    expect(confirmations).toHaveLength(2);
    expect(confirmations[1].data).toMatchObject({ id: id(4), room_id: id(3), version: 1, revision: 'fictional-review' });
    expect(state.calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(state.errors).toEqual([]);
    expect(await body.locator('script').count()).toBe(0);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('edited and detached reschedule reviews cannot replace the active screen', async ({ page }) => {
    const state = await fixture(page);
    state.conflict = false; state.delay = true;
    await page.getByRole('button', { name: 'Review new meeting', exact: true }).click();
    await expect.poll(() => typeof state.release).toBe('function');
    await page.getByLabel(/^Starts/).fill('2026-08-12T11:15');
    const first = page.waitForResponse(response => response.url().endsWith('/reschedule/review/'));
    state.release(); await first;
    await expect(page.getByLabel(/^Starts/)).toHaveValue('2026-08-12T11:15');
    await expect(page.getByRole('heading', { name: 'Review the move', exact: true })).toHaveCount(0);
    state.release = null;
    await page.getByRole('button', { name: 'Review new meeting', exact: true }).click();
    await expect.poll(() => typeof state.release).toBe('function');
    await page.getByRole('button', { name: 'Back to booking', exact: true }).click();
    const second = page.waitForResponse(response => response.url().endsWith('/reschedule/review/'));
    state.release(); await second;
    await expect(page.getByRole('button', { name: 'Cancel space request', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Review the move', exact: true })).toHaveCount(0);
    expect(state.calls.filter(call => call.path.endsWith('/reschedule/confirm/'))).toHaveLength(0);
    expect(state.errors).toEqual([]);
});
