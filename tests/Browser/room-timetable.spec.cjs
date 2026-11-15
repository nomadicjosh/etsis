const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function fixture(page) {
    const room = id(1), booking = id(2);
    const state = { calls: [], errors: [], approved: false, delayed: null, release: null, overflow: false };
    page.on('pageerror', error => state.errors.push(error.message));
    function week(date) {
        const start = !date || date < '2026-03-09' ? '2026-03-02' : '2026-03-09';
        const items = start !== '2026-03-02' ? [] : [
            { id: id(10), booking_id: id(3), title: 'Fictional overnight meeting', status: 'approved',
                starts_at: '2026-03-01T23:00:00-08:00', ends_at: '2026-03-03T01:00:00-08:00' },
            ...Array.from({ length: 21 }, (_, n) => ({ id: id(20 + n), booking_id: id(4),
                title: `Fictional <img src=x onerror=alert(1)> ${n + 1}`, status: 'requested',
                starts_at: '2026-03-03T09:00:00-08:00', ends_at: '2026-03-03T10:00:00-08:00' })),
            { id: id(11), booking_id: booking, title: 'Fictional spring seminar',
                status: state.approved ? 'approved' : 'requested',
                starts_at: '2026-03-08T01:30:00-08:00', ends_at: '2026-03-08T03:30:00-07:00' },
        ];
        const monday = new Date(`${start}T12:00:00Z`);
        const dates = Array.from({ length: 8 }, (_, n) => new Date(monday.getTime() + n * 86400000).toISOString().slice(0, 10));
        const days = dates.slice(0, 7).map((date, n) => {
            const a = new Date(`${date}T00:00:00${date < '2026-03-09' ? '-08:00' : '-07:00'}`);
            const b = new Date(`${dates[n + 1]}T00:00:00${dates[n + 1] < '2026-03-09' ? '-08:00' : '-07:00'}`);
            const rows = items.filter(item => new Date(item.starts_at) < b && new Date(item.ends_at) > a);
            return { date, counts: { approved: rows.filter(item => item.status === 'approved').length,
                requested: rows.filter(item => item.status === 'requested').length },
                items: rows.map(item => ({ id: item.id, continues_before: new Date(item.starts_at) < a,
                    continues_after: new Date(item.ends_at) > b })) };
        });
        return { week: start, through: dates[6], today: '2026-03-08',
            previous: start === '2026-03-02' ? '2026-02-23' : '2026-03-02', next: dates[7],
            timezone: 'America/Los_Angeles', room: { id: room, code: 'Fictional.101', capacity: 30 },
            totals: { approved: items.filter(item => item.status === 'approved').length,
                requested: items.filter(item => item.status === 'requested').length }, days, items };
    }
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) state.calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/rooms/') result = { items: [{ id: room, code: 'Fictional.101', capacity: 30 }], next_after: null };
        else if (path.startsWith('/sis/ui/')) result = { items: [], next_after: null };
        else if (path === `/sis/rooms/${room}/`) result = { id: room, code: 'Fictional.101', capacity: 30, revision: 'fictional' };
        else if (path === `/sis/rooms/${room}/agenda/`) result = { items: [], next_after: null };
        else if (path === `/sis/rooms/${room}/week/`) {
            if (state.overflow) return route.fulfill({ status: 422, json: { message: 'Use the paged room agenda.',
                errors: [{ field: 'week', message: 'This week exceeds 500 occurrences. Use the paged room agenda.' }] } });
            result = week(data.week);
            if (data.week === state.delayed) await new Promise(resolve => { state.release = resolve; });
        } else if (path === `/sis/bookings/${booking}/`) result = { id: booking, room_id: room,
            title: 'Fictional spring seminar', status: state.approved ? 'approved' : 'requested', version: state.approved ? 1 : 0,
            occurrences: [{ id: id(50), status: state.approved ? 'approved' : 'requested', starts_at: '2026-03-08T01:30:00-08:00', ends_at: '2026-03-08T03:30:00-07:00' }] };
        else if (path.endsWith('/approve/')) state.approved = true;
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="bookings"]').click();
    await page.locator('#directory-tabs').getByRole('link', { name: 'Rooms & spaces' }).click();
    await page.getByRole('button', { name: 'Fictional.101', exact: true }).click();
    await page.getByRole('button', { name: 'Weekly timetable', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Fictional.101 · Capacity: 30' })).toBeVisible();
    return state;
}

test('weekly room timetable keeps school time, pages days and returns from booking approval with fresh counts', async ({ page }) => {
    const state = await fixture(page);
    const body = page.locator('#drawer-body');
    await expect(body).toContainText('America/Los_Angeles');
    await expect(body).toContainText('01:30 UTC-08:00');
    await expect(body).toContainText('03:30 UTC-07:00');
    await expect(page.getByRole('button', { name: /^Sunday, Mar 8/ })).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: /^Monday, Mar 2/ }).click();
    await expect(body).toContainText('Continues from an earlier day');
    await expect(body).toContainText('Continues into the next day');
    await page.getByRole('button', { name: /^Tuesday, Mar 3/ }).click();
    await expect(body.locator('tbody tr')).toHaveCount(20);
    await expect(body.locator('img')).toHaveCount(0);
    await page.getByRole('button', { name: 'Next daily page', exact: true }).click();
    await expect(body.locator('tbody tr')).toHaveCount(2);
    await page.getByLabel('Show approved bookings only').check();
    await expect(body.locator('tbody tr')).toHaveCount(1);
    await expect(page.getByRole('button', { name: 'Next daily page', exact: true })).toBeDisabled();
    const scans = async () => {
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    };
    await scans();
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await scans();
    await page.getByLabel('Show approved bookings only').uncheck();
    await page.getByRole('button', { name: /^Sunday, Mar 8/ }).click();
    await page.getByRole('button', { name: 'Fictional spring seminar', exact: true }).click();
    await page.getByRole('button', { name: 'Approve space request', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm space approval', exact: true }).click();
    await page.getByRole('button', { name: 'Back to weekly timetable', exact: true }).click();
    await expect(body).toContainText('2 approved and 21 requested occurrences');
    await expect(page.getByRole('button', { name: /^Sunday, Mar 8/ })).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: 'Next week', exact: true }).click();
    await expect(body).toContainText('2026-03-09 through 2026-03-15');
    await expect(body).toContainText('No requested or approved bookings for this day.');
    await page.getByRole('button', { name: 'Previous week', exact: true }).click();
    await expect(body).toContainText('2026-03-02 through 2026-03-08');
    expect(state.calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(state.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('weekly timetable ignores old and detached responses and offers the agenda after overflow', async ({ page }) => {
    const state = await fixture(page);
    const body = page.locator('#drawer-body');
    state.delayed = '2026-03-09';
    await page.getByRole('button', { name: 'Next week', exact: true }).click();
    await expect.poll(() => typeof state.release).toBe('function');
    await page.getByRole('button', { name: 'This week', exact: true }).click();
    await expect(body).toContainText('2026-03-02 through 2026-03-08');
    await page.getByLabel('Date in week').fill('2026-03-05');
    state.release();
    await expect(body).toContainText('Choose View week to load these dates.');
    await expect(page.getByLabel('Date in week')).toHaveValue('2026-03-05');
    state.overflow = true;
    await page.getByRole('button', { name: 'View week', exact: true }).click();
    await expect(body).toContainText('Timetable unavailable. Retry or use the paged room agenda.');
    await expect(body.locator('tbody')).toHaveCount(0);
    state.overflow = false;
    await page.getByRole('button', { name: 'View week', exact: true }).click();
    await expect(body).toContainText('2026-03-02 through 2026-03-08');
    state.release = null;
    await page.getByRole('button', { name: 'Next week', exact: true }).click();
    await expect.poll(() => typeof state.release).toBe('function');
    await page.getByRole('button', { name: 'Back to room agenda', exact: true }).click();
    state.release();
    await expect(page.getByRole('button', { name: 'View agenda', exact: true })).toBeVisible();
    await expect(body).not.toContainText('2026-03-09 through 2026-03-15');
    expect(state.errors).toEqual([]);
});
