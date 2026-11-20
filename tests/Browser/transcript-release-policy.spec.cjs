"use strict";
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

test('release check setup preserves stale edits, supports keyboard use and keeps retained artifacts outside setup', async ({ page }) => {
    let policy = { revision: 0, checks: [] }, reject = true, sequence = 10;
    const calls = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/transcript-policy/') {
            if (data && reject) {
                reject = false;
                return route.fulfill({ status: 409, json: { message: 'Reload release-check setup before saving.' } });
            }
            if (data) policy = { revision: policy.revision + 1, checks: data.checks };
            result = policy;
        } else if (path === '/sis/transcript-policy/check-id/') result = { id: id(++sequence) };
        else if (path.startsWith('/sis/ui/data/')) result = { items: [], next_after: null };
        else if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="transcripts"]').click();
    await page.getByRole('button', { name: 'Transcript release checks', exact: true }).click();
    await page.getByRole('button', { name: 'Add check', exact: true }).click();
    const title = page.getByLabel('Check title', { exact: true });
    await expect(title).toBeFocused();
    await title.fill('Review release authorization');
    await page.getByLabel('Instructions', { exact: true }).fill('Verify the fictional authorization reference.');
    await page.getByLabel('Required at', { exact: true }).selectOption('both');
    await page.setViewportSize({ width: 375, height: 812 });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Save checks', exact: true }).click();
    await expect(title).toHaveValue('Review release authorization');
    await expect(page.locator('#drawer-body')).toContainText('Setup revision 0');
    await page.getByRole('button', { name: 'Save checks', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Setup revision 1');
    await page.getByText('Review release authorization', { exact: true }).click();
    await expect(page.getByLabel('Check title', { exact: true })).toHaveValue('Review release authorization');
    await page.getByRole('button', { name: 'Remove check', exact: true }).click();
    await expect(page.getByLabel('Check title', { exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Save checks', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Setup revision 2');
    const saves = calls.filter(call => call.path === '/sis/transcript-policy/' && call.data);
    expect(saves[0].data).toEqual(saves[1].data);
    expect(saves[1].data.checks[0]).toEqual({ id: id(11), label: 'Review release authorization',
        details: 'Verify the fictional authorization reference.', stage: 'both' });
    expect(saves[2].data).toEqual({ revision: 1, checks: [] });
    expect(calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(calls.some(call => call.path.includes('/documents/'))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});
