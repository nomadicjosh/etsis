const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function fixture(page) {
    const state = { calls: [], errors: [], approved: false, release: null, delay: false };
    const library = { id: id(8), code: 'LIB', name: 'Campus library' };
    const science = { id: id(9), code: 'SCI', name: 'Science building' };
    const rooms = [{ id: id(1), code: 'LIB.101', name: 'Seminar room', capacity: 30, building_id: library.id, building: library },
        { id: id(2), code: 'LIB.102', name: '<img> Reading room', capacity: 20, building_id: library.id, building: library }];
    const allItems = () => [...Array.from({ length: 25 }, (_, n) => ({ id: id(200 + n), booking_id: id(100 + n),
        title: `Fictional <script> meeting ${n}`, status: n === 0 && state.approved ? 'approved' : 'requested',
        starts_at: `2026-08-10T09:${String(n).padStart(2, '0')}:00-07:00`, ends_at: `2026-08-10T10:${String(n).padStart(2, '0')}:00-07:00`,
        room_id: rooms[n % 2].id, room_code: rooms[n % 2].code, room_name: rooms[n % 2].name,
        building_id: library.id, building_code: library.code, building_name: library.name })),
        { id: id(299), booking_id: id(199), title: 'Fictional overnight meeting', status: 'approved',
            starts_at: '2026-08-10T23:00:00-07:00', ends_at: '2026-08-11T01:00:00-07:00', room_id: rooms[0].id,
            room_code: rooms[0].code, room_name: rooms[0].name, building_id: library.id, building_code: library.code, building_name: library.name }];
    const week = data => {
        const start = data.week || '2026-08-10';
        const monday = new Date(`${start}T12:00:00Z`);
        const dates = Array.from({ length: 8 }, (_, n) => new Date(monday.getTime() + n * 86400000).toISOString().slice(0, 10));
        const items = data.building_id === library.id && !data.search?.includes('missing')
            ? allItems().filter(item => new Date(item.starts_at) < new Date(`${dates[7]}T00:00:00-07:00`)
                && new Date(item.ends_at) > new Date(`${start}T00:00:00-07:00`)) : [];
        const days = dates.slice(0, 7).map((date, n) => {
            const a = new Date(`${date}T00:00:00-07:00`), b = new Date(`${dates[n + 1]}T00:00:00-07:00`);
            const entries = items.filter(item => new Date(item.starts_at) < b && new Date(item.ends_at) > a);
            return { date, counts: { approved: entries.filter(item => item.status === 'approved').length,
                requested: entries.filter(item => item.status === 'requested').length },
                items: entries.map(item => ({ id: item.id, continues_before: new Date(item.starts_at) < a,
                    continues_after: new Date(item.ends_at) > b })) };
        });
        return { week: start, through: dates[6], previous: new Date(monday.getTime() - 7 * 86400000).toISOString().slice(0, 10),
            next: dates[7], today: '2026-08-10', timezone: 'America/Los_Angeles', building: data.building_id === library.id ? library : science,
            totals: { approved: items.filter(item => item.status === 'approved').length,
                requested: items.filter(item => item.status === 'requested').length }, days, items };
    };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        state.calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/buildings/') result = { items: [library, science], next_after: null };
        else if (path === '/sis/calendar/context/') result = { week: '2026-08-10', today: '2026-08-10', timezone: 'America/Los_Angeles' };
        else if (path === '/sis/calendar/week/') {
            if (!data.building_id) return route.fulfill({ status: 422, json: { errors: [{ field: 'search',
                message: 'More than 500 meetings match. Choose a building or narrow your search for a complete week.' }] } });
            result = week(data);
            if (state.delay && data.week === '2026-08-17') await new Promise(resolve => { state.release = resolve; });
        } else if (path.startsWith('/sis/bookings/') && path.endsWith('/approve/')) state.approved = true;
        else if (path === `/sis/bookings/${id(100)}/` || path === `/sis/bookings/${id(199)}/`) {
            const item = allItems().find(item => path === `/sis/bookings/${item.booking_id}/`);
            result = { id: item.booking_id, room_id: item.room_id, title: item.title, status: item.status,
                version: state.approved ? 1 : 0, source_booking_id: null, occurrences: [{ id: item.id,
                    starts_at: item.starts_at, ends_at: item.ends_at, status: item.status, cancelled_at: null, replacement_booking_id: null }] };
        } else if (path === `/sis/rooms/${rooms[0].id}/`) result = rooms[0];
        else if (path === `/sis/rooms/${rooms[1].id}/`) result = rooms[1];
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="bookings"]').click();
    await page.locator('#directory-tabs').getByRole('link', { name: 'Calendar', exact: true }).click();
    await expect(page.locator('#directory-results')).toContainText('More than 500 meetings match.');
    await expect(page.getByLabel('Date in week')).toHaveValue('2026-08-10');
    await page.getByRole('combobox', { name: 'Building', exact: true }).selectOption(library.id);
    await page.getByLabel('Search bookings or rooms').fill('Fictional');
    await page.getByRole('button', { name: 'View week', exact: true }).click();
    await expect(page.locator('#directory-results')).toContainText('1 approved and 25 requested');
    return state;
}

