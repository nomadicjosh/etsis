const { revealRecordAction } = require('./support/workspace-actions.cjs');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

test('private setup link stays out of URLs and storage and submits through a CSRF form', async ({ page }) => {
    const token = 'a'.repeat(64);
    const requests = [];
    page.on('request', request => requests.push(request.url()));
    await page.goto(`/portal/password-setup/?guest=1#${token}`);
    await expect(page).toHaveURL(/\/portal\/password-setup\/$/);
    await expect(page.locator('#account-setup-token')).toHaveValue(token);
    await page.getByLabel('New password', { exact: true }).fill('a-fictional-campus-password-long-enough');
    await page.getByLabel('Confirm password', { exact: true }).fill('a-fictional-campus-password-long-enough');
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations).toEqual([]);
    const sent = page.waitForRequest(request => request.method() === 'POST');
    await page.getByRole('button', { name: 'Set password', exact: true }).click();
    const body = new URLSearchParams((await sent).postData());
    expect(body.get('csrf_token')).toBe('fictional-test-token');
    expect(body.get('token')).toBe(token);
    await expect(page.getByRole('heading', { name: 'Your password is ready' })).toBeVisible();
    expect(requests.every(url => !url.includes(token))).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test('missing setup link cannot submit a password', async ({ page }) => {
    await page.goto('/portal/password-setup/?guest=1');
    await expect(page.getByRole('button', { name: 'Set password', exact: true })).toBeDisabled();
    await expect(page.getByRole('status')).toContainText('Open the setup link');
});

test('staff create one person and account without assigning an initial password', async ({ page }) => {
    const person = '01K00000000000000000000001', user = '01K00000000000000000000002';
    const operation = '01K00000000000000000000003';
    let creation;
    await page.route('**/sis/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = { items: [], next_after: null };
        else if (path === '/sis/ui/operation-id/') result = { id: operation };
        else if (path === '/sis/persons/') {
            expect(request.headers()['x-csrf-token']).toBe('fictional-test-token');
            creation = JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json'));
            result = { id: person };
        } else if (path === `/sis/ui/people/${person}/`) result = {
            person: { id: { value: person }, ownerUserId: { value: user }, givenName: 'Alex', familyName: 'Example', institutionalId: { value: 'S-EXAMPLE' } },
            students: [], employees: [], applications: [], accounts: []
        };
        await route.fulfill({ json: result });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="people"]').click();
    await page.getByRole('button', { name: 'Add a person', exact: true }).click();
    await page.getByLabel('Given name').fill('Alex');
    await page.getByLabel('Family name').fill('Example');
    await page.getByLabel('Email address').fill('alex@example.invalid');
    expect(await page.locator('#drawer-body input[type="password"]').count()).toBe(0);
    await page.getByRole('button', { name: 'Save and continue', exact: false }).click();
    await expect(page.locator('#drawer-body')).toContainText('Alex Example');
    expect(creation).toEqual({ given_name: 'Alex', family_name: 'Example', email: 'alex@example.invalid', operation_id: operation });
    await expect(await revealRecordAction(page, 'Account setup')).toBeVisible();
});
