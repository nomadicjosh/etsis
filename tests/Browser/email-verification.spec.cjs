'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const token = 'a'.repeat(64);
async function fixture(page, { theme = 'campus-harbor', guest = false, link = true } = {}) {
    const state = { calls: [], errors: [], mode: 'ok', release: null, arrived: false,
        value: { verified: false, required: true, delivery_enabled: true, destination_hint: 'f…@example.invalid',
            can_request: !link, version: link ? 1 : 0, state: link ? 'sent' : 'not_requested' } };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/portal/api/email-verification/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        const raw = request.postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        state.calls.push({ path, data, headers: request.headers() });
        if (state.mode === 'denied') return route.fulfill({ status: 403, json: { error: 'PRIVATE upstream details' } });
        if (state.mode === 'malformed') return route.fulfill({ json: { verified: 'true', state: 'verified', token: 'PRIVATE' } });
        if (path.endsWith('/confirm/')) {
            if (state.mode === 'wrong') return route.fulfill({ status: 422, json: { error: 'PRIVATE recipient and transport body' } });
            if (state.mode === 'delay') { state.arrived = true; await new Promise(resolve => { state.release = resolve; }); }
            Object.assign(state.value, { verified: true, state: 'verified', can_request: false });
        } else if (path.endsWith('/request/')) {
            Object.assign(state.value, { version: state.value.version + 1, state: 'queued', can_request: false });
        }
        await route.fulfill({ json: { ...state.value, ignored_secret: 'PRIVATE upstream body' },
            headers: { 'Cache-Control': 'private, no-store' } }).catch(() => {});
    });
    const response = await page.goto(`/portal/verify-email/?theme=${theme}${guest ? '&guest=1' : ''}${link ? '#' + token : ''}`);
    expect(response.headers()['referrer-policy']).toBe('no-referrer');
    return state;
}
const card = page => page.getByRole('region', { name: 'Verify your sign-in email', exact: true });
for (const theme of ['campus-harbor', 'campus-quad', 'campus-horizon']) {
    test(`${theme}: a private link is cleared from the address bar and consumed only after explicit owner confirmation`, async ({ page }) => {
        const f = await fixture(page, { theme });
        const confirm = card(page).getByRole('button', { name: 'Confirm my email', exact: true });
        await expect(confirm).toBeVisible();
        expect(await page.evaluate(() => location.hash)).toBe('');
        expect(f.calls.filter(call => call.data)).toHaveLength(0);
        await confirm.press('Enter');
        await expect(card(page)).toContainText('Your current sign-in email is verified.');
        await expect(confirm).toBeHidden();
        const posts = f.calls.filter(call => call.data);
        expect(posts).toHaveLength(1);
        expect(posts[0].data).toEqual({ token });
        expect(posts[0].headers['x-csrf-token']).toBe('fictional-test-token');
        expect(posts[0].path).not.toContain(token);
        expect(posts[0].headers.referer || '').not.toContain(token);
        expect(await page.locator('body').textContent()).not.toContain(token);
        await expect(card(page)).not.toContainText('PRIVATE');
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
        await page.setViewportSize({ width: 320, height: 900 });
        await page.addStyleTag({ content: 'html { font-size: 200%; }' });
        await card(page).scrollIntoViewIfNeeded();
        const scan = await new AxeBuilder({ page }).include('#email-verification')
            .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => item.id)).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        expect(f.errors).toEqual([]);
    });
}

