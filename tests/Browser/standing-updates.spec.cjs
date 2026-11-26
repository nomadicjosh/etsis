'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const fixture = require('./helpers/notification-person.cjs');
const notice = '01K00000000000000000000999';
const at = '2026-11-26T12:00:00.000000Z';
const row = { id: notice, revision: 1, status: 'recorded', unread: true, recorded_at: at,
    program_code: 'ARTS.UG', program_title: 'Fictional <b>Arts</b>' };
const detail = { program_code: 'ARTS.UG', program_title: row.program_title,
    pursuit_status: 'active', as_of: '2026-08-10', recorded_at: at, items: [
        { code: 'GOOD', title: 'Fictional <b>standing</b>', starts_on: '2026-08-01', ends_on: '2026-08-31' },
        { code: 'REVIEW', title: 'Fictional future period', starts_on: '2026-09-01', ends_on: null }
    ] };
async function open(page, portal) {
    if (portal) await page.goto('/portal/student/profile/');
    await page.locator(portal ? '#campus-notifications-open' : '#workspace-notifications-open').click();
    await page.getByText('Academic standing updates', { exact: true }).click();
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).include('.workspace-notification-dialog')
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}
for (const portal of [false, true]) test(`${portal ? 'portal' : 'workspace'} opens retained standing, keeps Back and physically deletes the notice`, async ({ page }) => {
    const f = await fixture(page, { portal });
    let removed = false, read = false;
    const posts = [];
    await page.route('**/portal/api/standing-updates/**', async route => {
        const request = route.request(), url = new URL(request.url());
        let result;
        if (url.pathname.endsWith('/delete/')) { removed = true; result = { deleted: true }; }
        else if (url.pathname.endsWith('/read/')) { read = true; result = { unread: false }; }
        else if (url.pathname === `/portal/api/standing-updates/${notice}/`) result = {
            ...detail, pursuit_status: portal ? 'closed' : 'active', reason: 'Never render staff evidence'
        };
        else result = { items: removed ? [] : [{ ...row, unread: !read, status: portal ? 'corrected' : 'recorded' }], next: null };
        if (request.method() === 'POST') {
            posts.push(url.pathname);
            expect(request.headers()['x-csrf-token']).toBe('fictional-test-token');
            const payload = JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json'));
            expect(payload).toEqual({ revision: 1 });
        }
        await route.fulfill({ json: result });
    });
    await open(page, portal);
    await expect(page.getByText(portal ? 'Historical academic standing corrected' : 'Academic standing updated', { exact: true })).toBeVisible();
    const dialog = page.locator('.workspace-notification-dialog');
    await expect(dialog.getByRole('button', { name: 'Mark as read', exact: true })).toHaveCount(0);
    await expect(dialog.getByLabel('Show read updates', { exact: true })).toBeHidden();
    await expect(dialog.getByText('Notification preferences', { exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'View academic standing', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Recorded academic standing', exact: true })).toBeFocused();
    await expect(dialog.getByText('GOOD · Fictional <b>standing</b>', { exact: true })).toBeVisible();
    await expect(dialog).toContainText('2026-09-01');
    await expect(dialog).toContainText('No end date');
    if (portal) await expect(dialog).toContainText('This is a historical program pursuit.');
    await expect(dialog).not.toContainText('Never render staff evidence');
    await expect(dialog.locator('b')).toHaveCount(0);
    const back = page.getByRole('button', { name: 'Back to standing updates', exact: true });
    await expect(back.locator('i.bi')).toHaveCount(1);
    await back.click();
    await expect(page.getByRole('button', { name: 'View academic standing', exact: true })).toBeVisible();
    await expect(dialog.getByText('Read', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'View academic standing', exact: true }).click();
    await page.setViewportSize({ width: 320, height: 900 });
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await accessible(page);
    if (portal) await page.screenshot({ path: '/tmp/etsis-standing-phone.png' });
    await page.getByRole('button', { name: 'Delete update', exact: true }).click();
    await expect(dialog.getByText('There are no academic standing updates.', { exact: true })).toBeVisible();
    expect(posts.some(path => path.endsWith('/read/'))).toBe(true);
    expect(posts.some(path => path.endsWith('/delete/'))).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('a detached standing detail never renders or marks its notice read', async ({ page }) => {
    const f = await fixture(page);
    let release, started, reads = 0;
    const pending = new Promise(resolve => { release = resolve; });
    const loading = new Promise(resolve => { started = resolve; });
    await page.route('**/portal/api/standing-updates/**', async route => {
        const path = new URL(route.request().url()).pathname;
        if (path.endsWith('/read/')) { reads++; return route.fulfill({ json: { unread: false } }); }
        if (path === `/portal/api/standing-updates/${notice}/`) {
            started(); await pending;
            return route.fulfill({ json: { ...detail, program_title: 'Late private standing' } });
        }
        return route.fulfill({ json: { items: [row], next: null } });
    });
    await open(page, false);
    await page.getByRole('button', { name: 'View academic standing', exact: true }).click();
    await loading;
    await page.keyboard.press('Escape');
    release();
    await expect(page.locator('.workspace-notification-dialog')).toHaveCount(0);
    await expect(page.getByText('Late private standing', { exact: true })).toHaveCount(0);
    expect(reads).toBe(0);
    expect(f.errors).toEqual([]);
});

test('access denial clears standing notice metadata and withholds returned private fields', async ({ page }) => {
    const f = await fixture(page);
    await page.route('**/portal/api/standing-updates/**', route => {
        const path = new URL(route.request().url()).pathname;
        return path === `/portal/api/standing-updates/${notice}/`
            ? route.fulfill({ status: 403, json: { error: 'Denied', title: 'Forbidden private period' } })
            : route.fulfill({ json: { items: [row], next: null } });
    });
    await open(page, false);
    await page.getByRole('button', { name: 'View academic standing', exact: true }).click();
    await expect(page.locator('.workspace-notification-dialog')).toHaveCount(0);
    await expect(page.locator('[data-update-id]')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('Forbidden private period');
    await expect(page.locator('body')).not.toContainText(row.program_title);
    expect(f.errors).toEqual([]);
});

test('standing detail Back retains its bounded notification page and explains a cleared determination', async ({ page }) => {
    const f = await fixture(page);
    const id = index => String(index).padStart(26, '0');
    const first = Array.from({ length: 20 }, (_, index) => ({ ...row, id: id(21 - index) }));
    let pageTwo = false;
    await page.route('**/portal/api/standing-updates/**', route => {
        const request = route.request(), url = new URL(request.url());
        if (url.pathname.endsWith('/read/')) return route.fulfill({ json: { unread: false } });
        if (url.pathname === `/portal/api/standing-updates/${id(1)}/`) return route.fulfill({ json: { ...detail, items: [] } });
        pageTwo = !!url.searchParams.get('after');
        if (pageTwo) {
            expect(url.searchParams.get('after')).toBe(id(2));
            expect(url.searchParams.get('after_at')).toBe(at);
        }
        return route.fulfill({ json: { items: pageTwo ? [{ ...row, id: id(1) }] : first,
            next: pageTwo ? null : { at, id: id(2) } } });
    });
    await open(page, false);
    await expect(page.locator('[data-update-id]')).toHaveCount(20);
    await page.getByRole('button', { name: 'More updates', exact: true }).click();
    await expect(page.locator('[data-update-id]')).toHaveCount(1);
    await page.getByRole('button', { name: 'View academic standing', exact: true }).click();
    await expect(page.getByText('No academic standing periods are recorded.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Back to standing updates', exact: true }).click();
    await expect(page.locator('[data-update-id]')).toHaveAttribute('data-update-id', id(1));
    await page.getByRole('button', { name: 'Previous updates', exact: true }).click();
    await expect(page.locator('[data-update-id]')).toHaveCount(20);
    expect(f.errors).toEqual([]);
});
