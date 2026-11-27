'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const fixture = require('./helpers/notification-person.cjs');
const id = n => `01K000000000000000000${String(n).padStart(5, '0')}`;
const notice = (n, status, at) => ({ id: id(n), revision: 1, unread: true, status,
    recorded_at: at || '2026-11-26T12:00:00.000000Z', course_code: 'HIST.101', course_title: 'Fictional <b>History</b>',
    section_code: `Section ${n}`, term_code: 'FALL26' });
async function setup(page, portal = false) {
    const f = await fixture(page, { portal });
    const rows = new Map(), posts = [], denied = new Set();
    let failSource = null, detailsDenied = false;
    await page.route('**/portal/api/*-updates/**', async route => {
        const req = route.request(), url = new URL(req.url());
        const [, kind, ref, action] = url.pathname.match(/\/([a-z]+)-updates\/(?:([^/]+)\/)?(?:([^/]+)\/)?/) || [];
        if (denied.has(kind)) return route.fulfill({ status: 403, json: { error: 'Denied' } });
        if (failSource === kind) return route.fulfill({ status: 503, json: { error: 'Unavailable' } });
        if (req.method() === 'POST') {
            expect(req.headers()['x-csrf-token']).toBe('fictional-test-token');
            const body = JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json'));
            posts.push({ kind, ref, action, body });
            const item = (rows.get(kind) || []).find(item => item.id === ref);
            expect(body).toEqual({ revision: item.revision });
            if (action === 'delete') rows.set(kind, rows.get(kind).filter(item => item.id !== ref));
            else item.unread = false;
            return route.fulfill({ json: action === 'delete' ? { deleted: true } : { unread: false } });
        }
        if (ref) {
            if (detailsDenied) return route.fulfill({ status: 403, json: { error: 'Denied' } });
            return route.fulfill({ json: { course_code: 'HIST.101', course_title: 'Fictional <b>History</b>',
                grade: 'B', term_code: 'FALL26', status: 'A', completed_milli: 3000, private_reason: 'Never display staff evidence' } });
        }
        const inventory = (rows.get(kind) || []).filter(item => !url.searchParams.get('after') || item.id < url.searchParams.get('after'));
        await route.fulfill({ json: { items: inventory.slice(0, 20), next: inventory.length > 20
            ? { at: inventory[19].recorded_at, id: inventory[19].id } : null } });
    });
    const open = async () => {
        if (portal && !page.url().includes('/portal/')) await page.goto('/portal/student/profile/');
        await page.locator(portal ? '#campus-notifications-open' : '#workspace-notifications-open').click();
        await expect(page.getByRole('button', { name: 'Refresh notifications', exact: true })).toBeEnabled();
    };
    return { ...f, rows, posts, denied, open, fail: source => { failSource = source; },
        denyDetails: () => { detailsDenied = true; } };
}

