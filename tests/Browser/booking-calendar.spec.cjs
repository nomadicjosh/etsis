const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function fixture(page) {
    const state = { calls: [], errors: [], approved: false, release: null, delay: false,
        delayMonth: false, monthOverflow: false, includeOnline: false };
    const library = { id: id(8), code: 'LIB', name: 'Campus library' };
    const science = { id: id(9), code: 'SCI', name: 'Science building' };
    const category = { id: id(7), code: 'COURSE', name: 'Course sections', tone: 'blue', active: true, version: 0 };
    const rooms = [{ id: id(1), code: 'LIB.101', name: 'Seminar room', capacity: 30, building_id: library.id, building: library },
        { id: id(2), code: 'LIB.102', name: '<img> Reading room', capacity: 20, building_id: library.id, building: library }];
    const allItems = () => [...Array.from({ length: 25 }, (_, n) => ({ id: id(200 + n), booking_id: id(100 + n),
        title: `Fictional <script> meeting ${n}`, status: n === 0 && state.approved ? 'approved' : 'requested',
        starts_at: `2026-08-10T09:${String(n).padStart(2, '0')}:00-07:00`, ends_at: `2026-08-10T10:${String(n).padStart(2, '0')}:00-07:00`,
        room_id: rooms[n % 2].id, room_code: rooms[n % 2].code, room_name: rooms[n % 2].name,
        building_id: library.id, building_code: library.code, building_name: library.name })),
        { id: id(299), booking_id: id(199), title: 'Fictional overnight meeting', status: 'approved',
            starts_at: '2026-08-10T23:00:00-07:00', ends_at: '2026-08-11T01:00:00-07:00', room_id: rooms[0].id,
            room_code: rooms[0].code, room_name: rooms[0].name, building_id: library.id, building_code: library.code, building_name: library.name },
        ...(state.includeOnline ? [{ id: id(298), booking_id: id(198), title: 'Fictional online Algebra', status: 'approved',
            starts_at: '2026-08-10T10:00:00-07:00', ends_at: '2026-08-10T11:00:00-07:00', room_id: null,
            room_code: null, room_name: null, building_id: null, building_code: null, building_name: null,
            section_code: 'MATH.101.26FA.A', section_term_code: '26/FA' }] : [])];
    const week = data => {
        const view = data.view || 'week';
        const monday = new Date(`${data.date || '2026-08-10'}T12:00:00Z`);
        if (view === 'month') monday.setUTCDate(1);
        else monday.setUTCDate(monday.getUTCDate() - (monday.getUTCDay() + 6) % 7);
        const start = monday.toISOString().slice(0, 10);
        const length = view === 'month' ? new Date(Date.UTC(monday.getUTCFullYear(), monday.getUTCMonth() + 1, 0)).getUTCDate() : 7;
        const previous = new Date(monday);
        if (view === 'month') previous.setUTCMonth(previous.getUTCMonth() - 1);
        else previous.setUTCDate(previous.getUTCDate() - 7);
        const dates = Array.from({ length: length + 1 }, (_, n) => new Date(monday.getTime() + n * 86400000).toISOString().slice(0, 10));
        const items = (data.building_id === library.id || (!data.building_id && data.location === 'online'))
            && !data.search?.includes('missing')
            ? allItems().filter(item => new Date(item.starts_at) < new Date(`${dates[length]}T00:00:00-07:00`)
                && new Date(item.ends_at) > new Date(`${start}T00:00:00-07:00`)
                && (!data.building_id || item.building_id === data.building_id)
                && (data.location === 'online' ? !item.room_id : data.location === 'room' ? !!item.room_id : true)) : [];
        const days = dates.slice(0, length).map((date, n) => {
            const a = new Date(`${date}T00:00:00-07:00`), b = new Date(`${dates[n + 1]}T00:00:00-07:00`);
            const entries = items.filter(item => new Date(item.starts_at) < b && new Date(item.ends_at) > a);
            return { date, counts: { approved: entries.filter(item => item.status === 'approved').length,
                requested: entries.filter(item => item.status === 'requested').length },
                items: entries.map(item => ({ id: item.id, continues_before: new Date(item.starts_at) < a,
                    continues_after: new Date(item.ends_at) > b })) };
        });
        return { from: start, view, through: dates[length - 1], previous: previous.toISOString().slice(0, 10),
            next: dates[length], today: '2026-08-10', timezone: 'America/Los_Angeles', building: data.building_id === library.id ? library : science,
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
        else if (path === '/sis/ui/data/bookingcategories/') result = { items: [category], next_after: null };
        else if (path === '/sis/calendar/context/') result = { today: '2026-08-10', timezone: 'America/Los_Angeles' };
        else if (path === '/sis/calendar/view/') {
            if ((!data.building_id && data.location !== 'online') || (state.monthOverflow && data.view === 'month'))
                return route.fulfill({ status: 422, json: { errors: [{ field: 'search',
                message: 'More than 500 meetings match. Choose a building or narrow your search for a complete week.' }] } });
            result = week(data);
            if ((state.delay && data.date === '2026-08-17') || (state.delayMonth && data.view === 'month'))
                await new Promise(resolve => { state.release = resolve; });
        } else if (path.startsWith('/sis/bookings/') && path.endsWith('/approve/')) state.approved = true;
        else if ([id(100), id(199), id(198)].some(booking => path === `/sis/bookings/${booking}/`)) {
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
    await expect(page.getByLabel('Calendar date')).toHaveValue('2026-08-10');
    await page.getByRole('combobox', { name: 'Building', exact: true }).selectOption(library.id);
    await page.getByLabel('Search bookings, rooms or sections').fill('Fictional');
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
    await page.getByRole('combobox', { name: 'Category filter', exact: true }).selectOption(id(7));
    await page.getByRole('button', { name: 'View week', exact: true }).click();
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
    await expect(page.getByLabel('Search bookings, rooms or sections')).toHaveValue('Fictional');
    await expect(page.getByRole('combobox', { name: 'Category filter', exact: true })).toHaveValue(id(7));
    expect(state.calls.some(call => call.data?.category_id === id(7))).toBe(true);
    await expect(results).toContainText('2 approved and 24 requested');
    await page.getByRole('button', { name: /^Tuesday, Aug 11/ }).click();
    await expect(results).toContainText('Continues from an earlier day');
    await page.getByRole('button', { name: 'Fictional overnight meeting', exact: true }).click();
    await page.getByRole('button', { name: 'Back to calendar', exact: true }).click();
    await expect(page.getByRole('button', { name: /^Tuesday, Aug 11/ })).toHaveAttribute('aria-pressed', 'true');
    await page.getByLabel('Search bookings, rooms or sections').fill('missing');
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
    await page.getByLabel('Search bookings, rooms or sections').fill('Fictional changed');
    const response = page.waitForResponse(response => response.url().endsWith('/sis/calendar/view/'));
    state.release(); await response;
    await expect(page.getByLabel('Search bookings, rooms or sections')).toHaveValue('Fictional changed');
    await expect(page.locator('#directory-results')).toContainText('Choose View week to load these dates and filters.');
    await expect(page.locator('#directory-results tbody')).toHaveCount(0);
    await page.getByRole('button', { name: 'View week', exact: true }).click();
    await expect(page.locator('#directory-results')).toContainText('2026-08-10 through 2026-08-16');
    state.release = null;
    await page.getByRole('button', { name: 'Next week', exact: true }).click();
    await expect.poll(() => typeof state.release).toBe('function');
    await page.locator('#directory-tabs').getByRole('link', { name: 'Space requests', exact: true }).click();
    const oldResponse = page.waitForResponse(response => response.url().endsWith('/sis/calendar/view/'));
    state.release(); await oldResponse;
    await expect(page.locator('#directory-search-form')).toBeVisible();
    await expect(page.locator('#directory-heading')).toHaveText('Spaces & scheduling');
    await expect(page.getByLabel('Search bookings, rooms or sections')).toHaveCount(0);
    expect(state.errors).toEqual([]);
});

test('month overview keeps filters, selected days and booking actions with keyboard and narrow-screen access', async ({ page }) => {
    const state = await fixture(page);
    const results = page.locator('#directory-results');
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await expect(results).toContainText('2026-08-01 through 2026-08-31');
    await expect(results).toContainText('1 approved and 25 requested occurrences this month.');
    await expect(results.locator('.booking-month [data-date]')).toHaveCount(31);
    await expect(results.locator('.booking-month-weekday')).toHaveCount(7);
    await expect(results.locator('.booking-month-spacer')).toHaveCount(5);
    await expect(results.locator('[data-date="2026-08-10"]')).toHaveAttribute('aria-current', 'date');
    await expect(results.locator('[data-date="2026-08-10"]')).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: 'Next month', exact: true }).click();
    await expect(results).toContainText('2026-09-01 through 2026-09-30');
    await expect(results.locator('.booking-month [data-date]')).toHaveCount(30);
    await expect(results).toContainText('No requested or approved bookings for this day.');
    await page.getByRole('button', { name: 'Previous month', exact: true }).click();
    const tuesday = results.locator('[data-date="2026-08-11"]');
    await tuesday.focus();
    await page.keyboard.press('Enter');
    await expect(results.getByRole('heading', { name: 'Tuesday, Aug 11', exact: true })).toBeFocused();
    await page.getByLabel('Show approved bookings only').check();
    await page.getByRole('button', { name: 'Fictional overnight meeting', exact: true }).click();
    await page.getByRole('button', { name: 'Back to calendar', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Month', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('combobox', { name: 'Building', exact: true })).toHaveValue(id(8));
    await expect(page.getByLabel('Search bookings, rooms or sections')).toHaveValue('Fictional');
    await expect(page.getByLabel('Show approved bookings only')).toBeChecked();
    await expect(tuesday).toHaveAttribute('aria-pressed', 'true');
    for (const width of [1440, 320]) {
        await page.setViewportSize({ width, height: 1000 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    }
    await page.getByRole('button', { name: 'Week', exact: true }).click();
    await expect(results).toContainText('2026-08-10 through 2026-08-16');
    await expect(tuesday).toHaveAttribute('aria-pressed', 'true');
    expect(state.errors).toEqual([]);
    expect(state.calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('switching views ignores a delayed month and recovers from an oversized month without partial counts', async ({ page }) => {
    const state = await fixture(page);
    const results = page.locator('#directory-results');
    state.delayMonth = true;
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await expect.poll(() => typeof state.release).toBe('function');
    await page.getByRole('button', { name: 'Week', exact: true }).click();
    await expect(results).toContainText('2026-08-10 through 2026-08-16');
    const response = page.waitForResponse(response => response.url().endsWith('/sis/calendar/view/'));
    state.release(); await response;
    await expect(results.locator('.booking-month')).toHaveCount(0);
    await expect(page.getByLabel('Calendar date')).toHaveValue('2026-08-10');
    state.delayMonth = false; state.monthOverflow = true;
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await expect(results).toContainText('More than 500 meetings match.');
    await expect(results.locator('[data-date], tbody')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Next month', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Week', exact: true }).click();
    await expect(results).toContainText('1 approved and 25 requested occurrences this week.');
    expect(state.errors).toEqual([]);
});

test('online location filtering shows class context without room requests and survives a connected booking read', async ({ page }) => {
    const state = await fixture(page);
    state.includeOnline = true;
    const results = page.locator('#directory-results');
    await page.getByRole('combobox', { name: 'Meeting location', exact: true }).selectOption('online');
    await page.getByRole('button', { name: 'View week', exact: true }).click();
    await expect(results).toContainText('No requested or approved bookings for this day.');
    await page.getByRole('combobox', { name: 'Building', exact: true }).selectOption('');
    await page.getByRole('button', { name: 'View week', exact: true }).click();
    await expect(results.locator('tbody tr')).toHaveCount(1);
    await expect(results).toContainText('Online · No room');
    await expect(results).toContainText('MATH.101.26FA.A · 26/FA');
    await page.getByRole('button', { name: 'Fictional online Algebra', exact: true }).click();
    await expect(page.getByRole('button', { name: 'View room agenda', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Back to calendar', exact: true }).click();
    await expect(page.getByRole('combobox', { name: 'Meeting location', exact: true })).toHaveValue('online');
    await expect(results.locator('tbody tr')).toHaveCount(1);
    expect(state.calls.some(call => /\/sis\/rooms\/(null|undefined)\//.test(call.path))).toBe(false);
    expect(state.calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(state.errors).toEqual([]);
});
