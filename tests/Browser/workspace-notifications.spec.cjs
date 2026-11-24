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
    await expect(dialog(page).getByRole('button', { name: 'Close notifications', exact: true })).toBeFocused();
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
    await page.getByRole('button', { name: 'Clear recent notifications', exact: true }).click();
    await expect(dialog(page)).toContainText('There are no notifications from this workspace session.');
    await expect(page.locator('.workspace-notification-toast')).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(trigger(page)).toBeFocused();
    expect(f.errors).toEqual([]);
});
test('automatic dismissal retains history and keep-visible preference preserves feedback', async ({ page }) => {
    await page.clock.install();
    const f = await fixture(page);
    await emit(page, 'Fictional automatic feedback.');
    await page.clock.fastForward(8100);
    await expect(page.locator('.workspace-notification-toast')).toHaveCount(0);
    await trigger(page).click();
    await expect(dialog(page)).toContainText('Fictional automatic feedback.');
    await page.getByLabel('Keep notifications on screen until dismissed', { exact: true }).check();
    await page.keyboard.press('Escape');
    await emit(page, 'Fictional pinned feedback.');
    await page.clock.fastForward(20000);
    await expect(page.locator('.workspace-notification-toast')).toContainText('Fictional pinned feedback.');
    await page.getByRole('button', { name: 'Dismiss notification', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('.workspace-notification-toast')).toHaveCount(0);
    await expect(trigger(page)).toBeFocused();
    expect(f.errors).toEqual([]);
});
test('sound is opt-in, rate-limited and muted independently of visual feedback', async ({ page }) => {
    await page.clock.install();
    const f = await fixture(page);
    await emit(page, 'Fictional silent feedback.');
    expect(await page.evaluate(() => window.testAudioContexts)).toBe(0);
    await trigger(page).click();
    await page.getByLabel('Play notification sounds', { exact: true }).check();
    await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
    expect(await page.evaluate(() => window.testTones)).toBe(1);
    await page.keyboard.press('Escape');
    await page.clock.fastForward(2100);
    await emit(page, 'Fictional audible feedback.');
    await emit(page, 'Fictional burst feedback.');
    expect(await page.evaluate(() => window.testTones)).toBe(2);
    await trigger(page).click();
    await page.getByLabel('Play notification sounds', { exact: true }).uncheck();
    await page.keyboard.press('Escape');
    await page.clock.fastForward(2100);
    await emit(page, 'Fictional muted feedback.');
    expect(await page.evaluate(() => window.testTones)).toBe(2);
    expect(await page.evaluate(() => window.testClosedAudio)).toBeGreaterThan(0);
    const stored = await page.evaluate(() => JSON.parse(localStorage.getItem('etsis.workspace.notification-preferences')));
    expect(stored).toEqual({ sound: false, keep_visible: false, sound_id: 'tone' });
    expect(f.errors).toEqual([]);
});
test('blocked sound and browser storage leave visual feedback usable', async ({ page }) => {
    const f = await fixture(page, { blocked: true });
    await page.evaluate(() => Object.defineProperty(window, 'localStorage', {
        get() { throw new DOMException('Fictional disabled storage', 'SecurityError'); },
    }));
    await trigger(page).click();
    await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
    await expect(dialog(page)).toContainText('Sound is unavailable. Visual notifications will still appear.');
    await page.keyboard.press('Escape');
    await emit(page, 'Fictional feedback still works.');
    await expect(page.locator('#workspace-status')).toContainText('Fictional feedback still works.');
    expect(f.errors).toEqual([]);
});
test('only preferences survive reload and private recent messages do not', async ({ page }) => {
    const f = await fixture(page);
    await trigger(page).click();
    await page.getByLabel('Keep notifications on screen until dismissed', { exact: true }).check();
    await page.keyboard.press('Escape');
    await emit(page, 'Fictional private student feedback.');
    const storage = await page.evaluate(() => ({ local: { ...localStorage }, session: { ...sessionStorage } }));
    expect(storage).toEqual({ local: { 'etsis.workspace.notification-preferences':
        JSON.stringify({ sound: false, keep_visible: true, sound_id: 'tone' }) }, session: {} });
    await page.reload();
    await trigger(page).click();
    await expect(dialog(page)).not.toContainText('Fictional private student feedback.');
    await expect(dialog(page)).toContainText('There are no notifications from this workspace session.');
    await expect(page.getByLabel('Keep notifications on screen until dismissed', { exact: true })).toBeChecked();
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
test('notifications and preferences support narrow enlarged-text views and keyboard dismissal', async ({ page }) => {
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
test('delayed audio unlock cannot amplify a burst or play after muting', async ({ page }) => {
    await page.clock.install();
    const f = await fixture(page, { delayed: true });
    await trigger(page).click();
    await page.getByLabel('Play notification sounds', { exact: true }).check();
    await emit(page, 'Fictional pending sound one.');
    await emit(page, 'Fictional pending sound two.');
    await page.evaluate(() => window.testResumeCallbacks.splice(0).forEach(resume => resume()));
    expect(await page.evaluate(() => window.testTones)).toBe(1);
    await page.getByLabel('Play notification sounds', { exact: true }).uncheck();
    await page.getByLabel('Play notification sounds', { exact: true }).check();
    await page.clock.fastForward(2100);
    await emit(page, 'Fictional pending background sound.');
    await page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, value: true });
        window.testResumeCallbacks.splice(0).forEach(resume => resume());
    });
    expect(await page.evaluate(() => window.testTones)).toBe(1);
    await page.evaluate(() => { delete document.hidden; });
    await page.getByLabel('Play notification sounds', { exact: true }).uncheck();
    await page.getByLabel('Play notification sounds', { exact: true }).check();
    await emit(page, 'Fictional pending muted sound.');
    await page.getByLabel('Play notification sounds', { exact: true }).uncheck();
    await page.evaluate(() => window.testResumeCallbacks.splice(0).forEach(resume => resume()));
    expect(await page.evaluate(() => window.testTones)).toBe(1);
    expect(f.errors).toEqual([]);
});
test('all four local sounds are selectable, lazy, cached and stopped when changed or muted', async ({ page }) => {
    await page.clock.install();
    const requests = [];
    page.on('request', request => {
        if (request.url().includes('/assets/sounds/')) requests.push(new URL(request.url()).pathname);
    });
    const f = await fixture(page);
    await trigger(page).click();
    const choice = page.getByLabel('Notification sound', { exact: true });
    await choice.selectOption('message');
    expect(requests).toEqual([]);
    expect(await page.evaluate(() => window.testAudioContexts)).toBe(0);
    const files = { message: 'message-notification-4.wav', warm: 'warm-success-notification.wav',
        bird: 'bird_notification.mp3', piano: 'piano-notification-5b.mp3' };
    for (const [value, filename] of Object.entries(files)) {
        await choice.selectOption(value);
        await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
        await expect(dialog(page)).toContainText('Sound preview played.');
        expect(requests.at(-1)).toBe(`/assets/sounds/${filename}`);
    }
    expect(await page.evaluate(() => window.testDecoded)).toBe(4);
    await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
    await expect.poll(() => page.evaluate(() => window.testFileSounds)).toBe(5);
    expect(requests).toHaveLength(4);
    expect(await page.evaluate(() => window.testDecoded)).toBe(4);
    await page.clock.fastForward(2100);
    await emit(page, 'Fictional feedback during a longer sound.');
    await emit(page, 'Fictional burst during a longer sound.');
    expect(await page.evaluate(() => window.testFileSounds)).toBe(5);
    await page.getByLabel('Play notification sounds', { exact: true }).uncheck();
    expect(await page.evaluate(() => window.testStoppedFiles)).toBe(5);
    await page.reload();
    await trigger(page).click();
    await expect(choice).toHaveValue('piano');
    await expect(page.getByLabel('Play notification sounds', { exact: true })).not.toBeChecked();
    expect(requests).toHaveLength(4);
    expect(f.errors).toEqual([]);
});
test('failed and delayed asset loads do not block visual feedback or play an obsolete choice', async ({ page }) => {
    const f = await fixture(page);
    await page.route('**/assets/sounds/*', route => route.fulfill({ status: 404, body: '' }));
    await trigger(page).click();
    const choice = page.getByLabel('Notification sound', { exact: true });
    await choice.selectOption('message');
    await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
    await expect(dialog(page)).toContainText('Sound is unavailable. Visual notifications will still appear.');
    expect(await page.evaluate(() => window.testFileSounds)).toBe(0);
    await page.unroute('**/assets/sounds/*');
    let release;
    const arrived = new Promise(resolve => { release = resolve; });
    let complete;
    const delay = new Promise(resolve => { complete = resolve; });
    await page.route('**/assets/sounds/*', async route => { release(); await delay;
        await route.fulfill({ body: Buffer.from('fictional audio'), contentType: 'audio/wav' }); });
    await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
    await arrived;
    await choice.selectOption('tone');
    complete();
    await expect(dialog(page)).not.toContainText('Preparing sound…');
    await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
    await expect(dialog(page)).toContainText('Sound preview played.');
    expect(await page.evaluate(() => window.testFileSounds)).toBe(0);
    await page.keyboard.press('Escape');
    await emit(page, 'Fictional action still completed.');
    await expect(page.locator('#workspace-status')).toContainText('Fictional action still completed.');
    expect(f.errors).toEqual([]);
});
test('legacy preferences preserve choices safely and stored URLs cannot select an audio source', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('etsis.workspace.notification-preferences',
        JSON.stringify({ sound: true, keep_visible: true })));
    await fixture(page);
    await trigger(page).click();
    await expect(page.getByLabel('Play notification sounds', { exact: true })).toBeChecked();
    await expect(page.getByLabel('Keep notifications on screen until dismissed', { exact: true })).toBeChecked();
    await expect(page.getByLabel('Notification sound', { exact: true })).toHaveValue('tone');
    await page.getByLabel('Notification sound', { exact: true }).selectOption('bird');
    expect(await page.evaluate(() => JSON.parse(localStorage.getItem('etsis.workspace.notification-preferences'))))
        .toEqual({ sound: true, keep_visible: true, sound_id: 'bird' });
    await page.addInitScript(() => localStorage.setItem('etsis.workspace.notification-preferences',
        JSON.stringify({ sound: false, keep_visible: false, sound_id: 'https://example.invalid/private' })));
    await page.reload();
    await trigger(page).click();
    await expect(page.getByLabel('Notification sound', { exact: true })).toHaveValue('tone');
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
test('sound loading times out and hidden tabs or clearing feedback stop file playback', async ({ page }) => {
    await page.clock.install();
    const f = await fixture(page);
    let arrived, release;
    const loading = new Promise(resolve => { arrived = resolve; });
    const delayed = new Promise(resolve => { release = resolve; });
    await page.route('**/assets/sounds/*', async route => { arrived(); await delayed; await route.abort(); });
    await trigger(page).click();
    await page.getByLabel('Notification sound', { exact: true }).selectOption('bird');
    await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
    await loading;
    await page.clock.fastForward(8100);
    await expect(dialog(page)).toContainText('Sound is unavailable. Visual notifications will still appear.');
    release(); await page.unroute('**/assets/sounds/*');
    await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
    await expect(dialog(page)).toContainText('Sound preview played.');
    await page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, value: true });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    expect(await page.evaluate(() => window.testStoppedFiles)).toBe(1);
    await emit(page, 'Fictional background feedback.');
    expect(await page.evaluate(() => window.testFileSounds)).toBe(1);
    await page.evaluate(() => { delete document.hidden; });
    await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
    await expect.poll(() => page.evaluate(() => window.testFileSounds)).toBe(2);
    await page.getByRole('button', { name: 'Clear recent notifications', exact: true }).click();
    expect(await page.evaluate(() => window.testStoppedFiles)).toBe(2);
    expect(f.errors).toEqual([]);
});
