'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const installAudio = require('./helpers/notification-audio.cjs');
const id = n => String(n).padStart(26, '0');
async function fixture(page, { staff = false, count = 2, failed = false, rich = false } = {}) {
    const errors = [], reads = [], requests = [];
    let items = Array.from({ length: count }, (_, n) => ({ id: id(100 - n), revision: 1,
        title: `Fictional announcement ${100 - n}`, unread: true }));
    let denied = false, conflict = false, fail = failed;
    page.on('pageerror', error => errors.push(error.message));
    await installAudio(page);
    await page.route('**/sis/**', route => route.fulfill({ json: { cards: [], intake: [], pipeline: [], queue: [], items: [] } }));
    await page.route('**/portal/api/announcements/**', async route => {
        const req = route.request(), url = new URL(req.url());
        requests.push({ path: url.pathname, method: req.method() });
        if (denied) return route.fulfill({ status: 403, json: { error: 'Fictional denied request' } });
        if (fail) { fail = false; return route.fulfill({ status: 500, json: { error: 'Fictional unavailable feed' } }); }
        const postId = url.pathname.split('/')[4];
        let json;
        if (!postId) {
            const rows = items.filter(item => (url.searchParams.get('include_read') === '1' || item.unread)
                && (!url.searchParams.get('after') || item.id < url.searchParams.get('after')));
            json = { items: rows.slice(0, 20), next_after: rows.length > 20 ? rows[19].id : null };
        } else {
            const item = items.find(row => row.id === postId);
            if (req.method() === 'POST') {
                expect(req.headers()['x-csrf-token']).toBe('fictional-test-token');
                const body = JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json'));
                reads.push(body);
                if (conflict) return route.fulfill({ status: 409, json: { error: 'Fictional stale revision' } });
                expect(body).toEqual({ revision: item.revision });
                if (url.pathname.endsWith('/delete/')) {
                    items = items.filter(row => row.id !== postId); json = { deleted: true };
                } else { item.unread = false; json = { unread: false }; }
            } else json = { ...item, content: 'Fictional <b>literal</b> announcement content.',
                content_format: rich ? 'html' : 'plain',
                content_html: rich ? '<p>Fictional <strong>formatted notice</strong> <a href="https://example.invalid/guide">Guide</a></p><img src=x onerror=alert(1)><script>window.fictionalAttack=1</script>' : null };
        }
        await route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(staff ? '/admin/' : '/portal/');
    const trigger = page.getByRole('button', { name: staff ? 'Recent workspace notifications' : 'Recent notifications', exact: true });
    await trigger.click();
    const dialog = page.getByRole('dialog', { name: staff ? 'Recent workspace notifications' : 'Recent notifications', exact: true });
    const disclosure = dialog.locator('summary').filter({ hasText: 'Campus announcements' });
    return { dialog, trigger, disclosure, reads, requests, errors,
        deny: () => { denied = true; }, conflict: () => { conflict = true; },
        add: () => { items.unshift({ id: id(101), title: 'Fictional new announcement', revision: 1, unread: true }); } };
}
for (const staff of [false, true]) test(`${staff ? 'staff' : 'portal'} reader opens announcements lazily and marks only the displayed revision`, async ({ page }) => {
    const f = await fixture(page, { staff });
    expect(f.requests).toEqual([]);
    await f.disclosure.click();
    await f.dialog.getByRole('button', { name: 'Fictional announcement 100', exact: true }).click();
    await expect(f.dialog).toContainText('Fictional <b>literal</b> announcement content.');
    await expect(f.dialog.locator('.campus-announcement-content b')).toHaveCount(0);
    await expect(f.dialog.getByRole('button', { name: 'Mark as read', exact: true })).toHaveCount(0);
    expect(f.reads).toEqual([{ revision: 1 }]);
    await f.dialog.getByRole('button', { name: 'Back to list', exact: true }).click();
    await expect(f.dialog.getByRole('button', { name: 'Fictional announcement 100', exact: true })).toBeVisible();
    await f.dialog.getByRole('button', { name: 'Clear recent notifications', exact: true }).click();
    await expect(f.dialog.getByRole('button', { name: 'Fictional announcement 100', exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(f.trigger).toBeFocused();
    expect(await page.evaluate(() => ({ local: { ...localStorage }, session: { ...sessionStorage } })))
        .toEqual({ local: {}, session: {} });
    expect(f.errors).toEqual([]);
});
test('deleting an announcement from either list or detail stays deleted after reopening and reload', async ({ page }) => {
    const f = await fixture(page);
    await f.disclosure.click();
    const first = f.dialog.locator('.campus-announcement-list > li').first();
    await first.getByRole('button', { name: 'Delete announcement', exact: true }).click();
    await expect(f.dialog).not.toContainText('Fictional announcement 100');
    await f.dialog.getByRole('button', { name: 'Fictional announcement 99', exact: true }).click();
    await f.dialog.getByRole('button', { name: 'Delete announcement', exact: true }).click();
    await expect(f.dialog).toContainText('There are no matching campus announcements.');
    await page.keyboard.press('Escape');
    await page.reload();
    await f.trigger.click(); await f.disclosure.click();
    await expect(f.dialog).toContainText('There are no matching campus announcements.');
    expect(f.requests.filter(row => row.method === 'POST').map(row => row.path))
        .toEqual([`/portal/api/announcements/${id(100)}/delete/`, `/portal/api/announcements/${id(99)}/read/`, `/portal/api/announcements/${id(99)}/delete/`]);
    expect(f.errors).toEqual([]);
});
test('announcements page both ways, retry a failed feed and reopen without duplicate feedback', async ({ page }) => {
    const f = await fixture(page, { count: 25, failed: true });
    await f.disclosure.click();
    await expect(f.dialog).toContainText('These notifications are unavailable. Reopen this section to try again.');
    await f.disclosure.click(); await f.disclosure.click();
    await expect(f.dialog.locator('.campus-announcement-list > li')).toHaveCount(20);
    await f.dialog.getByRole('button', { name: 'More announcements', exact: true }).click();
    await expect(f.dialog.locator('.campus-announcement-list > li')).toHaveCount(5);
    await f.dialog.getByRole('button', { name: 'Previous announcements', exact: true }).click();
    await expect(f.dialog.locator('.campus-announcement-list > li')).toHaveCount(20);
    await expect(f.dialog.locator('.workspace-notification-entry')).toHaveCount(0);
    f.add();
    await f.disclosure.click(); await f.disclosure.click();
    await expect(f.dialog).toContainText('Fictional new announcement');
    await f.disclosure.click(); await f.disclosure.click();
    await expect(f.dialog.locator('.workspace-notification-entry')).toHaveCount(0);
    expect(f.reads).toEqual([]);
    expect(f.errors).toEqual([]);
});
test('stale read confirmation keeps retry controls and denial clears private content', async ({ page }) => {
    const f = await fixture(page);
    await f.disclosure.click();
    f.conflict();
    await f.dialog.getByRole('button', { name: 'Fictional announcement 100', exact: true }).click();
    await expect(f.dialog).toContainText('These notifications are unavailable. Reopen this section to try again.');
    f.deny();
    await f.disclosure.click(); await f.disclosure.click();
    await expect(f.dialog).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('Fictional <b>literal</b> announcement content.');
    expect(f.errors).toEqual([]);
});
test('closing the announcement disclosure rejects a late private response', async ({ page }) => {
    const f = await fixture(page);
    await f.disclosure.click();
    await expect(f.dialog.getByRole('button', { name: 'Fictional announcement 100', exact: true })).toBeVisible();
    let arrived, release;
    const requested = new Promise(resolve => { arrived = resolve; });
    const gate = new Promise(resolve => { release = resolve; });
    await page.route(`**/portal/api/announcements/${id(100)}/`, async route => {
        arrived(); await gate; await route.fulfill({ json: { id: id(100), revision: 1,
            title: 'Fictional late detail', content: 'Fictional late private text.', content_format: 'plain', unread: true } });
    });
    await f.dialog.getByRole('button', { name: 'Fictional announcement 100', exact: true }).click();
    await requested;
    await f.disclosure.click(); release();
    await expect(f.disclosure.locator('..').locator('.campus-announcements-body')).toBeEmpty();
    await expect(f.dialog).not.toContainText('Fictional late private text.');
    const oldDisclosure = await f.disclosure.locator('..').elementHandle();
    await page.keyboard.press('Escape');
    await f.trigger.click();
    await f.disclosure.click();
    await expect(f.dialog.getByRole('button', { name: 'Fictional announcement 100', exact: true })).toBeVisible();
    await oldDisclosure.evaluate(element => element.dispatchEvent(new Event('toggle')));
    await expect(f.dialog.getByRole('button', { name: 'Fictional announcement 100', exact: true })).toBeVisible();
    expect(f.reads).toEqual([]);
    expect(f.errors).toEqual([]);
});
test('safe rich content and announcement controls reflow with keyboard and enlarged text', async ({ page }) => {
    const f = await fixture(page, { rich: true });
    await f.disclosure.click();
    await f.dialog.getByRole('button', { name: 'Fictional announcement 100', exact: true }).click();
    await expect(f.dialog.locator('.campus-announcement-content strong')).toHaveText('formatted notice');
    await expect(f.dialog.getByRole('link', { name: 'Guide', exact: true })).toHaveAttribute('href', 'https://example.invalid/guide');
    await expect(f.dialog.locator('.campus-announcement-content img, .campus-announcement-content script')).toHaveCount(0);
    expect(await page.evaluate(() => window.fictionalAttack)).toBeUndefined();
    await page.screenshot({ path: '/tmp/etsis-announcements-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, target: item.nodes.map(node => node.target) }))).toEqual([]);
    await page.screenshot({ path: '/tmp/etsis-announcements-phone.png' });
    expect(await f.dialog.evaluate(node => node.scrollWidth <= node.clientWidth)).toBe(true);
    await f.dialog.getByRole('button', { name: 'Delete announcement', exact: true }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: '/tmp/etsis-announcements-phone.png' });
    await page.keyboard.press('Escape');
    await expect(f.trigger).toBeFocused();
    expect(f.errors).toEqual([]);
});
