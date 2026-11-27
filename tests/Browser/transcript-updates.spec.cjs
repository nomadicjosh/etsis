'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const notificationPerson = require('./helpers/notification-person.cjs');
const id = number => String(number).padStart(26, '0');
async function fixture(page, { portal = false, count = 1, status = 'ready', delivery = 'pickup' } = {}) {
    const base = await notificationPerson(page, { portal });
    let denied = false, stale = false;
    let items = Array.from({ length: count }, (_, number) => ({ id: id(100 - number), revision: 1,
        status, unread: true, delivery, requested_at: '2026-11-24T23:30:00-07:00',
        recorded_at: '2026-11-25T12:00:00.000000Z' }));
    const calls = [];
    await page.route('**/portal/api/transcript-updates/**', async route => {
        const req = route.request(), url = new URL(req.url()), notice = url.pathname.split('/')[4];
        calls.push({ path: url.pathname, method: req.method() });
        if (denied) return route.fulfill({ status: 403, json: { error: 'Fictional denied owner' } });
        let result;
        if (req.method() === 'POST') {
            expect(req.headers()['x-csrf-token']).toBe('fictional-test-token');
            const body = JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json'));
            const item = items.find(row => row.id === notice);
            expect(body).toEqual({ revision: item.revision });
            if (stale) return route.fulfill({ status: 409, json: { error: 'Fictional stale notice' } });
            if (url.pathname.endsWith('/delete/')) { items = items.filter(row => row.id !== notice); result = { deleted: true }; }
            else { item.unread = false; result = { unread: false }; }
        } else if (notice) {
            const item = items.find(row => row.id === notice);
            result = { status: item.status, delivery: item.delivery, requested_at: item.requested_at, recorded_at: item.recorded_at };
        } else {
            const rows = items.filter(item => (url.searchParams.get('include_read') === '1' || item.unread)
                && (!url.searchParams.get('after') || item.id < url.searchParams.get('after')));
            result = { items: rows.slice(0, 20), next: rows.length > 20 ? { at: rows[19].recorded_at, id: rows[19].id } : null };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    if (portal) await page.goto('/portal/student/transcripts/');
    const trigger = page.locator(portal ? '#campus-notifications-open' : '#workspace-notifications-open');
    await trigger.click();
    const dialog = page.locator('.workspace-notification-dialog');
    const section = dialog.locator('.notification-feed');
    return { ...base, trigger, dialog, section, calls, deny: () => { denied = true; }, stale: () => { stale = true; } };
}
for (const portal of [false, true]) test(`${portal ? 'portal' : 'workspace'} transcript notice opens minimized status and deletes permanently without granting a document`, async ({ page }) => {
    const f = await fixture(page, { portal });
    expect(f.calls.some(call => /updates\/[^/]+\//.test(call.path))).toBe(false);
    await expect(f.section).toContainText('Transcript prepared');
    await expect(f.section.getByRole('button', { name: 'Mark as read', exact: true })).toHaveCount(0);
    await expect(f.section.getByLabel('Show read updates', { exact: true })).toBeHidden();
    await f.section.getByRole('button', { name: 'View transcript request', exact: true }).click();
    await expect(f.section).toContainText('Current transcript request');
    await expect(f.section).toContainText('2026-11-24');
    await expect(f.section).toContainText('Preparation is separate from delivery.');
    await expect(f.section.getByRole('link', { name: 'Open transcript requests', exact: true }))
        .toHaveAttribute('href', '/portal/student/transcripts/');
    expect(f.calls.filter(call => call.method === 'POST')).toEqual([{ path: `/portal/api/transcript-updates/${id(100)}/read/`, method: 'POST' }]);
    await expect(f.section.locator('iframe, object, embed')).toHaveCount(0);
    await f.section.getByRole('button', { name: 'Back to notifications', exact: true }).click();
    await expect(f.section).toContainText('Transcript prepared');
    await expect(f.section.getByRole('button', { name: 'View transcript request', exact: true })).toBeFocused();
    await f.section.getByRole('button', { name: 'Delete notification', exact: true }).click();
    await expect(f.section).toContainText('You have no notifications.');
    await page.keyboard.press('Escape'); await page.reload();
    await f.trigger.click(); await expect(f.section).toContainText('You have no notifications.');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('transcript notices page both ways and stale revisions preserve the current list', async ({ page }) => {
    const f = await fixture(page, { count: 25 });
    await expect(f.section.locator('[data-update-id]')).toHaveCount(20);
    await f.section.getByRole('button', { name: 'Older notifications', exact: true }).click();
    await expect(f.section.locator('[data-update-id]')).toHaveCount(5);
    await f.section.getByRole('button', { name: 'Previous notifications', exact: true }).click();
    await expect(f.section.locator('[data-update-id]')).toHaveCount(20);
    f.stale();
    await f.section.getByRole('button', { name: 'Delete notification', exact: true }).first().click();
    await expect(f.section).toContainText('This notification changed or is unavailable. Refresh to try again.');
    await expect(f.section.locator('[data-update-id]')).toHaveCount(20);
    expect(f.errors).toEqual([]);
});

test('closing transcript details rejects a late response before any later read mutation', async ({ page }) => {
    const f = await fixture(page); let release, arrive;
    const gate = new Promise(resolve => { release = resolve; });
    const requested = new Promise(resolve => { arrive = resolve; });
    await page.route(`**/portal/api/transcript-updates/${id(100)}/`, async route => {
        arrive(); await gate; await route.fulfill({ json: { status: 'ready', delivery: 'pickup', requested_at: '2026-11-24T23:30:00-07:00' } });
    });
    await f.section.getByRole('button', { name: 'View transcript request', exact: true }).click();
    await requested; await page.keyboard.press('Escape'); release();
    await page.waitForTimeout(50);
    expect(f.calls.some(call => call.method === 'POST')).toBe(false);
    await expect(page.locator('body')).not.toContainText('Current transcript request');
    expect(f.errors).toEqual([]);
});

test('private transcript details support keyboard return, phone reflow and access-loss cleanup', async ({ page }) => {
    const f = await fixture(page, { portal: true, status: 'fulfilled', delivery: 'electronic' }); await expect(f.section).toBeVisible();
    await expect(f.section).toContainText('Transcript delivery recorded');
    await f.section.getByRole('button', { name: 'View transcript request', exact: true }).press('Enter');
    await expect(f.section.getByRole('heading', { name: 'Current transcript request', exact: true })).toBeFocused();
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    expect(await f.dialog.evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
    await f.section.getByRole('heading', { name: 'Current transcript request', exact: true }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: '/tmp/etsis-transcript-notices-phone.png' });
    f.deny();
    await f.section.getByRole('button', { name: 'Delete notification', exact: true }).click();
    await expect(f.dialog).toBeVisible();
    await expect(f.dialog.getByText('Notifications are unavailable for this account.', { exact: true })).toBeFocused();
    await expect(page.locator('body')).not.toContainText('Current transcript request');
    expect(f.errors).toEqual([]);
});
