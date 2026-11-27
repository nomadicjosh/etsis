'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const fixture = require('./helpers/notification-person.cjs');
const id = '01K00000000000000000000999';
for (const portal of [false, true]) test(`${portal ? 'student' : 'workspace'} opens current grade and permanently deletes its minimized notification`, async ({ page }) => {
    const f = await fixture(page, { portal });
    let removed = false, read = false;
    const calls = [];
    await page.route('**/portal/api/academic-updates/**', async route => {
        const req = route.request(), url = new URL(req.url()); calls.push(url.pathname);
        let result;
        if (url.pathname.endsWith('/delete/')) { removed = true; result = { deleted: true }; }
        else if (url.pathname.endsWith('/read/')) { read = true; result = { unread: false }; }
        else if (url.pathname === `/portal/api/academic-updates/${id}/`) result = {
            course_code: 'HIST.101', course_title: 'Fictional <b>History</b>', grade: 'B', term_code: 'FALL26', status: 'A', completed_milli: 3000,
        };
        else result = { items: removed || (read && url.searchParams.get('include_read') !== '1') ? [] : [{
            id, revision: 1, status: 'posted', unread: !read, recorded_at: '2026-11-25T12:00:00.000000Z',
            course_code: 'HIST.101', course_title: 'Fictional <b>History</b>', section_code: '01', term_code: 'FALL26',
        }], next: null };
        if (req.method() === 'POST') expect(req.headers()['x-csrf-token']).toBe('fictional-test-token');
        await route.fulfill({ json: result });
    });
    if (portal) await page.goto('/portal/student/profile/');
    await page.locator(portal ? '#campus-notifications-open' : '#workspace-notifications-open').click();

    await expect(page.getByText('Final grade available', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Mark as read', exact: true })).toHaveCount(0);
    await expect(page.getByLabel('Show read updates', { exact: true })).toBeHidden();
    await page.getByRole('button', { name: 'View academic record', exact: true }).click();
    await expect(page.getByText('Current final grade', { exact: true })).toBeVisible();
    await expect(page.getByText('B', { exact: true })).toBeVisible();
    await expect(page.locator('.workspace-notification-dialog b')).toHaveCount(0);
    await page.setViewportSize({ width: 320, height: 812 });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => v.id)).toEqual([]);
    await page.getByRole('button', { name: 'Delete notification', exact: true }).click();
    await expect(page.getByText('You have no notifications.', { exact: true })).toBeVisible();
    expect(calls.some(path => path.endsWith('/read/'))).toBe(true);
    expect(calls.some(path => path.endsWith('/delete/'))).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('closing academic notifications rejects late detail and sends no later read mutation', async ({ page }) => {
    const f = await fixture(page);
    let release, arrived; let reads = 0;
    const gate = new Promise(resolve => { release = resolve; });
    const requested = new Promise(resolve => { arrived = resolve; });
    await page.route('**/portal/api/academic-updates/**', async route => {
        const url = new URL(route.request().url());
        if (url.pathname.endsWith('/read/')) { reads++; return route.fulfill({ json: { unread: false } }); }
        if (url.pathname === `/portal/api/academic-updates/${id}/`) {
            arrived(); await gate;
            return route.fulfill({ json: { course_code: 'HIST.101', course_title: 'Late private academic record', grade: 'B', completed_milli: 3000, term_code: 'FALL26' } });
        }
        return route.fulfill({ json: { items: [{ id, revision: 1, status: 'posted', unread: true,
            course_code: 'HIST.101', course_title: 'Fictional History', section_code: '01', term_code: 'FALL26',
            recorded_at: '2026-11-25T12:00:00.000000Z' }], next: null } });
    });
    await page.locator('#workspace-notifications-open').click();

    await page.getByRole('button', { name: 'View academic record', exact: true }).click();
    await requested; await page.keyboard.press('Escape'); release();
    await page.waitForTimeout(50);
    await expect(page.locator('body')).not.toContainText('Late private academic record');
    expect(reads).toBe(0); expect(f.errors).toEqual([]);
});
