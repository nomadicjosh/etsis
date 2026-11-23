const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K00000000000000000${String(n).padStart(7, '0')}`;

async function fixture(page, { root = 'rooms', delayed = null, empty = false } = {}) {
    const calls = [], errors = [];
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    const room = { id: id(1), code: 'LIB.101', name: 'Learning studio', capacity: 24,
        building_id: id(2), building: { code: 'LIB', name: 'Campus library' }, revision: 'fictional-room' };
    const building = { id: id(2), code: 'LIB', name: 'Campus library', revision: 'fictional-building' };
    const booking = { id: id(3), title: 'Fictional <script>seminar</script>', room_id: id(1),
        status: 'approved', version: 2, occurrences: empty ? [] : Array.from({ length: 25 }, (_, n) => ({
            id: id(100 + n), starts_at: `2026-11-${String(n + 1).padStart(2, '0')}T09:00:00Z`,
            ends_at: `2026-11-${String(n + 1).padStart(2, '0')}T10:00:00Z`, status: 'approved',
        })) };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        const raw = request.postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, method: request.method() });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/rooms/') result.items = [room];
        else if (path === '/sis/ui/data/buildings/') result.items = [building];
        else if (path === '/sis/ui/data/bookings/') result.items = [booking];
        else if (path === '/sis/ui/lookup/buildings/') result.items = [{ value: id(2), label: 'LIB · Campus library' }];
        else if (path === '/sis/ui/lookup/rooms/') result.items = [{ value: id(1), label: 'LIB.101 · Learning studio' }];
        else if (path === `/sis/rooms/${id(1)}/`) {
            if (delayed === 'rooms' || delayed === 'booking-room') await pending;
            result = room;
        } else if (path === `/sis/buildings/${id(2)}/`) {
            if (delayed === 'buildings') await pending;
            result = building;
        } else if (path === `/sis/bookings/${id(3)}/`) {
            if (delayed === 'bookings') await pending;
            result = booking;
        } else if (path === `/sis/rooms/${id(1)}/agenda/`) result = {
            items: [{ booking_id: id(3), title: data.after ? 'Second-page seminar' : booking.title,
                category_name: 'Class meeting', category_tone: 'blue', section_code: 'HIST.101.A', section_term_code: '26/FA',
                status: 'approved', starts_at: '2026-11-01T09:00:00Z', ends_at: '2026-11-01T10:00:00Z' }],
            next_after: data.after ? null : id(50),
        };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="bookings"]').click();
    if (root === 'rooms') await page.locator('#directory-tabs').getByRole('link', { name: 'Rooms & spaces', exact: true }).click();
    if (root === 'buildings') await page.locator('#directory-tabs').getByRole('link', { name: 'Buildings', exact: true }).click();
    const label = root === 'rooms' ? 'LIB.101' : root === 'buildings' ? 'LIB' : booking.title;
    await page.getByRole('button', { name: label, exact: true }).click();
    return { calls, errors, release };
}

async function selectAgenda(page) {
    await page.getByLabel(/^Starts/u).fill('2026-11-01T00:00');
    await page.getByLabel(/^Ends/u).fill('2026-11-10T00:00');
    await page.getByRole('button', { name: 'View agenda', exact: true }).click();
    await page.getByRole('button', { name: 'Next agenda page', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Second-page seminar', exact: true })).toBeVisible();
}

async function assertAgenda(page, calls) {
    await expect(page.getByLabel(/^Starts/u)).toHaveValue('2026-11-01T00:00');
    await expect(page.getByLabel(/^Ends/u)).toHaveValue('2026-11-10T00:00');
    await expect(page.getByRole('button', { name: 'Second-page seminar', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Previous agenda page', exact: true })).toBeEnabled();
    const window = await page.evaluate(() => ({
        starts_at: new Date('2026-11-01T00:00').toISOString(),
        ends_at: new Date('2026-11-10T00:00').toISOString(),
    }));
    const request = calls.filter(call => call.path.endsWith('/agenda/')).at(-1).data;
    expect({ ...request, starts_at: new Date(request.starts_at).toISOString(), ends_at: new Date(request.ends_at).toISOString() })
        .toEqual({ ...window, after: id(50) });
}

async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, nodes: v.nodes.map(n => ({ target: n.target, summary: n.failureSummary })) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}

test('a booking returns to the viewed room agenda window and page with fresh reads', async ({ page }) => {
    const f = await fixture(page);
    await selectAgenda(page);
    await page.getByRole('button', { name: 'Second-page seminar', exact: true }).click();
    const meetings = page.getByRole('region', { name: 'Booking meetings', exact: true });
    await expect(meetings).toBeVisible();
    expect((await page.getByRole('group', { name: 'Booking decisions', exact: true }).boundingBox()).y)
        .toBeLessThan((await meetings.boundingBox()).y);
    await page.getByRole('button', { name: 'Back to room agenda', exact: true }).click();
    await assertAgenda(page, f.calls);
    expect(f.calls.filter(call => call.path === `/sis/rooms/${id(1)}/`)).toHaveLength(3);
    expect(f.errors).toEqual([]);
});

test('building and room editor returns preserve the room agenda context', async ({ page }) => {
    const f = await fixture(page);
    await selectAgenda(page);
    await page.getByRole('button', { name: 'Open building', exact: true }).click();
    await expect(page.locator('#drawer-title')).toHaveText('LIB · Campus library');
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await assertAgenda(page, f.calls);
    await page.getByRole('button', { name: 'Edit room details', exact: true }).click();
    await page.getByRole('button', { name: 'Back to room agenda', exact: true }).click();
    await assertAgenda(page, f.calls);
    expect(f.errors).toEqual([]);
});

test('meeting cards keep the selected booking page through reschedule and cancellation returns', async ({ page }) => {
    const f = await fixture(page, { root: 'bookings' });
    await page.getByRole('button', { name: 'Next booking meetings', exact: true }).click();
    const meetings = page.getByRole('region', { name: 'Booking meetings', exact: true });
    await expect(meetings.getByRole('article')).toHaveCount(5);
    await meetings.getByRole('button', { name: /^Reschedule meeting on / }).first().click();
    await page.getByRole('button', { name: 'Back to booking', exact: true }).click();
    await expect(meetings.getByRole('article', { name: 'Meeting 21', exact: true })).toBeVisible();
    await meetings.getByRole('button', { name: /^Cancel meeting on / }).first().click();
    await expect(page.getByRole('button', { name: 'Back to booking', exact: true })).toBeFocused();
    await page.getByRole('button', { name: 'Back to booking', exact: true }).click();
    await expect(meetings.getByRole('article', { name: 'Meeting 21', exact: true })).toBeVisible();
    expect(f.calls.some(call => call.path.endsWith('/cancel/') || call.path.endsWith('/confirm/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('room agenda and booking cards reflow with keyboard dropdowns at phone width and text zoom', async ({ page }) => {
    const f = await fixture(page);
    const agenda = page.getByRole('region', { name: 'Room agenda', exact: true });
    await expect(agenda).toContainText('HIST.101.A · 26/FA');
    await expect(agenda.locator('time')).toHaveCount(2);
    await expect(agenda.locator('time').first()).toBeVisible();
    await expect(agenda.locator('time').first()).toContainText(/\d/u);
    await page.screenshot({ path: '/tmp/etsis-room-agenda-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    await agenda.getByRole('button', { name: 'Fictional <script>seminar</script>', exact: true }).click();
    const meetings = page.getByRole('region', { name: 'Booking meetings', exact: true });
    await expect(meetings.locator('script')).toHaveCount(0);
    await expect(meetings.locator('time').first()).toContainText(/\d/u);
    await accessible(page);
    const more = page.getByRole('button', { name: 'More booking actions', exact: true });
    await more.focus(); await page.keyboard.press('ArrowDown');
    const menu = page.locator('.workspace-action-menu .dropdown-menu.show');
    await expect(menu).toBeVisible();
    expect(await menu.evaluate(el => {
        const r = el.getBoundingClientRect();
        return r.left >= 0 && r.right <= innerWidth + 1 && el.contains(document.elementFromPoint(r.left + 10, r.top + 10));
    })).toBe(true);
    await page.keyboard.press('Escape'); await expect(more).toBeFocused();
    await page.locator('#record-drawer .offcanvas-body').evaluate(el => { el.scrollTop = 0; });
    await page.screenshot({ path: '/tmp/etsis-booking-cards-phone.png' });
    await meetings.getByRole('article').first().screenshot({ path: '/tmp/etsis-booking-meeting-card-phone.png' });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await accessible(page);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('an empty booking has an honest meeting summary with disabled paging', async ({ page }) => {
    const f = await fixture(page, { root: 'bookings', empty: true });
    await expect(page.getByRole('region', { name: 'Booking meetings', exact: true })).toContainText('No meetings in this booking.');
    await expect(page.locator('#drawer-body')).not.toContainText('Meetings 1–0');
    await expect(page.getByRole('button', { name: 'Next booking meetings', exact: true })).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Previous booking meetings', exact: true })).toBeDisabled();
    expect(f.errors).toEqual([]);
});

for (const root of ['rooms', 'bookings', 'buildings']) {
    test(`a delayed ${root} record cannot reopen a closed drawer`, async ({ page }) => {
        const f = await fixture(page, { root, delayed: root });
        await expect(page.locator('#record-drawer')).toHaveClass(/show/u);
        await page.getByRole('button', { name: 'Close record', exact: true }).click();
        await expect(page.locator('#drawer-body')).toBeEmpty();
        const finished = page.waitForEvent('requestfinished', {
            predicate: request => new URL(request.url()).pathname === `/sis/${root}/${id(root === 'rooms' ? 1 : root === 'buildings' ? 2 : 3)}/`,
        });
        f.release(); await finished;
        await page.waitForLoadState('networkidle');
        await expect(page.locator('#drawer-body')).toBeEmpty();
        expect(f.errors).toEqual([]);
    });
}

test('a delayed booking room lookup cannot reopen a closed drawer', async ({ page }) => {
    const f = await fixture(page, { root: 'bookings', delayed: 'booking-room' });
    await expect.poll(() => f.calls.some(call => call.path === `/sis/rooms/${id(1)}/`)).toBe(true);
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await expect(page.locator('#drawer-body')).toBeEmpty();
    const finished = page.waitForEvent('requestfinished', {
        predicate: request => new URL(request.url()).pathname === `/sis/rooms/${id(1)}/`,
    });
    f.release(); await finished;
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#drawer-body')).toBeEmpty();
    expect(f.errors).toEqual([]);
});
