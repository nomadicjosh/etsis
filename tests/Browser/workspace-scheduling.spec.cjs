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
        else if (path === `/sis/rooms/${room}/`) result = { id: room, code, capacity, revision: 'fictional-room-revision' };
        else if (path === `/sis/rooms/${room}/edit/`) { code = data.code; capacity = data.capacity; }
        else if (path === `/sis/rooms/${room}/availability/`) result = { available: false };
        else if (path === `/sis/rooms/${room}/agenda/`) result = { items: [{ id: id(3), booking_id: booking, title: 'Fictional seminar', status,
            starts_at: '2026-08-11T09:00:00+00:00', ends_at: '2026-08-11T10:00:00+00:00' }], next_after: data.after ? null : id(3) };
        else if (path === '/sis/bookings/') result = { id: booking, status };
        else if (path === `/sis/bookings/${booking}/`) result = { id: booking, room_id: room, title: 'Fictional seminar', status, version,
            slots: [{ starts_at: '2026-08-11T09:00:00+00:00', ends_at: '2026-08-11T10:00:00+00:00' }] };
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
    await page.getByRole('button', { name: 'View audit history' }).click();
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
    await page.getByLabel('Room name or code').fill('LIBRARY.102');
    await page.getByLabel(/^Capacity/).fill('35');
    await page.getByRole('button', { name: 'Save room details' }).click();
    await expect(page.locator('#drawer-body')).toContainText('Capacity: 35');
    expect(calls.find(item => item.path.endsWith('/edit/'))?.data).toMatchObject({ code: 'LIBRARY.102', capacity: 35, revision: 'fictional-room-revision' });
    await page.getByRole('button', { name: 'Request this room' }).click();
    await page.getByLabel('What is the space for?').fill('Fictional seminar');
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
    await page.getByRole('button', { name: 'Submit space request' }).click();
    await expect(page.getByRole('button', { name: 'Approve space request', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Approve space request', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm space approval', exact: true }).click();
    await expect(page.locator('#drawer-body .status-badge')).toHaveText('approved');
    expect(calls.find(item => item.path.endsWith('/approve/'))?.data).toEqual({ version: 0 });
    await page.getByRole('button', { name: 'Cancel space request', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm space cancellation', exact: true }).click();
    await expect(page.locator('#drawer-body .status-badge')).toHaveText('cancelled');
    expect(calls.find(item => item.path.endsWith('/cancel/'))?.data).toEqual({ version: 1 });
    expect(calls.every(item => item.csrf === 'fictional-test-token')).toBe(true);
    await accessible();
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible();
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
