const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

for (const theme of ['campus-harbor', 'campus-quad', 'campus-horizon']) {
    test(`${theme}: public calendar filters and pages without private account requests`, async ({ page }) => {
        const calls = [], errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => { if (request.url().includes('/portal/api/')) calls.push(request.url()); });
        await page.goto(`/portal/events/?guest=1&theme=${theme}`);
        const calendar = page.locator('[data-public-events]');
        await expect(calendar.getByRole('article')).toHaveCount(20);
        await expect(calendar.locator('.academic-timetable-day')).toHaveCount(31);
        await expect(calendar.locator('img')).toHaveCount(0);
        await calendar.getByRole('link', { name: 'Next events', exact: true }).click();
        await expect(calendar.getByRole('article')).toHaveCount(1);
        await expect(calendar.locator('[data-event-day-heading]')).toBeFocused();
        await calendar.getByRole('link', { name: /Tuesday, Aug 11, 2026: 0/ }).click();
        await expect(calendar).toContainText('No public events match this day');
        await calendar.getByLabel('Calendar view', { exact: true }).selectOption('week');
        await calendar.getByRole('button', { name: 'Explore events', exact: true }).click();
        await expect(calendar.locator('.academic-timetable-day')).toHaveCount(7);
        await expect(calendar.locator('[data-event-heading]')).toBeFocused();
        await calendar.getByLabel('Find an event or location').fill('No match');
        await calendar.getByRole('button', { name: 'Explore events', exact: true }).click();
        await expect(calendar).toContainText('0 public event times');
        const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(result.violations).toEqual([]);
        await page.setViewportSize({ width: 320, height: 900 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        expect(calls.every(url => url.includes('/portal/api/events/'))).toBe(true);
        expect(errors).toEqual([]);
    });
}

test('public calendar remains usable without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto('/portal/events/?guest=1');
    await page.getByRole('link', { name: 'Next events', exact: true }).click();
    await expect(page.getByRole('article')).toHaveCount(1);
    await page.getByLabel('Find an event or location').fill('No match');
    await page.getByRole('button', { name: 'Explore events', exact: true }).click();
    await expect(page.locator('[data-public-events]')).toContainText('0 public event times');
    await context.close();
});

test('a delayed calendar response cannot replace a newer search draft', async ({ page }) => {
    let release;
    await page.route('**/portal/api/events/**', async route => {
        const response = await route.fetch();
        await new Promise(resolve => { release = resolve; });
        await route.fulfill({ response }).catch(() => {});
    });
    await page.goto('/portal/events/?guest=1');
    await page.getByRole('link', { name: 'Next', exact: true }).click();
    await expect.poll(() => !!release).toBe(true);
    await page.getByLabel('Find an event or location').fill('Keep this draft');
    release();
    await expect(page.getByLabel('Find an event or location')).toHaveValue('Keep this draft');
    await expect(page.locator('[data-event-heading]')).toContainText('Aug 1');
});

test('staff deliberately review public text and dates, then publish or withdraw from the same workflow', async ({ page }) => {
    const calls = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const booking = { id: id(1), room_id: id(2), title: 'PRIVATE staff purpose', status: 'approved', version: 1,
        section: null, occurrences: [{ id: id(3), starts_at: '2026-08-10T18:00:00Z', ends_at: '2026-08-10T19:00:00Z', status: 'approved' }] };
    let current = null;
    const preview = { room_label: 'LIB.101 · Fictional room', building_label: 'LIB · Fictional library', timezone: 'America/Los_Angeles',
        times: Array.from({ length: 21 }, () => booking.occurrences[0]) };
    let draft;
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/bookings/') result.items = [booking];
        else if (path === `/sis/bookings/${booking.id}/`) result = booking;
        else if (path === `/sis/rooms/${booking.room_id}/`) result = { id: booking.room_id, code: 'LIB.101', name: 'Fictional room', capacity: 40 };
        else if (path.endsWith('/public-event/setup/')) result = { current, preview };
        else if (path.endsWith('/public-event/review/')) { draft = data; result = { ...data, review_token: 'fictional-encrypted-review', preview }; }
        else if (path.endsWith('/public-event/publish/')) { current = { ...draft, id: id(9), version: 1, published: 1 }; result = { id: id(9), version: 1 }; }
        else if (path.endsWith('/public-event/withdraw/')) { current.published = 0; current.version++; result = { status: 'withdrawn' }; }
        await route.fulfill({ json: result });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="bookings"]').click();
    await page.getByRole('button', { name: /Open PRIVATE staff purpose/ }).click();
    await page.getByRole('button', { name: 'Manage public event', exact: true }).click();
    const body = page.locator('#drawer-body');
    await expect(page.getByLabel('Public event title')).toHaveValue('');
    await page.getByLabel('Public event title').fill('Open house <img>');
    await page.getByLabel('Public event description').fill('A fictional public invitation.');
    await page.getByRole('button', { name: 'Review public event', exact: true }).click();
    await expect(body).not.toContainText('PRIVATE staff purpose');
    await expect(body.locator('img')).toHaveCount(0);
    await page.getByRole('button', { name: 'Next event dates', exact: true }).click();
    await expect(body.locator('[aria-label="Public event review"] tbody tr')).toHaveCount(1);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations).toEqual([]);
    await page.getByRole('button', { name: 'Publish public event', exact: true }).click();
    await expect(body).toContainText('Public event content is published');
    await page.getByRole('button', { name: 'Withdraw public event', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm withdrawal', exact: true }).click();
    await expect(body).toContainText('This event is not published');
    expect(calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(calls.find(call => call.path.endsWith('/public-event/publish/')).data)
        .toEqual({ review_token: 'fictional-encrypted-review' });
    expect(errors).toEqual([]);
});
