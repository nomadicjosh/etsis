const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const person = '00000000000000000000000001';
async function fixture(page, options = {}) {
    const calls = [], errors = [];
    let saved = null;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, method: route.request().method(), csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/people/')) result = { items: [{ id: person, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }], next_after: null };
        else if (path === `/sis/ui/people/${person}/`) result = { person: { id: { value: person }, givenName: 'Alex', familyName: 'Example', institutionalId: { value: 'S-FICTIONAL' }, ownerUserId: null }, students: [], applications: [], employees: [], accounts: [] };
        else if (path.endsWith('/moodle/account-request/')) result = { enabled: !options.disabled, installation_approved: true,
            processing_available: false, request: saved, link: null, defaults: { username: 'alex_example', email: 'fictional@example.invalid' },
            person: { id: person, name: 'Alex Example', institutional_id: 'S-FICTIONAL' } };
        else if (path.endsWith('/account-request/review/')) result = { review_token: 'fictional-private-account-review', person_name: 'Alex Example',
            username: data.username, email: data.email, sign_in: data.sign_in, expires_at: 1794410000, processing_available: false };
        else if (path.endsWith('/account-request/confirm/')) {
            saved = { id: '00000000000000000000000002', binding_id: '00000000000000000000000003', requested_at: '2026-11-12T12:00:00Z', state: 'queued' };
            if (options.interrupted) { await route.fulfill({ status: 503, json: { error: 'Fictional interrupted response.' } }); return; }
            result = { request: saved, processing_available: false };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(options.denied ? '/admin/moodle-request-denied-fixture/' : '/admin/');
    await page.locator('.workspace-nav-link[data-workspace="people"]').click();
    await page.locator('#directory-search').fill('Alex'); await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    return { calls, errors };
}
for (const mode of ['sso', 'password_email']) {
    test(`review and queue ${mode} with explicit confirmation, transient private state and accessible controls`, async ({ page }) => {
        const f = await fixture(page);
        expect(f.calls.some(c => c.path.includes('account-request'))).toBe(false);
        await page.getByRole('button', { name: 'Create a Moodle account', exact: true }).click();
        await expect(page.getByLabel('Moodle username', { exact: true })).toHaveValue('alex_example');
        await expect(page.getByLabel('Account email address', { exact: true })).toHaveValue('fictional@example.invalid');
        await expect(page.locator('[data-account-request-status]')).toContainText('will not create an account or send an email');
        await page.getByLabel(mode === 'sso' ? 'Institutional SSO' : 'Moodle password-setup email', { exact: true }).check();
        await page.getByLabel('Reason for account creation', { exact: true }).fill('Institution-approved access.');
        await page.getByRole('button', { name: 'Review account request', exact: true }).click();
        const body = page.locator('#drawer-body');
        await expect(body.getByRole('heading', { name: 'Review account creation request' })).toBeFocused();
        const save = page.getByRole('button', { name: 'Save reviewed creation request', exact: true });
        await expect(save).toBeDisabled();
        expect(f.calls.some(c => c.path.endsWith('/confirm/'))).toBe(false);
        await page.setViewportSize({ width: 375, height: 812 });
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await body.getByRole('checkbox').focus(); await page.keyboard.press('Space'); await page.keyboard.press('Tab');
        await expect(save).toBeFocused(); await page.keyboard.press('Enter');
        await expect(body.locator('[data-account-request-status]')).toContainText('queued');
        await expect(body.locator('[data-account-request-status]')).toBeFocused();
        await expect(body).not.toContainText('fictional-private-account-review');
        await expect(body.getByLabel('Account email address', { exact: true })).toHaveCount(0);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
        expect(f.calls.find(c => c.path.endsWith('/review/'))).toMatchObject({ data: { sign_in: mode }, csrf: 'fictional-test-token' });
        expect(f.calls.find(c => c.path.endsWith('/confirm/'))).toMatchObject({ data: { review_token: 'fictional-private-account-review' }, csrf: 'fictional-test-token' });
        await page.getByRole('button', { name: 'Refresh request status', exact: true }).click();
        await expect(page.locator('[data-account-request-status]')).toContainText('queued');
        expect(f.calls.filter(c => c.path.endsWith('/confirm/'))).toHaveLength(1);
        expect(f.errors).toEqual([]);
    });
}
test('cancel preserves the person workflow without enqueueing', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Create a Moodle account', exact: true }).click();
    await page.getByLabel('Institutional SSO', { exact: true }).check();
    await page.getByLabel('Reason for account creation', { exact: true }).fill('Fictional request.');
    await page.getByRole('button', { name: 'Review account request', exact: true }).click();
    await page.getByRole('button', { name: 'Cancel account review', exact: true }).click();
    await expect(page.getByLabel('Moodle username', { exact: true })).toBeFocused();
    await expect(page.getByRole('button', { name: 'Save reviewed creation request' })).toHaveCount(0);
    expect(f.calls.some(c => c.path.endsWith('/confirm/'))).toBe(false);
    expect(f.errors).toEqual([]);
});
test('refresh recovers an interrupted confirmation without a duplicate request', async ({ page }) => {
    const f = await fixture(page, { interrupted: true });
    await page.getByRole('button', { name: 'Create a Moodle account', exact: true }).click();
    await page.getByLabel('Moodle password-setup email', { exact: true }).check();
    await page.getByLabel('Reason for account creation', { exact: true }).fill('Fictional request.');
    await page.getByRole('button', { name: 'Review account request', exact: true }).click();
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Save reviewed creation request', exact: true }).click();
    await expect(page.locator('[data-account-request-status]')).toContainText('Refresh request status');
    await page.getByRole('button', { name: 'Refresh request status', exact: true }).click();
    await expect(page.locator('[data-account-request-status]')).toContainText('queued');
    expect(f.calls.filter(c => c.path.endsWith('/confirm/'))).toHaveLength(1);
    expect(f.errors).toEqual([]);
});
test('disabled intake does not expose draft fields or run a review', async ({ page }) => {
    const f = await fixture(page, { disabled: true });
    await page.getByRole('button', { name: 'Create a Moodle account', exact: true }).click();
    await expect(page.locator('[data-account-request-status]')).toContainText('intake is disabled');
    await expect(page.getByLabel('Moodle username', { exact: true })).toHaveCount(0);
    expect(f.calls.some(c => c.path.endsWith('/review/'))).toBe(false);
    expect(f.errors).toEqual([]);
});
test('existing-link permission alone does not expose creation action', async ({ page }) => {
    const f = await fixture(page, { denied: true });
    await expect(page.getByRole('button', { name: 'Create a Moodle account', exact: true })).toHaveCount(0);
    expect(f.calls.some(c => c.path.includes('account-request'))).toBe(false);
    expect(f.errors).toEqual([]);
});
