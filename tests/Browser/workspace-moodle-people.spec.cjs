const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const person = '00000000000000000000000001';
async function fixture(page, options = {}) {
    const calls = [], errors = [];
    let link = null;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, method: route.request().method(), csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/people/')) result = { items: [{ id: person, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }], next_after: null };
        else if (path === `/sis/ui/people/${person}/`) result = { person: { id: { value: person }, givenName: 'Alex', familyName: 'Example', institutionalId: { value: 'S-FICTIONAL' }, ownerUserId: null }, students: [], applications: [], accounts: [], employees: [] };
        else if (path === `/sis/people/${person}/moodle/`) result = { enabled: !options.disabled, installation_approved: !options.unapproved, person: { id: person, name: 'Alex Example', institutional_id: 'S-FICTIONAL' }, link };
        else if (path.endsWith('/moodle/review/')) result = { review_token: 'fictional-private-person-review', moodle_user_id: '123', status: { suspended: false, deleted: null }, expires_at: 1794410000 };
        else if (path.endsWith('/moodle/confirm/')) {
            link = { id: '00000000000000000000000002', binding_id: '00000000000000000000000003', moodle_user_id: '123', approved_at: '2026-11-11T12:00:00Z' };
            if (options.interrupted) {
                await route.fulfill({ status: 503, json: { error: 'Fictional interrupted response.' } }); return;
            }
            result = { link };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(options.denied ? '/admin/moodle-person-denied-fixture/' : '/admin/');
    await page.locator('.workspace-nav-link[data-workspace="people"]').click();
    await page.locator('#directory-search').fill('Alex');
    await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    return { calls, errors };
}
test('explicit person link is reviewed with unknown flags and keyboard approval without browser storage', async ({ page }) => {
    const f = await fixture(page);
    expect(f.calls.some(c => c.path.includes('/moodle/'))).toBe(false);
    await page.getByRole('button', { name: 'Moodle account link', exact: true }).click();
    await page.getByLabel('Moodle user ID', { exact: true }).fill('0');
    await page.getByLabel('Reason for linking', { exact: true }).fill('Institutional account verified independently.');
    await page.getByRole('button', { name: 'Review Moodle account link', exact: true }).click();
    expect(f.calls.some(c => c.path.endsWith('/review/'))).toBe(false);
    await page.getByLabel('Moodle user ID', { exact: true }).fill('123');
    await page.getByRole('button', { name: 'Review Moodle account link', exact: true }).click();
    const body = page.locator('#drawer-body');
    await expect(body.getByRole('heading', { name: 'Review the person and Moodle reference' })).toBeFocused();
    await expect(body).toContainText('Not supplied by Moodle');
    const confirm = body.getByRole('button', { name: 'Approve reviewed person link', exact: true });
    await expect(confirm).toBeDisabled();
    expect(f.calls.some(c => c.path.endsWith('/confirm/'))).toBe(false);
    await page.setViewportSize({ width: 375, height: 812 });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await body.getByRole('checkbox').focus();
    await page.keyboard.press('Space'); await page.keyboard.press('Tab');
    await expect(confirm).toBeFocused(); await page.keyboard.press('Enter');
    await expect(body.locator('[data-moodle-link-status]')).toContainText('link saved');
    await expect(body.locator('[data-moodle-link-status]')).toBeFocused();
    await expect(body.getByRole('button', { name: 'Review Moodle account link' })).toHaveCount(0);
    await expect(body).not.toContainText('fictional-private-person-review');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    const confirmation = f.calls.find(c => c.path.endsWith('/confirm/'));
    expect(confirmation).toMatchObject({ data: { review_token: 'fictional-private-person-review' }, method: 'POST', csrf: 'fictional-test-token' });
    const reads = f.calls.filter(c => c.path.endsWith('/review/')).length;
    await body.getByRole('button', { name: 'Refresh saved link' }).click();
    await expect(page.locator('#drawer-body')).toContainText('123');
    expect(f.calls.filter(c => c.path.endsWith('/review/'))).toHaveLength(reads);
    expect(f.errors).toEqual([]);
});
test('cancellation preserves the person workflow without creating a link', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Moodle account link', exact: true }).click();
    await page.getByLabel('Moodle user ID', { exact: true }).fill('123');
    await page.getByLabel('Reason for linking', { exact: true }).fill('Fictional initial setup.');
    await page.getByRole('button', { name: 'Review Moodle account link', exact: true }).click();
    await page.getByRole('button', { name: 'Cancel link review', exact: true }).click();
    await expect(page.getByLabel('Moodle user ID', { exact: true })).toBeFocused();
    await expect(page.getByRole('button', { name: 'Approve reviewed person link' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Back to person', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('S-FICTIONAL');
    expect(f.calls.some(c => c.path.endsWith('/confirm/'))).toBe(false);
    expect(f.errors).toEqual([]);
});
test('refresh recovers saved link after an interrupted response without repeating confirmation', async ({ page }) => {
    const f = await fixture(page, { interrupted: true });
    await page.getByRole('button', { name: 'Moodle account link', exact: true }).click();
    await page.getByLabel('Moodle user ID', { exact: true }).fill('123');
    await page.getByLabel('Reason for linking', { exact: true }).fill('Fictional initial setup.');
    await page.getByRole('button', { name: 'Review Moodle account link', exact: true }).click();
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Approve reviewed person link', exact: true }).click();
    await expect(page.locator('[data-moodle-link-status]')).toContainText('Reload saved details');
    await page.getByRole('button', { name: 'Refresh saved link', exact: true }).click();
    await expect(page.locator('[data-moodle-link-status]')).toContainText('link saved');
    expect(f.calls.filter(c => c.path.endsWith('/confirm/'))).toHaveLength(1);
    expect(f.errors).toEqual([]);
});
test('unapproved installation makes no account lookup', async ({ page }) => {
    const f = await fixture(page, { unapproved: true });
    await page.getByRole('button', { name: 'Moodle account link', exact: true }).click();
    await expect(page.locator('[data-moodle-link-status]')).toContainText('approve the institutional installation');
    await expect(page.getByLabel('Moodle user ID', { exact: true })).toHaveCount(0);
    expect(f.calls.some(c => /\/(review|confirm)\//.test(c.path))).toBe(false);
    expect(f.errors).toEqual([]);
});
test('Moodle administration permission alone does not show the person-link action', async ({ page }) => {
    const f = await fixture(page, { denied: true });
    await expect(page.getByRole('button', { name: 'Moodle account link', exact: true })).toHaveCount(0);
    expect(f.calls.some(c => c.path.includes('/moodle/'))).toBe(false);
    expect(f.errors).toEqual([]);
});
