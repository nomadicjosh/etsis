'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
async function fixture(page, { staff = false, count = 2 } = {}) {
    const requests = [], errors = [];
    let denied = false;
    let items = Array.from({ length: count }, (_, n) => ({ id: id(100 - n), revision: n + 2,
        status: n % 2 ? 'removed' : 'registered', unread: true, recorded_at: '2026-11-23T12:00:00.000000Z',
        course_code: 'HIST 101', section_code: `Fictional ${100 - n}`, term_code: 'FALL26',
        course_title: 'Fictional <b>world history</b>' }));
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', route => route.fulfill({ json: { cards: [], intake: [], pipeline: [], queue: [], items: [] } }));
    await page.route('**/portal/api/registration-updates/**', async route => {
        const req = route.request(), url = new URL(req.url()), notice = url.pathname.split('/')[4];
        requests.push({ path: url.pathname, method: req.method() });
        if (denied) return route.fulfill({ status: 403, json: { error: 'Fictional denied account' } });
        if (req.method() === 'POST') {
            expect(req.headers()['x-csrf-token']).toBe('fictional-test-token');
            const body = JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json'));
            const item = items.find(row => row.id === notice);
            expect(body).toEqual({ revision: item.revision });
            if (url.pathname.endsWith('/delete/')) {
                items = items.filter(row => row.id !== notice);
                return route.fulfill({ json: { deleted: true } });
            }
            item.unread = false;
            return route.fulfill({ json: { unread: false } });
        }
        const rows = items.filter(item => (url.searchParams.get('include_read') === '1' || item.unread)
            && (!url.searchParams.get('after') || item.id < url.searchParams.get('after')));
        return route.fulfill({ json: { items: rows.slice(0, 20), next: rows.length > 20
            ? { at: rows[19].recorded_at, id: rows[19].id } : null } });
    });
    await page.goto(staff ? '/admin/' : '/portal/');
    const name = staff ? 'Recent workspace notifications' : 'Recent notifications';
    const trigger = page.getByRole('button', { name, exact: true });
    await trigger.click();
    const dialog = page.getByRole('dialog', { name, exact: true });
    const disclosure = dialog.locator('summary').filter({ hasText: 'Registration updates' });
    const section = disclosure.locator('..');
    return { requests, errors, trigger, dialog, disclosure, section, deny: () => { denied = true; } };
}
for (const staff of [false, true]) test(`${staff ? 'staff' : 'portal'} reads and deletes saved updates without recreating feedback`, async ({ page }) => {
    const f = await fixture(page, { staff });
    expect(f.requests).toEqual([]);
    await f.disclosure.click();
    await expect(f.section.locator('li')).toHaveCount(2);
    await expect(f.section).toContainText('Fictional <b>world history</b>');
    await expect(f.section.locator('b')).toHaveCount(0);
    await expect(f.section.getByRole('button', { name: 'Mark as read', exact: true })).toHaveCount(0);
    await f.section.getByRole('button', { name: 'Delete update', exact: true }).first().click();
    await expect(f.section).toContainText('Registration update deleted.');
    await expect(f.section.locator('li')).toHaveCount(1);
    await expect(f.dialog.locator('.workspace-notification-entry')).toHaveCount(0);
    await page.keyboard.press('Escape'); await page.reload();
    await f.trigger.click(); await f.disclosure.click();
    await expect(f.section.locator('li')).toHaveCount(1);
    await expect(f.section).not.toContainText('Fictional 100');
    expect(f.requests.filter(row => row.method === 'POST').map(row => row.path))
        .toEqual([`/portal/api/registration-updates/${id(100)}/delete/`]);
    expect(await page.evaluate(() => ({ local: { ...localStorage }, session: { ...sessionStorage } })))
        .toEqual({ local: {}, session: {} });
    expect(f.errors).toEqual([]);
});
test('updates page in both directions and clear private content after access is denied', async ({ page }) => {
    const f = await fixture(page, { count: 25 });
    await f.disclosure.click();
    await expect(f.section.locator('li')).toHaveCount(20);
    await f.section.getByRole('button', { name: 'More updates', exact: true }).click();
    await expect(f.section.locator('li')).toHaveCount(5);
    await f.section.getByRole('button', { name: 'Previous updates', exact: true }).click();
    await expect(f.section.locator('li')).toHaveCount(20);
    f.deny();
    await f.disclosure.click(); await f.disclosure.click();
    await expect(f.dialog).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('Fictional <b>world history</b>');
    expect(f.errors).toEqual([]);
});
test('closing rejects a late update and controls reflow at 320px with enlarged text', async ({ page }) => {
    const f = await fixture(page);
    await f.disclosure.click();
    await expect(f.section.locator('li')).toHaveCount(2);
    let arrived, release;
    const requested = new Promise(resolve => { arrived = resolve; });
    const gate = new Promise(resolve => { release = resolve; });
    await page.route('**/portal/api/registration-updates/?*', async route => {
        arrived(); await gate; await route.fulfill({ json: { items: [], next: null } });
    });
    await f.disclosure.click(); await f.disclosure.click();
    await requested; await f.disclosure.click(); release();
    await expect(f.section.locator('.campus-announcements-body')).toBeEmpty();
    await page.unroute('**/portal/api/registration-updates/?*');
    await f.disclosure.click();
    await expect(f.section.locator('li')).toHaveCount(2);
    await page.screenshot({ path: '/tmp/etsis-registration-updates-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, target: item.nodes.map(node => node.target) }))).toEqual([]);
    expect(await f.dialog.evaluate(node => node.scrollWidth <= node.clientWidth)).toBe(true);
    await f.section.getByRole('button', { name: 'Delete update', exact: true }).first().scrollIntoViewIfNeeded();
    await page.screenshot({ path: '/tmp/etsis-registration-updates-phone.png' });
    await page.keyboard.press('Escape');
    await expect(f.trigger).toBeFocused();
    expect(f.errors).toEqual([]);
});
