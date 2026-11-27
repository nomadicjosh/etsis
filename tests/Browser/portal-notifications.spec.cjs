'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const installAudio = require('./helpers/notification-audio.cjs');
const trigger = page => page.getByRole('button', { name: 'Recent notifications', exact: true });
const dialog = page => page.getByRole('dialog', { name: 'Recent notifications', exact: true });
async function fixture(page) {
    const errors = [], writes = [];
    let denied = false;
    page.on('pageerror', error => errors.push(error.message));
    await installAudio(page);
    await page.route('**/portal/api/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        let result = { items: [], next: null };
        if (path === '/portal/api/contact/') {
            expect(request.headers()['x-csrf-token']).toBe('fictional-test-token');
            writes.push(JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json')));
            if (denied) return route.fulfill({ status: 403, json: { error: 'Fictional denied service' } });
            result = { status: 'saved' };
        }
        if (path === '/portal/api/me/') result = { person: { id: 'fictional-person',
            given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' },
            contact: { preferred_name: 'Alex', phone: '', address: '', version: 0 },
            students: [], applications: [], accounts: [], restrictions: [], news: [], transcript_requests: [] };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/portal/student/profile/');
    await expect(page.getByRole('button', { name: 'Save personal information', exact: true })).toBeVisible();
    return { errors, writes, deny: () => { denied = true; } };
}
test('owner action feedback stays private and its notification area contains no preferences', async ({ page }) => {
    const f = await fixture(page);
    await page.getByLabel('What would you like us to call you?').fill('Avery');
    await page.getByRole('button', { name: 'Save personal information', exact: true }).click();
    await trigger(page).click();
    await expect(dialog(page)).toContainText('Your contact information is up to date.');
    await expect(dialog(page).locator('input,select')).toHaveCount(0);
    await expect(dialog(page).getByRole('button', { name: 'Delete notification', exact: true })).toBeVisible();
    expect(f.writes).toHaveLength(1);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});
test('portal notifications clear after access denial and page leave without changing action requests', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Save personal information', exact: true }).click();
    await expect(page.locator('#campus-notice .toast')).toHaveCount(1);
    f.deny();
    await page.getByRole('button', { name: 'Save personal information', exact: true }).click();
    await expect(page.locator('#campus-error')).toContainText('additional access');
    await expect(page.locator('#campus-notice .toast')).toHaveCount(0);
    await trigger(page).click();
    await expect(dialog(page)).toContainText('You have no notifications.');
    await page.keyboard.press('Escape');
    await page.evaluate(() => window.testNotifications.notify('Fictional private feedback.'));
    await page.evaluate(() => dispatchEvent(new PageTransitionEvent('pagehide')));
    await expect(page.locator('#campus-notice .toast')).toHaveCount(0);
    await trigger(page).click();
    await expect(dialog(page)).not.toContainText('Fictional private feedback.');
    expect(f.writes).toHaveLength(2);
    expect(f.errors).toEqual([]);
});
test('portal feedback respects modal focus, narrow reflow and accessible preference controls', async ({ page }) => {
    const f = await fixture(page);
    await page.evaluate(() => {
        document.getElementById('campus-dialog-title').textContent = 'Fictional details';
        window.bootstrap.Modal.getOrCreateInstance(document.getElementById('campus-dialog')).show();
    });
    await expect(page.locator('#campus-dialog')).toHaveClass(/show/);
    await page.evaluate(() => window.testNotifications.notify('Fictional request completed.'));
    expect(await page.locator('#campus-notice').evaluate(node => node.parentElement.id)).toBe('campus-dialog');
    await page.getByRole('button', { name: 'Dismiss notification', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('heading', { name: 'Fictional details', exact: true })).toBeFocused();
    await page.getByRole('button', { name: 'Close details', exact: true }).click();
    await expect(page.locator('#campus-dialog')).not.toHaveClass(/show/);
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    await trigger(page).click();
    await expect(dialog(page).locator('input,select')).toHaveCount(0);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    await page.screenshot({ path: '/tmp/etsis-portal-notifications-phone.png' });
    const overflow = await page.evaluate(() => [...document.querySelectorAll('body *')].filter(node => {
        const rect = node.getBoundingClientRect();
        return !node.closest('.campus-student-sidebar nav') && rect.width && rect.right > innerWidth + 1;
    }).slice(0, 20).map(node => ({ tag: node.tagName, id: node.id, class: node.className,
        right: node.getBoundingClientRect().right })));
    expect(overflow).toEqual([]);
    expect(await page.evaluate(() => ({ width: document.documentElement.scrollWidth, viewport: innerWidth,
        offset: scrollX }))).toEqual({ width: 320, viewport: 320, offset: 0 });
    expect(await dialog(page).evaluate(node => node.scrollWidth <= node.clientWidth)).toBe(true);
    await page.screenshot({ path: '/tmp/etsis-portal-notifications-phone-controls.png' });
    await page.keyboard.press('Escape');
    await expect(trigger(page)).toBeFocused();
    expect(f.errors).toEqual([]);
});
test('guest campus pages have no private notification controls or sound downloads', async ({ page }) => {
    const sounds = [];
    page.on('request', request => { if (request.url().includes('/assets/sounds/')) sounds.push(request.url()); });
    await page.goto('/portal/?guest=1');
    await expect(trigger(page)).toHaveCount(0);
    await expect(page.locator('#campus-notice, #campus-notification-config')).toHaveCount(0);
    expect(sounds).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
