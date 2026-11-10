const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = number => String(number).padStart(26, '0');
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
}

test('person edits, linked account maintenance and bounded access controls', async ({ page }) => {
    const person = id(1); const user = id(2);
    const calls = []; const errors = []; let given = 'Alex'; let email = 'alex@example.invalid'; let version = 0;
    const grants = new Set();
    page.on('pageerror', error => errors.push(error.message));
    const profile = () => ({ id: { value: person }, ownerUserId: { value: user }, givenName: given, familyName: 'Example', institutionalId: { value: 'S-EXAMPLE' }, directoryRestricted: true, identityVersion: 0 });
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const body = route.request().postData();
        const data = body ? JSON.parse(new URLSearchParams(body).get('_workspace_json')) : null;
        if (data || route.request().method() !== 'GET') calls.push({ path, data, method: route.request().method() });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = { items: path.includes('/people/') ? [{ id: person, given_name: given, family_name: 'Example', institutional_id: 'S-EXAMPLE', owner_user_id: user }] : [], next_after: null };
        else if (path === `/sis/ui/people/${person}/`) result = { person: profile(), students: [], applications: [], employees: [], accounts: [] };
        else if (path === `/sis/ui/people/${person}/profile/`) {
            if (data) given = data.given_name;
            result = data ? { status: 'saved' } : { person: profile(), revision: 'fictional-profile-revision' };
        } else if (path === `/sis/ui/people/${person}/account/`) result = { username: 'alex_example', email, version, minimum_password_length: 26, is_self: false };
        else if (path.endsWith('/account/email/')) { email = data.email; version++; }
        else if (path.endsWith('/account/password/')) version++;
        else if (path.startsWith('/sis/access/directory/')) {
            const roles = path.endsWith('/roles/');
            let items = Array.from({ length: 1000 }, (_, index) => ({ id: id(index + 100), name: roles ? `office-${index}` : `campus:permission:${index}`, description: 'Fictional definition', is_system: false, assigned: grants.has(id(index + 100)), effective: index === 1 || grants.has(id(index + 100)), assignable: true }));
            if (data.search) items = items.filter(item => item.name.includes(data.search));
            if (data.assigned_only) items = items.filter(item => item.assigned);
            if (data.after) items = items.filter(item => item.id > data.after);
            result = { items: items.slice(0, data.limit), next_after: items.length > data.limit ? items[data.limit - 1].id : null };
        } else if (/\/sis\/access\/roles\/[^/]+\/permissions\/[^/]+\/$/.test(path)) {
            const target = path.split('/').at(-2);
            if (route.request().method() === 'PUT') grants.add(target); else grants.delete(target);
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="people"]').click();
    await page.locator('#directory-search').fill('a');
    await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    expect(calls.filter(item => item.path.startsWith('/sis/access/directory/'))).toHaveLength(0);
    await page.getByRole('button', { name: 'Edit person profile', exact: true }).click();
    await page.getByLabel('Given name').fill('Alexandra');
    await page.getByLabel('Restrict public directory disclosure').uncheck();
    await accessible(page);
    await page.getByRole('button', { name: 'Save person profile', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Alexandra Example');
    expect(calls.find(item => item.path.endsWith('/profile/'))?.data).toMatchObject({ given_name: 'Alexandra', directory_restricted: false, revision: 'fictional-profile-revision' });
    await page.getByRole('button', { name: 'Manage sign-in account', exact: true }).click();
    await page.getByRole('button', { name: 'Change sign-in email', exact: true }).click();
    await page.getByLabel('Email address').fill('new@example.invalid');
    await page.getByRole('button', { name: 'Save sign-in email', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('new@example.invalid');
    await page.getByRole('button', { name: 'Reset password', exact: true }).click();
    await page.getByLabel('New password (at least 26 characters)', { exact: false }).fill('fictional-new-password-for-an-account');
    await page.getByLabel('Confirm new password').fill('fictional-new-password-for-an-account');
    await accessible(page);
    await page.getByRole('button', { name: 'Save new password', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('alex_example');
    expect(calls.find(item => item.path.endsWith('/account/password/'))?.data.version).toBe(1);
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.locator('.workspace-nav-link[data-workspace="access"]').click();
    await expect(page.locator('#access-roles .role-card')).toHaveCount(50);
    await page.getByRole('button', { name: 'Next page', exact: true }).click();
    await expect(page.locator('#access-roles')).toContainText('office-50');
    await page.getByRole('button', { name: 'Previous page', exact: true }).click();
    await expect(page.locator('#access-roles')).toContainText('office-0');
    await page.locator('#access-roles .role-card').first().click();
    await expect(page.locator('#drawer-body .permission-picker input[type="checkbox"]')).toHaveCount(50);
    await expect(page.locator('#drawer-body')).toContainText('Effective through a role or permission group.');
    await page.getByRole('checkbox', { name: /^campus:permission:0\b/ }).check();
    await expect.poll(() => calls.filter(item => item.method === 'PUT').length).toBe(1);
    await expect(page.locator('#drawer-body .permission-picker input[type="checkbox"]')).toHaveCount(50);
    await page.getByLabel('Show direct assignments only').check();
    await expect(page.locator('#drawer-body .permission-picker input[type="checkbox"]')).toHaveCount(1);
    await accessible(page);
    expect(calls.filter(item => item.path.startsWith('/sis/access/directory/')).every(item => item.data.limit === 50)).toBe(true);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('staff review original application answers and revoke report sharing', async ({ page }) => {
    const app = id(20); const person = id(21); const report = id(22); const user = id(23);
    let status = 'submitted'; let shared = true; const sent = [];
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) sent.push({ path, data });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = { items: path.includes('/applications/') ? [{ id: app, given_name: 'Jamie', family_name: 'Example', status }] : path.includes('/reports/') ? [{ id: report, name: 'Fictional student report' }] : [], next_after: null };
        else if (path === `/sis/applications/${app}/review/`) result = { application: { id: { value: app }, status, version: 0 }, person: { id: { value: person }, givenName: 'Jamie', familyName: 'Example', institutionalId: { value: 'S-EXAMPLE' } }, labels: { program_code: 'BSCS', program_title: 'Computing', term_code: '26/FA' }, submission: { form: { name: 'Original application revision', fields: [{ key: 'returning', label: 'Returning student?', type: 'checkbox' }, { key: 'statement', label: 'Personal statement', type: 'textarea' }] }, answers: { returning: false, statement: '<script>fictional</script> A personal statement.' } } };
        else if (path.endsWith('/decision/review/')) result = {status: 'accepted', source: {program_code: 'BSCS', program_title: 'Computing', entry_term_code: '26/FA', submission: {form_name: 'Original application revision', form_version: 1}}, person: {given_name: 'Jamie', family_name: 'Example', institutional_id: 'S-EXAMPLE'}, effective_on: '2026-08-10', review_token: 'fictional-acceptance-token'};
        else if (path.endsWith('/decision/commit/')) { status = 'accepted'; result = { status }; }
        else if (path === `/sis/reports/${report}/`) result = { id: report, name: 'Fictional student report', active: null, can_manage: true, can_run: true };
        else if (path.endsWith('/shares/list/')) result = { items: shared ? [{ id: id(24), user_id: user, given_name: 'Alex', family_name: 'Example' }] : [], next_after: null };
        else if (path.endsWith('/revoke/')) shared = false;
        await route.fulfill({ json: result });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="applications"]').click();
    await page.getByRole('button', { name: 'Jamie Example', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Original application revision');
    await expect(page.locator('#drawer-body')).toContainText('No');
    await expect(page.locator('#drawer-body')).toContainText('<script>fictional</script>');
    expect(await page.locator('#drawer-body script').count()).toBe(0);
    await accessible(page);
    await page.getByRole('button', { name: 'Accept application', exact: true }).click();
    await page.getByLabel('Reason for admissions decision').fill('Fictional reviewed acceptance');
    await page.getByRole('button', { name: 'Review admissions decision', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm acceptance', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Matriculate student', exact: true })).toBeVisible();
    expect(sent.find(item => item.path.endsWith('/decision/review/'))?.data).toEqual({ decision: 'accept', reason: 'Fictional reviewed acceptance' });
    expect(sent.find(item => item.path.endsWith('/decision/commit/'))?.data).toEqual({ review_token: 'fictional-acceptance-token' });
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.locator('.workspace-nav-link[data-workspace="reports"]').click();
    await page.getByRole('button', { name: 'Fictional student report', exact: true }).click();
    await page.getByRole('button', { name: 'Manage report sharing', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Alex Example');
    await accessible(page);
    await page.getByRole('button', { name: 'Revoke share', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm share revocation', exact: true }).click();
    await expect.poll(() => sent.filter(item => item.path.endsWith('/revoke/')).length).toBe(1);
    await page.getByRole('button', { name: 'Manage report sharing', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('This report has no explicit shares.');
});

test('My profile matches the campus workspace and submits only owner profile fields', async ({ page }) => {
    const posted = [];
    await page.route('**/admin/profile/update/', async route => {
        const body = new URLSearchParams(route.request().postData());
        posted.push(Object.fromEntries(body));
        await route.fulfill({ contentType: 'text/html', body: '<!doctype html><html lang="en"><title>Fictional saved profile</title><body><main><h1>Fictional profile saved</h1></main></body></html>' });
    });
    await page.goto('/admin/profile/');
    await expect(page.getByRole('heading', { name: 'My profile.' })).toBeVisible();
    await expect(page.getByLabel('Username')).toHaveValue('alex_example');
    await expect(page.getByLabel('Username')).toHaveAttribute('readonly', '');
    expect(await page.locator('[name="role"], [name="user_id"]').count()).toBe(0);
    await accessible(page);
    await page.getByLabel('New password', { exact: true }).fill('fictional-owner-profile-password');
    await page.getByLabel('Confirm new password').fill('different-fictional-owner-password');
    await page.getByRole('button', { name: 'Save my password', exact: true }).click();
    await expect(page.locator('#profile-password-error')).toBeVisible();
    expect(posted).toHaveLength(0);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    await page.getByLabel('Given name').fill('A');
    await page.getByRole('button', { name: 'Save my details', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Fictional profile saved' })).toBeVisible();
    expect(posted[0]).toMatchObject({ first_name: 'A', last_name: 'Example', email: 'alex@example.invalid', csrf_token: 'fictional-test-token', _method: 'PUT' });
    expect(posted[0].role).toBeUndefined(); expect(posted[0].user_id).toBeUndefined();
});
