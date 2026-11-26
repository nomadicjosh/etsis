'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const personFixture = require('./helpers/notification-person.cjs');
const person = '01K00000000000000000000010';
const base = `/sis/people/${person}/ssn/`;

async function fixture(page, options = {}) {
    const f = await personFixture(page);
    let version = 0, mask = null, denied = false;
    const posts = [], history = [];
    await page.route('**' + base + '**', async route => {
        const req = route.request(), path = new URL(req.url()).pathname;
        const data = req.postData() ? JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json')) : null;
        if (options.wait && path.endsWith('/review/')) await options.wait;
        if (denied) return route.fulfill({ status: 403, json: { error: 'Fictional access revoked.' } });
        let result = { version, masked: mask, can_edit: !options.readonly, can_history: true, at_limit: false };
        if (data) posts.push({ path, data, csrf: req.headers()['x-csrf-token'] });
        if (path.endsWith('/review/')) {
            if (options.stale && posts.filter(item => item.path.endsWith('/review/')).length === 1) {
                version++; return route.fulfill({ status: 409, json: { error: 'Fictional saved record changed.' } });
            }
            if (data.ssn !== data.ssn_confirmation) return route.fulfill({ status: 422, json: { error: 'The replacement SSN entries do not match.' } });
            result = { version, before: mask, after: data.ssn ? `***-**-${data.ssn.replaceAll('-', '').slice(-4)}` : null,
                reason: data.reason, operation_id: '01K00000000000000000000099', review_token: 'fictional-private-proof' };
        } else if (path.endsWith('/confirm/')) {
            mask = data.ssn ? `***-**-${data.ssn.replaceAll('-', '').slice(-4)}` : null;
            version++; history.push({ version, recorded_at: '2026-11-26T12:00:00+00:00', action: mask ? 'recorded' : 'cleared', reason: data.reason });
            result = { version, status: 'recorded' };
        } else if (path.endsWith('/history/')) result = { items: history, next_cursor: null };
        await route.fulfill({ json: result });
    });
    async function open() {
        await page.locator('.workspace-nav-link[data-workspace="people"]').click();
        await page.getByLabel('Search this workspace').fill('Fictional');
        await page.getByLabel('Search this workspace').press('Enter');
        await page.getByRole('button', { name: 'Fictional Person', exact: true }).click();
        await page.getByRole('button', { name: 'More person actions', exact: true }).click();
        await page.getByRole('button', { name: 'Social Security number', exact: true }).click();
        await expect(page.locator('[data-ssn-mask]')).toBeVisible();
    }
    async function fill(value = '000001234') {
        await page.getByLabel('Replacement SSN', { exact: true }).fill(value);
        await page.getByLabel('Enter replacement SSN again', { exact: true }).fill(value);
        await page.getByLabel('Reason for SSN correction', { exact: true }).fill('Fictional verified correction.');
    }
    const review = () => page.getByRole('button', { name: 'Review SSN correction', exact: true }).click();
    async function confirm() {
        await page.getByRole('button', { name: 'Confirm SSN correction', exact: true }).click();
        await expect(page.getByText('SSN correction recorded. Only the saved last four digits are displayed.', { exact: true })).toBeVisible();
    }
    return { ...f, posts, open, fill, review, confirm, deny: () => { denied = true; } };
}

test('reviewed entry, same-last-four correction and clearing keep every saved view masked', async ({ page }) => {
    const f = await fixture(page); await f.open(); await f.fill(); await f.review();
    expect(f.posts).toHaveLength(1);
    await expect(page.getByText('***-**-1234', { exact: true })).toBeVisible();
    await expect(page.locator('input[type=password]')).toHaveCount(0);
    expect(await page.locator('#drawer-body').textContent()).not.toContain('000001234');
    await f.confirm();
    await expect(page.locator('[data-ssn-mask]')).toHaveText('***-**-1234');
    await expect(page.getByLabel('Replacement SSN', { exact: true })).toHaveValue('');
    await f.fill('000991234'); await f.review(); await f.confirm();
    expect(f.posts.filter(item => item.path.endsWith('/confirm/'))).toHaveLength(2);
    await page.getByLabel('Reason for SSN correction', { exact: true }).fill('Fictional explicit removal.');
    await page.getByRole('button', { name: 'Clear saved SSN', exact: true }).click();
    await f.confirm();
    await expect(page.locator('[data-ssn-mask]')).toHaveText('No saved SSN');
    await page.getByRole('button', { name: 'SSN correction history', exact: true }).click();
    await expect(page.getByText('Number cleared · 3', { exact: true })).toBeVisible();
    expect(f.posts.every(item => item.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('changed revisions preserve a private draft but require a fresh review; duty loss clears it', async ({ page }) => {
    const f = await fixture(page, { stale: true }); await f.open(); await f.fill(); await f.review();
    await expect(page.getByText('Fictional saved record changed.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Reload saved mask', exact: true }).click();
    await expect(page.getByLabel('Replacement SSN', { exact: true })).toHaveValue('000001234');
    await f.review(); await expect(page.getByRole('button', { name: 'Confirm SSN correction', exact: true })).toBeVisible();
    f.deny(); await page.getByRole('button', { name: 'Confirm SSN correction', exact: true }).click();
    await expect(page.getByText('SSN access changed. Reopen the person record before continuing.', { exact: true })).toBeVisible();
    await expect(page.locator('input[type=password]')).toHaveCount(0);
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('button', { name: 'More person actions', exact: true })).toBeVisible();
    expect(f.errors).toEqual([]);
});

test('readonly staff get no edit controls and delayed reviews cannot reopen a dismissed record', async ({ page }) => {
    const read = await fixture(page, { readonly: true }); await read.open();
    await expect(page.locator('input[type=password]')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Review SSN correction', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    let release;
    const wait = new Promise(resolve => { release = resolve; });
    const late = await fixture(page, { wait }); await late.open(); await late.fill(); await late.review();
    await page.getByRole('button', { name: 'Close record', exact: true }).click(); release();
    await expect(page.getByRole('button', { name: 'Confirm SSN correction', exact: true })).toHaveCount(0);
    expect(late.errors).toEqual([]);
});

test('masked correction flow supports keyboard, 320px width and 200 percent text', async ({ page }) => {
    const f = await fixture(page); await f.open();
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    await page.getByLabel('Replacement SSN', { exact: true }).focus();
    await page.keyboard.type('000001234'); await page.keyboard.press('Tab'); await page.keyboard.type('000001234');
    await page.keyboard.press('Tab'); await page.keyboard.type('Fictional verified correction.');
    await page.keyboard.press('Tab'); await page.keyboard.press('Enter');
    await expect(page.getByRole('button', { name: 'Confirm SSN correction', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    await page.screenshot({ path: '/tmp/etsis-ssn-phone.png' });
    await expect(page.getByRole('button', { name: 'Back to record', exact: true }).locator('i.bi-arrow-left')).toHaveCount(1);
    expect(f.errors).toEqual([]);
});
