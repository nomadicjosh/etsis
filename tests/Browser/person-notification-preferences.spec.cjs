'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const fixture = require('./helpers/notification-person.cjs');
const emit = (page, message = 'Fictional saved record.') => page.evaluate(message => window.testNotifications.notify(message), message);

test('person drawer saves preferences per person; the notification center has no settings or read controls', async ({ page }) => {
    const f = await fixture(page);
    await f.open();
    await page.getByLabel('Keep notifications on screen until dismissed', { exact: true }).check();
    await page.getByLabel('Notification sound', { exact: true }).selectOption('piano');
    await f.save();
    expect(f.posts[0].csrf).toBe('fictional-test-token');
    expect(f.values()).toEqual({ sound: false, sound_id: 'piano', keep_visible: true });
    await expect(page.getByRole('button', { name: 'Back to record', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await emit(page);
    await page.locator('#workspace-notifications-open').click();
    await expect(page.locator('.workspace-notification-dialog input')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Test notification sound', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Delete notification', exact: true })).toBeVisible();
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    await page.reload(); await f.open();
    await expect(page.getByLabel('Keep notifications on screen until dismissed', { exact: true })).toBeChecked();
    await expect(page.getByLabel('Notification sound', { exact: true })).toHaveValue('piano');
    expect(f.errors).toEqual([]);
});

test('student preferences fit under Personal information and reflow with enlarged text', async ({ page }) => {
    const f = await fixture(page, { portal: true });
    await f.open();
    await page.getByLabel('Notification sound', { exact: true }).selectOption('bird');
    await f.save();
    expect(f.posts[0].path).toBe('/portal/api/notification-preferences/');
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    await page.getByRole('button', { name: 'Save notification preferences', exact: true }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: '/tmp/etsis-person-notification-preferences-phone.png' });
    expect(f.errors).toEqual([]);
});

