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
    await page.route('**/portal/api/**', route => route.fulfill({ json: { items: [], next: null } }));
    await page.route('**/sis/**', route => route.fulfill({ json: { cards: [], intake: [], pipeline: [], queue: [], items: [] } }));
    await page.route('**/portal/api/announcements/**', async route => {
        const req = route.request(), url = new URL(req.url());
        requests.push({ path: url.pathname, method: req.method() });
        if (denied) return route.fulfill({ status: 403, json: { error: 'Fictional denied request' } });
        if (fail && url.searchParams.get('include_read') === '1') { fail = false; return route.fulfill({ status: 500, json: { error: 'Fictional unavailable feed' } }); }
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
    return { dialog, trigger, reads, requests, errors,
        deny: () => { denied = true; }, conflict: () => { conflict = true; },
        add: () => { items.unshift({ id: id(101), title: 'Fictional new announcement', revision: 1, unread: true }); } };
}

const card = (f, number) => f.dialog.locator('.notification-feed-card').filter({ hasText: `Fictional announcement ${number}` });
const openAnnouncement = (f, number = 100) => card(f, number).getByRole('button', { name: 'Read announcement', exact: true }).click();
for (const staff of [false, true]) test(`${staff ? 'staff' : 'portal'} opens an announcement and acknowledges only the displayed revision`, async ({ page }) => {
    const f = await fixture(page, { staff });
    expect(f.requests.filter(row => row.method === 'POST')).toEqual([]);
    await openAnnouncement(f);
    await expect(f.dialog).toContainText('Fictional <b>literal</b> announcement content.');
    await expect(f.dialog.locator('.campus-announcement-content b')).toHaveCount(0);
    await expect(f.dialog.getByRole('button', { name: 'Mark as read', exact: true })).toHaveCount(0);
    expect(f.reads).toEqual([{ revision: 1 }]);
    await f.dialog.getByRole('button', { name: 'Back to notifications', exact: true }).click();
    await expect(card(f, 100).getByRole('button', { name: 'Read announcement', exact: true })).toBeFocused();
    await f.dialog.getByRole('button', { name: 'Refresh notifications', exact: true }).click();
    await expect(card(f, 100)).toBeVisible();
    await page.keyboard.press('Escape'); await expect(f.trigger).toBeFocused();
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});
test('announcement deletion from list and detail survives reopening and reload', async ({ page }) => {
    const f = await fixture(page);
    await card(f, 100).getByRole('button', { name: 'Delete notification', exact: true }).click();
    await expect(card(f, 100)).toHaveCount(0);
    await openAnnouncement(f, 99);
    await f.dialog.getByRole('button', { name: 'Delete notification', exact: true }).click();
    await expect(f.dialog).toContainText('You have no notifications.');
    await page.keyboard.press('Escape'); await page.reload(); await f.trigger.click();
    await expect(f.dialog).toContainText('You have no notifications.');
    expect(f.requests.filter(row => row.method === 'POST').map(row => row.path)).toEqual([
        `/portal/api/announcements/${id(100)}/delete/`, `/portal/api/announcements/${id(99)}/read/`,
        `/portal/api/announcements/${id(99)}/delete/`,
    ]);
    expect(f.errors).toEqual([]);
});
test('announcements use shared paging and recover a source failure without duplicate feedback', async ({ page }) => {
    const f = await fixture(page, { count: 25, failed: true });
    await expect(f.dialog).toContainText('Some notifications could not load. Refresh to try again.');
    await f.dialog.getByRole('button', { name: 'Refresh notifications', exact: true }).click();
    await expect(f.dialog.locator('.notification-feed-card')).toHaveCount(20);
    await f.dialog.getByRole('button', { name: 'Older notifications', exact: true }).click();
    await expect(f.dialog.locator('.notification-feed-card')).toHaveCount(5);
    await f.dialog.getByRole('button', { name: 'Previous notifications', exact: true }).click();
    await expect(f.dialog.locator('.notification-feed-card')).toHaveCount(20);
    f.add(); await f.dialog.getByRole('button', { name: 'Refresh notifications', exact: true }).click();
    await expect(f.dialog).toContainText('Fictional new announcement');
    await expect(f.dialog.locator('.workspace-notification-entry')).toHaveCount(0);
    expect(f.reads).toEqual([]); expect(f.errors).toEqual([]);
});
test('stale announcement acknowledgement returns to the list and denied refresh clears its metadata', async ({ page }) => {
    const f = await fixture(page); f.conflict(); await openAnnouncement(f);
    await expect(f.dialog).toContainText('This notification changed or is unavailable. Refresh to try again.');
    await expect(card(f, 100)).toBeVisible();
    f.deny(); await f.dialog.getByRole('button', { name: 'Refresh notifications', exact: true }).click();
    await expect(f.dialog).toContainText('You have no notifications.');
    await expect(f.dialog).not.toContainText('Fictional <b>literal</b> announcement content.');
    expect(f.errors).toEqual([]);
});
test('Back during announcement loading rejects its late private content and read mutation', async ({ page }) => {
    const f = await fixture(page); let arrive, release;
    const requested = new Promise(resolve => { arrive = resolve; });
    const gate = new Promise(resolve => { release = resolve; });
    await page.route(`**/portal/api/announcements/${id(100)}/`, async route => {
        arrive(); await gate; await route.fulfill({ json: { id: id(100), revision: 1,
            title: 'Fictional late detail', content: 'Fictional late private text.', content_format: 'plain', unread: true } });
    });
    await openAnnouncement(f); await requested;
    await f.dialog.getByRole('button', { name: 'Back to notifications', exact: true }).click(); release();
    await expect(card(f, 100)).toBeVisible();
    await expect(f.dialog).not.toContainText('Fictional late private text.');
    await page.keyboard.press('Escape'); await f.trigger.click();
    await expect(card(f, 100)).toBeVisible(); expect(f.reads).toEqual([]); expect(f.errors).toEqual([]);
});
test('formatted announcement content and shared controls remain safe and accessible at narrow widths', async ({ page }) => {
    const f = await fixture(page, { rich: true });
    await card(f, 100).getByRole('button', { name: 'Read announcement', exact: true }).press('Enter');
    await expect(f.dialog.locator('.campus-announcement-content strong')).toHaveText('formatted notice');
    await expect(f.dialog.getByRole('link', { name: 'Guide', exact: true })).toHaveAttribute('href', 'https://example.invalid/guide');
    await expect(f.dialog.locator('.campus-announcement-content img, .campus-announcement-content script')).toHaveCount(0);
    expect(await page.evaluate(() => window.fictionalAttack)).toBeUndefined();
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    expect(await f.dialog.evaluate(node => node.scrollWidth <= node.clientWidth)).toBe(true);
    await page.keyboard.press('Escape'); await expect(f.trigger).toBeFocused(); expect(f.errors).toEqual([]);
});
