const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const privateHeaders = { 'Cache-Control': 'private, no-store' };
async function scopeChoices(page) {
    await page.route('**/sis/website/form-access/choices/**', route => {
        const kind = new URL(route.request().url()).searchParams.get('kind');
        const items = kind === 'department' ? [{ id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', code: 'ADV', title: 'Fictional advising' }]
            : kind === 'page' ? [{ id: '1', name: 'Fictional contact page' }]
            : [{ id: '01ARZ3NDEKTSV4RRFFQ69G5FAW', given_name: 'Fictional', family_name: 'Alex', institutional_id: 'F-101' }];
        return route.fulfill({ json: { items, next_after: null }, headers: privateHeaders });
    });
}
async function publicForm(page, theme = 'campus-harbor') {
    await page.route('**/portal/api/website-forms/**/prepare/**', route => route.fulfill({ json: {
        ticket: 'fictional-ticket', csrf: 'fictional-token', csrf_header: 'X-CSRF-Token'
    }, headers: privateHeaders }));
    await page.goto(`/fixture/website-blocks/?forms=1&count=1&theme=${theme}`);
    const form = page.locator('[data-website-form]');
    await expect(form.getByRole('button', { name: 'Send message', exact: true })).toBeEnabled();
    return form;
}
async function answer(form) {
    await form.getByLabel(/Your name/).fill('Fictional Alex');
    await form.getByLabel(/Email address/).fill('fictional@example.invalid');
    await form.getByLabel(/Message/).fill('A fictional private inquiry.');
    await form.getByLabel(/Inquiry topic/).selectOption('general');
    await form.getByLabel(/Please contact me about my inquiry/).check();
}
async function accessible(page, scope = null) {
    const builder = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']);
    if (scope) builder.include(scope);
    const scan = await builder.analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}
test('published native forms validate, clear hidden answers, and retry the identical private message', async ({ page }) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const form = await publicForm(page);
    await form.getByRole('button', { name: 'Send message', exact: true }).click();
    await expect(form.locator('[data-form-errors]')).toBeFocused();
    await form.locator('[data-form-errors] a').first().click();
    await expect(form.getByLabel(/Your name/)).toBeFocused();
    await answer(form);
    await form.getByLabel(/Inquiry topic/).selectOption('visit');
    await form.getByLabel(/Preferred visit date/).fill('2026-12-10');
    await form.getByLabel(/Inquiry topic/).selectOption('general');
    await expect(form.getByLabel(/Preferred visit date/)).toHaveValue('');
    await expect(form.locator('[data-question="visit_date"]')).toBeHidden();
    let first, attempts = 0;
    await page.route('**/portal/api/website-forms/**/submit/', async route => {
        const raw = new URLSearchParams(route.request().postData()).get('_workspace_json');
        const body = JSON.parse(raw); expect(body.answers).not.toHaveProperty('visit_date');
        expect(route.request().headers()['x-csrf-token']).toBe('fictional-token');
        attempts++;
        if (attempts === 1) { first = raw; return route.abort(); }
        expect(raw).toBe(first);
        return route.fulfill({ json: { received: true, message: 'Fictional message received.' }, headers: privateHeaders });
    });
    await form.getByRole('button', { name: 'Send message', exact: true }).click();
    await expect(form.getByRole('button', { name: 'Try again', exact: true })).toBeEnabled();
    await expect(form.getByLabel(/Your name/)).toBeDisabled();
    await form.getByRole('button', { name: 'Try again', exact: true }).click();
    await expect(form.locator('[data-form-status]')).toHaveText('Fictional message received.');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});
test('public forms keep readable labels and focus at narrow width in inherited themes', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 900 });
    await publicForm(page, 'campus-quad');
    await accessible(page, '[data-website-form]');
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await accessible(page, '[data-website-form]');
});
test('native website form designer supports fields, department routing, preview and saving only the draft', async ({ page }) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.route('https://**', route => route.abort());
    await scopeChoices(page);
    await page.goto('/fixture/website-editor/?forms=1');
    await expect(page.locator('#phpb-loading')).toHaveClass(/loaded/);
    await expect.poll(() => page.evaluate(() => !!window.afterInitialRendering)).toBe(true);
    await page.frameLocator('iframe.gjs-frame').getByRole('heading', { name: 'Contact us', exact: true }).click();
    await page.getByRole('button', { name: 'Edit website form', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('Replies department (optional)', { exact: true }).selectOption('01ARZ3NDEKTSV4RRFFQ69G5FAV');
    await dialog.getByLabel('Form title', { exact: true }).fill('Fictional advising inquiry');
    await dialog.getByRole('button', { name: 'Duplicate field', exact: true }).first().click();
    await expect(dialog.locator('[data-field-position]')).toHaveCount(7);
    await dialog.getByRole('button', { name: 'Remove field', exact: true }).nth(1).click();
    await dialog.getByRole('button', { name: 'Preview form', exact: true }).click();
    await expect(dialog.locator('[data-designer-preview]')).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Send message', exact: true })).toBeDisabled();
    await dialog.getByRole('button', { name: 'Back to form fields', exact: true }).click();
    await dialog.getByRole('button', { name: 'Use this form design', exact: true }).click();
    await expect(dialog).toBeHidden();
    await page.getByRole('button', { name: 'Edit website form', exact: true }).click();
    await page.setViewportSize({ width: 320, height: 900 });
    await accessible(page, '.website-form-designer');
    await page.screenshot({ path: '/tmp/etsis-website-forms-designer-mobile.png' });
    await dialog.getByRole('button', { name: 'Close form designer', exact: true }).click();
    const saved = await page.evaluate(() => JSON.parse(window.editor.getSelected().getAttributes()['website-definition']));
    expect(saved.title).toBe('Fictional advising inquiry');
    expect(saved.department_id).toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV');
    expect(errors).toEqual([]);
});
test('reply access setup selects department or exact form and explicitly grants and removes an eligible account', async ({ page }) => {
    await scopeChoices(page);
    let granted = false;
    await page.route('**/sis/website/form-access/forms/**', route => route.fulfill({ json: {
        items: [{ key: 'contact', title: 'Fictional contact', department_id: null }]
    } }));
    await page.route('**/sis/website/form-access/members/**', async route => {
        if (route.request().method() === 'POST') {
            const body = JSON.parse(new URLSearchParams(route.request().postData()).get('_workspace_json'));
            expect(body).toMatchObject({ kind: 'department', scope: '01ARZ3NDEKTSV4RRFFQ69G5FAV', form: '' });
            expect(route.request().headers()['x-csrf-token']).toBe('fictional-test-token');
            granted = body.grant;
            return route.fulfill({ json: { saved: true } });
        }
        return route.fulfill({ json: { items: granted ? [{ id: '01ARZ3NDEKTSV4RRFFQ69G5FAX', user_id: '01ARZ3NDEKTSV4RRFFQ69G5FAW',
            given_name: 'Fictional', family_name: 'Alex' }] : [], next_after: null } });
    });
    await page.goto('/fixture/website-manager/?mode=form-access');
    await page.getByRole('heading', { name: 'Department inbox members', exact: true }).locator('..').getByRole('button', { name: 'Search', exact: true }).click();
    await page.getByRole('button', { name: 'ADV · Fictional advising', exact: true }).click();
    const accounts = page.getByRole('heading', { name: 'Choose an enabled account with read permission', exact: true }).locator('..');
    await accounts.getByRole('button', { name: 'Search', exact: true }).click();
    await accounts.getByRole('button', { name: 'Fictional Alex · F-101', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Remove access', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Remove access', exact: true }).click();
    await expect(page.getByText('No accounts have been assigned.', { exact: true })).toBeVisible();
    await page.setViewportSize({ width: 320, height: 900 });
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-website-forms-access-mobile.png', fullPage: true });
});
test('private inbox renders replies as text, returns from delete confirmation and permanently deletes', async ({ page }) => {
    let removed = false;
    const item = { id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', page_title: 'Fictional contact page', form_title: 'Fictional contact', status: 'open', submitted_at: '2026-11-26T12:00:00Z', version: 0 };
    await page.route('**/sis/website/form-inbox/**', route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        if (request.method() === 'POST') { removed = true; return route.fulfill({ json: { deleted: true } }); }
        if (path.endsWith(`${item.id}/`)) return route.fulfill({ json: { ...item,
            fields: [{ name: 'message', label: 'Message', options: [] }], answers: { message: '<img src=x onerror=alert(1)> Fictional private message' }
        } });
        return route.fulfill({ json: { items: removed ? [] : [item], next_after: null, can_manage: true } });
    });
    await page.goto('/fixture/website-manager/?mode=form-inbox');
    await page.getByRole('button', { name: 'Read inquiry', exact: true }).click();
    const drawer = page.locator('#site-review');
    await expect(drawer).toContainText('<img src=x onerror=alert(1)>');
    await expect(drawer.locator('img')).toHaveCount(0);
    await drawer.getByRole('button', { name: 'Delete inquiry', exact: true }).click();
    await drawer.getByRole('button', { name: 'Back to inquiry', exact: true }).click();
    await expect(drawer).toContainText('Fictional private message');
    await drawer.getByRole('button', { name: 'Delete inquiry', exact: true }).click();
    await drawer.getByRole('button', { name: 'Permanently delete inquiry', exact: true }).click();
    expect(removed).toBe(true);
    await expect(page.locator('#site-manager')).toContainText('There are no matching website inquiries.');
});
