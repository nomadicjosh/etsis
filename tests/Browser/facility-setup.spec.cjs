const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

test('room setup separates codes and names and creates its building without losing the room draft', async ({ page }) => {
    const calls = [], errors = [];
    let building = null, room = null, rejectEdit = true;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/buildings/') result = { items: building ? [building] : [], next_after: null };
        else if (path === '/sis/ui/data/rooms/') result = { items: room ? [{ ...room, building_name: building.name }] : [], next_after: null };
        else if (path === '/sis/buildings/') {
            building = { id: id(1), ...data, revision: 'fictional-building-revision' }; result = { id: building.id };
        } else if (path === `/sis/buildings/${id(1)}/`) result = building;
        else if (path === '/sis/rooms/') {
            room = { id: id(2), ...data, revision: 'fictional-room-revision' }; result = { id: room.id };
        } else if (path === `/sis/rooms/${id(2)}/`) result = { ...room, building };
        else if (path === `/sis/rooms/${id(2)}/edit/`) {
            if (rejectEdit) {
                rejectEdit = false;
                return route.fulfill({ status: 409, json: { error: 'Fictional stale room. Reload before saving.' } });
            }
            room = { ...room, ...data }; result = { status: 'saved' };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    const body = page.locator('#drawer-body');
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="bookings"]').click();
    await page.locator('#directory-tabs').getByRole('link', { name: 'Rooms & spaces' }).click();
    await page.getByRole('button', { name: 'Add a room', exact: true }).click();
    await page.getByLabel('Room code', { exact: false }).fill('LIB.101');
    await page.getByLabel('Room name', { exact: false }).fill('Quiet <img src=x> study room');
    await page.getByLabel(/^Capacity/).fill('25');
    await page.getByRole('button', { name: 'Add a building', exact: true }).click();
    await page.getByRole('button', { name: 'Back to room details', exact: true }).click();
    await expect(page.getByLabel('Room code', { exact: false })).toHaveValue('LIB.101');
    await expect(page.getByLabel('Room name', { exact: false })).toHaveValue('Quiet <img src=x> study room');
    await expect(page.getByLabel(/^Capacity/)).toHaveValue('25');
    await page.getByRole('button', { name: 'Add a building', exact: true }).click();
    await page.getByLabel('Building code', { exact: false }).fill('LIB');
    await page.getByLabel('Building name', { exact: false }).fill('Campus library');
    await page.getByRole('button', { name: 'Save building', exact: true }).click();
    await expect(page.getByLabel('Room code', { exact: false })).toHaveValue('LIB.101');
    await expect(page.getByRole('combobox', { name: 'Building', exact: true })).toHaveValue(id(1));
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    await page.getByRole('button', { name: 'Save room', exact: true }).click();
    await expect(body).toContainText('LIB · Campus library');
    await expect(page.locator('#drawer-title')).toHaveText('LIB.101 · Quiet <img src=x> study room');
    await expect(body.locator('img')).toHaveCount(0);
    expect(calls.find(call => call.path === '/sis/rooms/')?.data).toEqual({ code: 'LIB.101',
        name: 'Quiet <img src=x> study room', building_id: id(1), capacity: 25 });
    await page.getByRole('button', { name: 'Edit room details', exact: true }).click();
    await page.getByLabel('Room name', { exact: false }).fill('Reading room');
    await page.getByRole('button', { name: 'Save room details', exact: true }).click();
    await expect(body).toContainText('Fictional stale room. Reload before saving.');
    await expect(page.getByLabel('Room name', { exact: false })).toHaveValue('Reading room');
    await page.getByRole('button', { name: 'Back to room agenda', exact: true }).click();
    await expect(page.locator('#drawer-title')).toContainText('Quiet <img src=x> study room');
    expect(calls.filter(call => call.path.endsWith('/edit/'))[0].data.revision).toBe('fictional-room-revision');
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