for (const portal of [false, true]) test(`${portal ? 'portal' : 'workspace'} has one account feed with detail return and permanent deletion`, async ({ page }) => {
    const f = await setup(page, portal);
    f.rows.set('academic', [notice(10, 'posted', '2026-11-26T12:00:00.000001Z')]);
    f.rows.set('enrollment', [notice(2, 'registered', '2026-11-26T12:00:00.000002Z')]);
    f.denied.add('standing');
    await f.open();
    const dialog = page.locator('.workspace-notification-dialog');
    await expect(dialog.locator('summary')).toHaveCount(0);
    const list = page.getByRole('list', { name: 'Your notifications', exact: true });
    await expect(list.locator(':scope > li')).toHaveCount(2);
    await expect(list.getByRole('heading').first()).toHaveText('Course registration accepted');
    await expect(dialog.getByText('Admissions', { exact: true })).toHaveCount(0);
    await expect(dialog.getByText('Academic standing', { exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'View academic record', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'HIST.101 · Fictional <b>History</b>', exact: true })).toBeFocused();
    await expect(dialog).not.toContainText('Never display staff evidence');
    await expect(dialog.locator('b')).toHaveCount(0);
    await page.getByRole('button', { name: 'Back to notifications', exact: true }).click();
    await expect(page.getByRole('button', { name: 'View academic record', exact: true })).toBeFocused();
    await list.locator(':scope > li').first().getByRole('button', { name: 'Delete notification', exact: true }).click();
    await expect(list.locator(':scope > li')).toHaveCount(1);
    await expect(dialog).toContainText('Notification deleted.');
    expect(f.posts.map(post => post.action)).toEqual(['read', 'delete']);
    await page.keyboard.press('Escape'); await page.reload(); await f.open();
    await expect(page.getByRole('list', { name: 'Your notifications', exact: true }).locator(':scope > li')).toHaveCount(1);
    await page.setViewportSize({ width: 320, height: 900 });
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    const scan = await new AxeBuilder({ page }).include('.workspace-notification-dialog')
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('the merged feed pages through one busy source without skipping other types or duplicating rows', async ({ page }) => {
    const f = await setup(page);
    f.rows.set('enrollment', Array.from({ length: 25 }, (_, n) => notice(100 - n, 'registered')));
    f.rows.set('academic', [notice(200, 'posted', '2026-11-27T12:00:00.000000Z')]);
    await f.open();
    const list = page.getByRole('list', { name: 'Your notifications', exact: true });
    await expect(list.locator(':scope > li')).toHaveCount(20);
    const first = await list.locator(':scope > li').evaluateAll(items => items.map(item => item.dataset.notificationKey));
    await page.getByRole('button', { name: 'Older notifications', exact: true }).click();
    await expect(list.locator(':scope > li')).toHaveCount(6);
    const second = await list.locator(':scope > li').evaluateAll(items => items.map(item => item.dataset.notificationKey));
    expect(new Set([...first, ...second]).size).toBe(26);
    await page.getByRole('button', { name: 'Previous notifications', exact: true }).click();
    expect(await list.locator(':scope > li').evaluateAll(items => items.map(item => item.dataset.notificationKey))).toEqual(first);
    expect(f.errors).toEqual([]);
});

test('one unavailable source does not hide available notices and refresh recovers it', async ({ page }) => {
    const f = await setup(page);
    f.rows.set('enrollment', [notice(1, 'withdrawn')]); f.rows.set('academic', [notice(2, 'posted')]);
    f.fail('academic'); await f.open();
    await expect(page.getByText('Course withdrawal recorded', { exact: true })).toBeVisible();
    await expect(page.getByText('Some notifications could not load. Refresh to try again.', { exact: true })).toBeVisible();
    f.fail(null); await page.getByRole('button', { name: 'Refresh notifications', exact: true }).click();
    await expect(page.getByText('Final grade available', { exact: true })).toBeVisible();
    f.denyDetails(); await page.getByRole('button', { name: 'View academic record', exact: true }).click();
    await expect(page.getByText('Notifications are unavailable for this account.', { exact: true })).toBeFocused();
    await expect(page.locator('.notification-feed-card')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Back to notifications', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('an account with no notices sees one empty state and no category list', async ({ page }) => {
    const f = await setup(page, true); await f.open();
    await expect(page.getByText('You have no notifications.', { exact: true })).toBeVisible();
    await expect(page.locator('.workspace-notification-dialog summary')).toHaveCount(0);
    await expect(page.locator('.notification-feed-card')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Delete notification', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('Back is available during detail loading and a late result cannot acknowledge or replace the list', async ({ page }) => {
    const f = await setup(page);
    f.rows.set('academic', [notice(1, 'posted')]);
    let release, arrive;
    const gate = new Promise(resolve => { release = resolve; });
    const requested = new Promise(resolve => { arrive = resolve; });
    await page.route(`**/portal/api/academic-updates/${id(1)}/`, async route => {
        arrive(); await gate;
        await route.fulfill({ json: { course_code: 'PRIVATE', course_title: 'Late fictional detail',
            grade: 'B', term_code: 'FALL26', status: 'A', completed_milli: 3000 } });
    });
    await f.open();
    await page.getByRole('button', { name: 'View academic record', exact: true }).click();
    await requested;
    await page.getByRole('button', { name: 'Back to notifications', exact: true }).click();
    release();
    await expect(page.getByRole('list', { name: 'Your notifications', exact: true })).toBeVisible();
    await expect(page.getByText('Late fictional detail', { exact: false })).toHaveCount(0);
    expect(f.posts).toEqual([]);
    expect(f.errors).toEqual([]);
});

test('a repeated source continuation stops with one retry message instead of looping', async ({ page }) => {
    const f = await setup(page);
    let pages = 0;
    await page.route('**/portal/api/enrollment-updates/**', route => {
        const url = new URL(route.request().url());
        if (url.searchParams.get('include_read') === '1') pages++;
        return route.fulfill({ json: { items: Array.from({ length: 20 }, (_, n) => notice(100 - n, 'registered')),
            next: { at: '2026-11-26T12:00:00.000000Z', id: id(81) } } });
    });
    await f.open();
    await expect(page.locator('.notification-feed-card')).toHaveCount(20);
    await page.getByRole('button', { name: 'Older notifications', exact: true }).click();
    await expect(page.getByText('Some notifications could not load. Refresh to try again.', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Older notifications', exact: true })).toHaveCount(0);
    expect(pages).toBe(2);
    expect(f.errors).toEqual([]);
});
