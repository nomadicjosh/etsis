const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

const booking = '01K00000000000000000000031';
const item = version => ({ id: booking, title: 'Fictional <img src=x onerror=alert(1)> seminar',
    version, kind: 'rescheduled', label: 'Meeting rescheduled', status: 'cancelled',
    updated_local: 'Aug 10, 2026 at 10:00 am PDT', unread: true });
const meeting = (hour, cancelled) => ({ local_start: `Aug 12, 2026 at ${hour}:00 am PDT`,
    local_end: `Aug 12, 2026 at ${hour + 1}:00 am PDT`, cancelled });
const detail = version => ({ ...item(version), booking: { status: 'cancelled', location: 'Fictional library',
    room_code: 'LIB.101', timezone: 'America/Los_Angeles', meetings: [meeting(9, true)] },
    replacement: { status: 'approved', location: 'Online', room_code: null,
        timezone: 'America/Los_Angeles', meetings: [meeting(10, false)] } });

test('requesters read private booking changes without loading student or financial overviews', async ({ page }) => {
    const calls = [], errors = [];
    let read = false;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/portal/api/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path.endsWith('/page/')) result = { items: read && data.unread_only ? [] : [{ ...item(2), unread: !read }],
            next: null, unread_count: read ? 0 : 1, timezone: 'America/Los_Angeles' };
        else if (path.endsWith('/read/')) { read = true; result = { status: 'read' }; }
        else result = { ...detail(2), unread: !read };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/portal/bookings/');
    await expect(page.getByRole('heading', { name: 'Booking updates', exact: true })).toBeVisible();
    await expect(page.locator('#campus-service-content')).toContainText('1 unread booking updates');
    await page.getByRole('button', { name: 'Read update', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Replacement meeting', exact: true })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Booking update details', exact: true })).toContainText('Online');
    expect(read).toBe(false);
    expect(await page.locator('#campus-service-content img').count()).toBe(0);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    await page.getByRole('button', { name: 'Mark update as read', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Booking update details', exact: true })).toContainText('Marked as read.');
    await expect(page.locator('#campus-service-content')).toContainText('0 unread booking updates');
    expect(calls.find(call => call.path.endsWith('/read/'))).toMatchObject({ data: { version: 2 }, csrf: 'fictional-test-token' });
    await page.getByRole('button', { name: 'Back to booking updates', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Your booking updates', exact: true })).toContainText('You’re all caught up');
    await page.getByRole('checkbox', { name: 'Include read updates', exact: true }).check();
    await expect(page.getByRole('button', { name: 'Read update', exact: true })).toBeVisible();
    expect(calls.filter(call => !call.path.startsWith('/portal/api/booking-updates/'))).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});

test('a newer booking update requires a fresh read before acknowledgement', async ({ page }) => {
    let version = 2;
    const acknowledgements = [];
    await page.route('**/portal/api/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (path.endsWith('/page/')) return route.fulfill({ json: { items: [item(version)], unread_count: 1,
            next: null, timezone: 'America/Los_Angeles' } });
        if (path.endsWith('/read/')) {
            acknowledgements.push(data.version);
            if (version === 2) {
                version = 3;
                return route.fulfill({ status: 409, json: { error: 'This booking has a newer update. Refresh it before marking it as read.' } });
            }
            return route.fulfill({ json: { status: 'read' } });
        }
        return route.fulfill({ json: detail(version) });
    });
    await page.goto('/portal/bookings/');
    await page.getByRole('button', { name: 'Read update', exact: true }).click();
    await page.getByRole('button', { name: 'Mark update as read', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('newer update');
    await expect(page.getByRole('button', { name: 'Mark update as read', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Refresh this booking update', exact: true }).click();
    await page.getByRole('button', { name: 'Mark update as read', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Booking update details', exact: true })).toContainText('Marked as read.');
    expect(acknowledgements).toEqual([2, 3]);
});

test('expired sign-in returns the booking requester to login', async ({ page }) => {
    await page.route('**/portal/api/**', route => route.fulfill({ status: 401, json: { error: 'Sign in again to continue.' } }));
    await page.goto('/portal/bookings/');
    await expect(page).toHaveURL(/\/login\//);
});