test('guest links require sign-in and reopening the email without a verification request or persisted token', async ({ page }) => {
    const f = await fixture(page, { guest: true });
    await expect(card(page)).toContainText('Sign in, then reopen the private link');
    await expect(card(page).getByRole('link', { name: 'Continue to sign in', exact: true })).toHaveAttribute('href', '/login/');
    await expect(card(page).getByRole('button')).toHaveCount(0);
    expect(f.calls).toHaveLength(0);
    expect(await page.evaluate(() => location.hash)).toBe('');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('an explicit link request uses only the current generation and waits for deliberate refresh before reissue', async ({ page }) => {
    const f = await fixture(page, { link: false });
    await card(page).getByRole('button', { name: 'Send verification link', exact: true }).click();
    await expect(card(page)).toContainText('Your link is queued for background delivery.');
    await expect(card(page).getByRole('button', { name: 'Send verification link', exact: true })).toBeDisabled();
    expect(f.calls.filter(call => call.data).map(call => call.data)).toEqual([{ version: 0 }]);
    Object.assign(f.value, { state: 'sent', can_request: true });
    await card(page).getByRole('button', { name: 'Refresh verification status', exact: true }).click();
    await card(page).getByRole('button', { name: 'Send verification link', exact: true }).click();
    expect(f.calls.filter(call => call.data).map(call => call.data)).toEqual([{ version: 0 }, { version: 1 }]);
    await expect(card(page).getByRole('button', { name: 'Confirm my email', exact: true })).toBeHidden();
    expect(f.errors).toEqual([]);
});

test('an unavailable or wrong-account token shows safe guidance and requires fresh status before retrying', async ({ page }) => {
    const f = await fixture(page);
    await expect(card(page).getByRole('button', { name: 'Confirm my email', exact: true })).toBeVisible();
    f.mode = 'wrong';
    await card(page).getByRole('button', { name: 'Confirm my email', exact: true }).click();
    await expect(card(page)).toContainText('This link is unavailable.');
    await expect(card(page)).not.toContainText('PRIVATE');
    await expect(card(page).getByRole('button', { name: 'Confirm my email', exact: true })).toBeHidden();
    f.mode = 'ok';
    await card(page).getByRole('button', { name: 'Refresh verification status', exact: true }).click();
    await expect(card(page).getByRole('button', { name: 'Confirm my email', exact: true })).toBeVisible();
    expect(f.errors).toEqual([]);
});

test('current permission loss clears the pending token and private controls', async ({ page }) => {
    const f = await fixture(page);
    await expect(card(page).getByRole('button', { name: 'Confirm my email', exact: true })).toBeVisible();
    f.mode = 'denied';
    await card(page).getByRole('button', { name: 'Confirm my email', exact: true }).click();
    await expect(card(page).getByRole('link', { name: 'Continue to sign in', exact: true })).toBeVisible();
    await expect(card(page).getByRole('button')).toHaveCount(0);
    await expect(card(page)).not.toContainText('PRIVATE');
    expect(f.errors).toEqual([]);
});

test('a timed-out confirmation never retries automatically and a later status can show its accepted outcome', async ({ page }) => {
    const f = await fixture(page);
    await expect(card(page).getByRole('button', { name: 'Confirm my email', exact: true })).toBeVisible();
    await page.clock.install(); f.mode = 'delay';
    await card(page).getByRole('button', { name: 'Confirm my email', exact: true }).click();
    await expect.poll(() => f.arrived).toBe(true);
    await page.clock.runFor(15001);
    await expect(card(page)).toContainText('Verification could not be completed.');
    expect(f.calls.filter(call => call.data)).toHaveLength(1);
    f.release(); f.mode = 'ok';
    await expect.poll(() => f.value.verified).toBe(true);
    await card(page).getByRole('button', { name: 'Refresh verification status', exact: true }).click();
    await expect(card(page)).toContainText('Your current sign-in email is verified.');
    expect(f.calls.filter(call => call.data)).toHaveLength(1);
    expect(f.errors).toEqual([]);
});

test('page exit discards a late confirmation and malformed status never enables credential actions', async ({ page }) => {
    const f = await fixture(page);
    await expect(card(page).getByRole('button', { name: 'Confirm my email', exact: true })).toBeVisible();
    f.mode = 'malformed';
    await card(page).getByRole('button', { name: 'Refresh verification status', exact: true }).click();
    await expect(card(page)).toContainText('Verification could not be completed.');
    await expect(card(page).getByRole('button', { name: 'Confirm my email', exact: true })).toBeHidden();
    f.mode = 'ok';
    await card(page).getByRole('button', { name: 'Refresh verification status', exact: true }).click();
    f.mode = 'delay';
    await card(page).getByRole('button', { name: 'Confirm my email', exact: true }).click();
    await expect.poll(() => f.arrived).toBe(true);
    await page.evaluate(() => window.dispatchEvent(new Event('pagehide'))); f.release();
    await expect(page.locator('#email-verification')).toHaveText('');
    expect(f.errors).toEqual([]);
});
