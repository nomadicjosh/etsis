'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const installAudio = require('./helpers/notification-audio.cjs');
async function fixture(page, options = {}) {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await installAudio(page, options);
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        if (options.denied && path.includes('/data/people/')) return route.fulfill({ status: 403,
            json: { error: 'Access denied.' }, headers: { 'Cache-Control': 'private, no-store' } });
        const result = path === '/sis/ui/metrics/' ? { cards: [], intake: [], pipeline: [], queue: [] }
            : { items: [], next_after: null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await expect.poll(() => page.evaluate(() => !!window.testNotifications)).toBe(true);
    return { errors };
}
const trigger = page => page.getByRole('button', { name: 'Recent workspace notifications', exact: true });
const dialog = page => page.getByRole('dialog', { name: 'Recent workspace notifications', exact: true });
async function emit(page, message, tone = 'success') {
    await page.evaluate(({ message, tone }) => window.testNotifications.notify(message, { tone }), { message, tone });
}
test('deleting recent feedback removes its message and toast and keeps a usable focus target', async ({ page }) => {
    const f = await fixture(page);
    await emit(page, 'Fictional removable feedback.');
    await trigger(page).click();
    await dialog(page).getByRole('button', { name: 'Delete notification', exact: true }).click();
    await expect(dialog(page).locator('.workspace-notification-entry')).toHaveCount(0);
    await expect(page.locator('.workspace-notification-toast')).toHaveCount(0);
    await expect(dialog(page).getByText('Notification deleted.', { exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await trigger(page).click();
    await expect(dialog(page)).not.toContainText('Fictional removable feedback.');
    expect(await page.evaluate(() => ({ local: { ...localStorage }, session: { ...sessionStorage } })))
        .toEqual({ local: {}, session: {} });
    expect(f.errors).toEqual([]);
});
test('bounded Bootstrap toasts retain literal messages without moving keyboard focus', async ({ page }) => {
    const f = await fixture(page);
    await trigger(page).focus();
    await emit(page, 'Fictional <img src=x onerror=alert(1)> saved.');
    await expect(trigger(page)).toBeFocused();
    await expect(page.locator('#workspace-status')).toContainText('Fictional <img src=x onerror=alert(1)> saved.');
    await expect(page.locator('#workspace-status img')).toHaveCount(0);
    await page.evaluate(() => { for (let n = 1; n <= 30; n++) window.testNotifications.notify(`Fictional notice ${n}`); });
    await expect(page.locator('.workspace-notification-toast')).toHaveCount(5);
    await trigger(page).click();
    await expect(dialog(page).locator('.workspace-notification-entry')).toHaveCount(20);
    await expect(dialog(page)).toContainText('Fictional notice 30');
    await expect(dialog(page)).not.toContainText('Fictional notice 10');
    await page.evaluate(() => window.testNotifications.clear(false));
    await expect(dialog(page)).toContainText('You have no notifications.');
    await expect(page.locator('.workspace-notification-toast')).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(trigger(page)).toBeFocused();
    expect(f.errors).toEqual([]);
});
test('access denial clears visible feedback and private session history', async ({ page }) => {
    const f = await fixture(page, { denied: true });
    await emit(page, 'Fictional earlier private feedback.');
    await page.locator('.workspace-nav-link[data-workspace="people"]').click();
    await page.getByRole('searchbox', { name: 'Search this workspace', exact: true }).fill('Fictional');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.locator('#workspace-alert')).toBeVisible();
    await expect(page.locator('.workspace-notification-toast')).toHaveCount(0);
    await trigger(page).click();
    await expect(dialog(page)).not.toContainText('Fictional earlier private feedback.');
    expect(f.errors).toEqual([]);
});
test('feedback stays in the active drawer or native dialog and cleans up after closure', async ({ page }) => {
    const f = await fixture(page);
    await page.evaluate(() => {
        const drawer = document.getElementById('record-drawer');
        drawer.querySelector('h2').textContent = 'Fictional editor';
        window.bootstrap.Offcanvas.getOrCreateInstance(drawer).show();
    });
    await expect(page.locator('#record-drawer')).toHaveClass(/show/);
    await emit(page, 'Fictional drawer saved.');
    expect(await page.locator('#workspace-status').evaluate(node => node.parentElement.id)).toBe('record-drawer');
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await expect(page.locator('#record-drawer')).not.toHaveClass(/show/);
    await page.evaluate(() => {
        const modal = document.createElement('dialog');
        modal.id = 'fictional-native-dialog'; modal.style.cssText = 'width:600px;height:400px;max-width:90vw';
        const heading = document.createElement('h2'); heading.textContent = 'Fictional report';
        modal.append(heading); document.body.append(modal); modal.showModal();
    });
    await emit(page, 'Fictional export prepared.');
    expect(await page.locator('#workspace-status').evaluate(node => node.parentElement.id)).toBe('fictional-native-dialog');
    const boxes = await page.locator('#workspace-status').evaluate(node => ({
        stack: node.getBoundingClientRect().toJSON(), parent: node.parentElement.getBoundingClientRect().toJSON(),
    }));
    expect(boxes.stack.right).toBeLessThanOrEqual(boxes.parent.right);
    expect(boxes.stack.left).toBeGreaterThanOrEqual(boxes.parent.left);
    await page.evaluate(() => document.getElementById('fictional-native-dialog').remove());
    await expect(page.locator('#workspace-status')).toBeVisible();
    expect(f.errors).toEqual([]);
});
test('notifications support narrow enlarged-text views and keyboard dismissal', async ({ page }) => {
    const f = await fixture(page);
    await emit(page, 'Fictional saved changes can be reviewed in their workspace.');
    await page.screenshot({ path: '/tmp/etsis-notifications-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    await trigger(page).click();
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: '/tmp/etsis-notifications-phone.png' });
    await page.keyboard.press('Escape');
    await expect(trigger(page)).toBeFocused();
    expect(f.errors).toEqual([]);
});
test('shipped sound files decode locally without playing any device audio', async ({ page }) => {
    await fixture(page);
    const results = await page.evaluate(async () => {
        const context = new OfflineAudioContext(2, 1, 44100), results = [];
        for (const name of ['message-notification-4.wav', 'warm-success-notification.wav',
            'bird_notification.mp3', 'piano-notification-5b.mp3']) {
            const response = await fetch(`/assets/sounds/${name}`, { credentials: 'omit' });
            const bytes = await response.arrayBuffer();
            const buffer = await context.decodeAudioData(bytes);
            results.push({ name, bytes: bytes.byteLength, duration: buffer.duration });
        }
        return results;
    });
    expect(results).toHaveLength(4);
    for (const result of results) {
        expect(result.bytes).toBeLessThan(2 * 1024 * 1024);
        expect(result.duration).toBeGreaterThan(0);
        expect(result.duration).toBeLessThan(8);
    }
});
test('drawer feedback reserves space so the next action remains visible and clickable', async ({page}) => {
    const f = await fixture(page);
    await page.setViewportSize({width: 375, height: 812});
    await page.evaluate(() => {
        const drawer = document.getElementById('record-drawer');
        drawer.querySelector('h2').textContent = 'Fictional workflow';
        const body = document.getElementById('drawer-body');
        const spacer = document.createElement('div'); spacer.style.height = '1200px';
        const next = document.createElement('button'); next.type = 'button'; next.className = 'btn btn-primary';
        next.textContent = 'Continue fictional workflow';
        next.addEventListener('click', () => { next.textContent = 'Fictional workflow continued'; });
        body.append(spacer, next);
        window.bootstrap.Offcanvas.getOrCreateInstance(drawer).show();
    });
    await expect(page.locator('#record-drawer')).toHaveClass(/show/);
    await emit(page, 'Fictional saved draft.');
    await page.getByRole('button', {name: 'Continue fictional workflow', exact: true}).click();
    await expect(page.getByRole('button', {name: 'Fictional workflow continued', exact: true})).toBeVisible();
    const bounds = await page.evaluate(() => ({
        action: document.querySelector('#drawer-body button').getBoundingClientRect().toJSON(),
        stack: document.getElementById('workspace-status').getBoundingClientRect().toJSON(),
    }));
    expect(bounds.action.bottom).toBeLessThanOrEqual(bounds.stack.top);
    await expect(page.locator('.workspace-notification-toast')).toBeVisible();
    await page.locator('.workspace-notification-toast .btn-close').click();
    await expect(page.locator('.workspace-notification-toast')).toHaveCount(0);
    expect(f.errors).toEqual([]);
});