test('editing another person does not apply their sound to the operators browser', async ({ page }) => {
    const f = await fixture(page, { other: true });
    await f.open();
    await page.getByLabel('Play notification sounds', { exact: true }).check();
    await f.save();
    await emit(page);
    expect(await page.evaluate(() => window.testTones)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('sound preview saves nothing and real feedback stays opt in and rate limited', async ({ page }) => {
    await page.clock.install();
    const f = await fixture(page);
    await f.open();
    await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
    expect(f.posts).toHaveLength(0);
    await expect(page.getByLabel('Play notification sounds', { exact: true })).not.toBeChecked();
    expect(await page.evaluate(() => window.testTones)).toBe(1);
    await page.clock.fastForward(2100); await emit(page);
    expect(await page.evaluate(() => window.testTones)).toBe(1);
    await page.getByLabel('Play notification sounds', { exact: true }).check(); await f.save();
    await page.clock.fastForward(2100); await emit(page); await emit(page);
    expect(await page.evaluate(() => window.testTones)).toBe(2);
    await page.getByLabel('Play notification sounds', { exact: true }).uncheck(); await f.save();
    await page.clock.fastForward(2100); await emit(page);
    expect(await page.evaluate(() => window.testTones)).toBe(2);
});

test('all local sound choices load lazily and a repeated preview reuses the decoded file', async ({ page }) => {
    const requested = [];
    page.on('request', req => { if (req.url().includes('/assets/sounds/')) requested.push(new URL(req.url()).pathname); });
    const f = await fixture(page);
    await f.open();
    for (const choice of ['message', 'warm', 'bird', 'piano']) {
        await page.getByLabel('Notification sound', { exact: true }).selectOption(choice);
        await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
        await expect(page.getByText('Sound preview played.', { exact: true })).toBeVisible();
    }
    expect(requested).toHaveLength(4);
    await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
    expect(requested).toHaveLength(4);
    expect(await page.evaluate(() => window.testDecoded)).toBe(4);
    expect(f.posts).toHaveLength(0);
    expect(f.errors).toEqual([]);
});

test('blocked audio and stale saves preserve a usable editor without browser storage', async ({ page }) => {
    const f = await fixture(page, { blocked: true, stale: true });
    await f.open();
    await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
    await expect(page.getByText('Sound is unavailable. Visual notifications will still appear.', { exact: true })).toBeVisible();
    await page.getByLabel('Notification sound', { exact: true }).selectOption('warm');
    await f.save();
    await expect(page.getByText('Preferences changed elsewhere. Close and reopen this editor before saving.', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Notification sound', { exact: true })).toHaveValue('warm');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('shared-browser legacy choices are cleared and cannot load an arbitrary sound URL', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('etsis.workspace.notification-preferences', JSON.stringify({
        sound: true, keep_visible: true, sound_id: 'https://example.invalid/private',
    })));
    const requested = [];
    page.on('request', req => { if (req.url().includes('example.invalid/private')) requested.push(req.url()); });
    const f = await fixture(page); await f.open();
    await expect(page.getByLabel('Play notification sounds', { exact: true })).not.toBeChecked();
    await expect(page.getByLabel('Notification sound', { exact: true })).toHaveValue('tone');
    expect(requested).toEqual([]);
    expect(await page.evaluate(() => localStorage.getItem('etsis.workspace.notification-preferences'))).toBeNull();
});

test('closing preferences cancels a delayed audio unlock before it can play', async ({ page }) => {
    const f = await fixture(page, { delayed: true });
    await f.open();
    await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
    await expect(page.getByText('Preparing sound…', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await page.evaluate(() => window.testResumeCallbacks.forEach(resolve => resolve()));
    expect(await page.evaluate(() => window.testTones)).toBe(0);
    expect(await page.evaluate(() => window.testClosedAudio)).toBe(1);
    expect(f.posts).toHaveLength(0); expect(f.errors).toEqual([]);
});

test('changing a preview cancels a delayed sound file and keeps the new choice silent', async ({ page }) => {
    const f = await fixture(page); await f.open();
    let arrive, release;
    const requested = new Promise(resolve => { arrive = resolve; });
    const gate = new Promise(resolve => { release = resolve; });
    await page.route('**/assets/sounds/message-notification-4.wav', async route => {
        arrive(); await gate; await route.fulfill({ body: Buffer.alloc(32), contentType: 'audio/wav' });
    });
    await page.getByLabel('Notification sound', { exact: true }).selectOption('message');
    await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
    await requested;
    await page.getByLabel('Notification sound', { exact: true }).selectOption('tone');
    release();
    await expect(page.getByRole('button', { name: 'Test notification sound', exact: true })).toBeEnabled();
    expect(await page.evaluate(() => window.testTones)).toBe(0);
    expect(f.posts).toHaveLength(0); expect(f.errors).toEqual([]);
});

test('a stalled preview times out without preventing visual feedback or saving preferences', async ({ page }) => {
    await page.clock.install();
    const f = await fixture(page); await f.open();
    let arrive, release;
    const requested = new Promise(resolve => { arrive = resolve; });
    const gate = new Promise(resolve => { release = resolve; });
    await page.route('**/assets/sounds/message-notification-4.wav', async route => {
        arrive(); await gate; await route.abort();
    });
    await page.getByLabel('Notification sound', { exact: true }).selectOption('message');
    await page.getByRole('button', { name: 'Test notification sound', exact: true }).click();
    await requested; await page.clock.fastForward(8100);
    await expect(page.getByText('Sound is unavailable. Visual notifications will still appear.', { exact: true })).toBeVisible();
    await emit(page);
    await expect(page.locator('.workspace-notification-toast')).toBeVisible();
    await f.save(); release();
    expect(f.values().sound).toBe(false); expect(f.errors).toEqual([]);
});

test('saved keep-visible preferences reconfigure current and future feedback without losing recent history', async ({ page }) => {
    await page.clock.install();
    const f = await fixture(page); await f.open();
    await emit(page, 'Fictional timed feedback.');
    await page.clock.fastForward(8100);
    await expect(page.locator('.workspace-notification-toast')).toHaveCount(0);
    await emit(page, 'Fictional kept feedback.');
    await page.getByLabel('Keep notifications on screen until dismissed', { exact: true }).check();
    await f.save();
    await page.clock.fastForward(20000);
    await expect(page.locator('.workspace-notification-toast')).toContainText('Fictional kept feedback.');
    await page.getByLabel('Keep notifications on screen until dismissed', { exact: true }).uncheck();
    await f.save(); await page.clock.fastForward(8100);
    await expect(page.locator('.workspace-notification-toast')).toHaveCount(0);
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.locator('#workspace-notifications-open').click();
    await expect(page.locator('.workspace-notification-dialog')).toContainText('Fictional timed feedback.');
    await expect(page.locator('.workspace-notification-dialog')).toContainText('Fictional kept feedback.');
    expect(f.errors).toEqual([]);
});
