const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

test('staff cancel a selected meeting with fresh confirmation while retaining the series and its other approvals', async ({ page }) => {
    const calls = [], errors = [], booking = id(1), room = id(2);
    let version = 7, cancelled = false, reject = true;
    page.on('pageerror', error => errors.push(error.message));
    const occurrences = () => Array.from({ length: 25 }, (_, n) => ({
        id: id(100 + n), starts_at: `2026-08-${String(n + 1).padStart(2, '0')}T09:00:00-07:00`,
        ends_at: `2026-08-${String(n + 1).padStart(2, '0')}T10:00:00-07:00`,
        status: cancelled && n === 21 ? 'cancelled' : 'approved',
        cancelled_at: cancelled && n === 21 ? '2026-08-01T10:00:00Z' : null,
    }));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/bookings/') result = { items: [{ id: booking,
            title: 'Fictional <script> lab sessions', status: 'approved' }], next_after: null };
        else if (path === `/sis/bookings/${booking}/`) result = { id: booking, room_id: room,
            title: 'Fictional <script> lab sessions', version, status: 'approved', occurrences: occurrences() };
        else if (path === `/sis/rooms/${room}/`) result = { id: room, code: 'SCI.101', name: 'Teaching laboratory',
            capacity: 25, building: { code: 'SCI', name: 'Science building' }, building_id: id(3) };
        else if (path === `/sis/bookings/${booking}/occurrences/${id(121)}/cancel/`) {
            if (reject) {
                reject = false; version = 8;
                return route.fulfill({ status: 409, json: { error: 'Fictional changed booking. Reload before cancelling.' } });
            }
            cancelled = true; version++;
            result = { id: booking, version, status: 'approved' };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    const body = page.locator('#drawer-body');
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="bookings"]').click();
    await page.getByRole('button', { name: 'Fictional <script> lab sessions', exact: true }).click();
    await expect(body.getByRole('region', { name: 'Booking meetings', exact: true }).getByRole('article')).toHaveCount(20);
    await page.getByRole('button', { name: 'Next booking meetings', exact: true }).click();
    await expect(body.getByRole('region', { name: 'Booking meetings', exact: true }).getByRole('article')).toHaveCount(5);
    await body.getByRole('button', { name: /^Cancel meeting on / }).nth(1).click();
    await expect(page.getByRole('button', { name: 'Back to booking', exact: true })).toBeFocused();
    await expect(body).toContainText('only this meeting');
    await page.getByRole('button', { name: 'Confirm meeting cancellation', exact: true }).click();
    await expect(body).toContainText('Fictional changed booking. Reload before cancelling.');
    expect(cancelled).toBe(false);
    await page.getByRole('button', { name: 'Back to booking', exact: true }).click();
    await expect(body).toContainText('Meetings 21–25 of 25.');
    await body.getByRole('button', { name: /^Cancel meeting on / }).nth(1).click();
    await page.getByRole('button', { name: 'Confirm meeting cancellation', exact: true }).click();
    await expect(body).toContainText('24 active meetings.');
    await expect(body.locator('.status-badge').first()).toHaveText('approved');
    await expect(body).toContainText('Meetings 21–25 of 25.');
    await expect(body.getByRole('region', { name: 'Booking meetings', exact: true }).getByRole('article')).toHaveCount(5);
    await expect(body.getByRole('button', { name: /^Cancel meeting on / })).toHaveCount(4);
    await expect(body.locator('.scheduling-meeting-list .status-badge').filter({ hasText: /^cancelled$/ })).toHaveCount(1);
    const cancellations = calls.filter(call => call.path.includes('/occurrences/'));
    expect(cancellations.map(call => call.data)).toEqual([{ version: 7 }, { version: 8 }]);
    expect(calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await body.locator('script').count()).toBe(0);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