test('campus calendar narrows large weeks, shows rooms and returns from approval with its filters and day intact', async ({ page }) => {
    const state = await fixture(page);
    const results = page.locator('#directory-results');
    await expect(page.locator('#directory-search-form')).toBeHidden();
    await expect(page.locator('#directory-create')).toBeHidden();
    await expect(results.locator('tbody tr')).toHaveCount(20);
    await expect(results).toContainText('LIB.101 · Seminar room');
    await expect(results).toContainText('LIB.102 · <img> Reading room');
    await expect(results.locator('img, script')).toHaveCount(0);
    await page.getByRole('button', { name: 'Next daily page', exact: true }).click();
    await expect(results.locator('tbody tr')).toHaveCount(6);
    await expect(results).toContainText('Continues into the next day');
    await page.getByLabel('Show approved bookings only').check();
    await expect(results.locator('tbody tr')).toHaveCount(1);
    await page.getByLabel('Show approved bookings only').uncheck();
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    await page.getByRole('button', { name: 'Fictional <script> meeting 0', exact: true }).click();
    await page.getByRole('button', { name: 'Approve space request', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm space approval', exact: true }).click();
    await page.getByRole('button', { name: 'Back to calendar', exact: true }).click();
    await expect(page.getByRole('combobox', { name: 'Building', exact: true })).toHaveValue(id(8));
    await expect(page.getByLabel('Search bookings or rooms')).toHaveValue('Fictional');
    await expect(results).toContainText('2 approved and 24 requested');
    await page.getByRole('button', { name: /^Tuesday, Aug 11/ }).click();
    await expect(results).toContainText('Continues from an earlier day');
    await page.getByRole('button', { name: 'Fictional overnight meeting', exact: true }).click();
    await page.getByRole('button', { name: 'Back to calendar', exact: true }).click();
    await expect(page.getByRole('button', { name: /^Tuesday, Aug 11/ })).toHaveAttribute('aria-pressed', 'true');
    await page.getByLabel('Search bookings or rooms').fill('missing');
    await page.getByRole('button', { name: 'View week', exact: true }).click();
    await expect(results).toContainText('No requested or approved bookings for this day.');
    expect(state.calls.filter(call => call.path === '/sis/ui/data/calendar/')).toHaveLength(0);
    expect(state.calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(state.errors).toEqual([]);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('edited and detached campus calendar responses never replace a newer search or workspace', async ({ page }) => {
    const state = await fixture(page);
    state.delay = true;
    await page.getByRole('button', { name: 'Next week', exact: true }).click();
    await expect.poll(() => typeof state.release).toBe('function');
    await page.getByLabel('Search bookings or rooms').fill('Fictional changed');
    const response = page.waitForResponse(response => response.url().endsWith('/sis/calendar/week/'));
    state.release(); await response;
    await expect(page.getByLabel('Search bookings or rooms')).toHaveValue('Fictional changed');
    await expect(page.locator('#directory-results')).toContainText('Choose View week to load these dates and filters.');
    await expect(page.locator('#directory-results tbody')).toHaveCount(0);
    await page.getByRole('button', { name: 'View week', exact: true }).click();
    await expect(page.locator('#directory-results')).toContainText('2026-08-10 through 2026-08-16');
    state.release = null;
    await page.getByRole('button', { name: 'Next week', exact: true }).click();
    await expect.poll(() => typeof state.release).toBe('function');
    await page.locator('#directory-tabs').getByRole('link', { name: 'Space requests', exact: true }).click();
    const oldResponse = page.waitForResponse(response => response.url().endsWith('/sis/calendar/week/'));
    state.release(); await oldResponse;
    await expect(page.locator('#directory-search-form')).toBeVisible();
    await expect(page.locator('#directory-heading')).toHaveText('Spaces & scheduling');
    await expect(page.getByLabel('Search bookings or rooms')).toHaveCount(0);
    expect(state.errors).toEqual([]);
});
