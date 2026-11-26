const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const target = 'a'.repeat(64);
const profile = { id, name: 'Fictional prospect list', provider: 'webhook', enabled: true, version: 0, target_hash: target };
async function accessibility(page, scope = null) {
    const scan = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']);
    if (scope) scan.include(scope);
    expect((await scan.analyze()).violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}
test('connection setup encrypts write-only credentials through the private API and permits safe pause and rotation', async ({ page }) => {
    let stored = null, posts = [];
    await page.route('**/sis/website/newsletters/**', async route => {
        const request = route.request();
        if (request.method() === 'POST') {
            const body = JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json'));
            expect(request.headers()['x-csrf-token']).toBe('fictional-test-token');
            posts.push(body);
            stored = { ...profile, name: body.name, enabled: body.enabled, version: body.version === null ? 0 : body.version + 1 };
            return route.fulfill({ json: stored });
        }
        if (new URL(request.url()).pathname.endsWith(`${id}/`)) return route.fulfill({ json: { ...stored, host: 'newsletter.example.invalid', list: 'prospects', credentials_set: true } });
        return route.fulfill({ json: { items: stored ? [stored] : [], next_after: null, delivery_enabled: false } });
    });
    await page.goto('/fixture/website-manager/?mode=newsletters');
    await page.getByRole('button', { name: 'Add connection', exact: true }).click();
    await page.getByLabel('Connection name', { exact: true }).fill('Fictional prospect list');
    await page.getByLabel('Service', { exact: true }).selectOption('webhook');
    await page.getByLabel('HTTPS service URL', { exact: true }).fill('https://newsletter.example.invalid/subscribe');
    await page.getByLabel('List, audience or segment reference', { exact: true }).fill('prospects');
    await page.getByLabel('API key, password or signing secret', { exact: true }).fill('fictional-signing-secret-at-least-32-characters');
    await page.getByLabel('The webhook receiver honors Idempotency-Key', { exact: true }).check();
    await page.getByLabel('Enable this connection', { exact: true }).check();
    await page.getByRole('button', { name: 'Save connection', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Edit connection', exact: true })).toBeVisible();
    expect(posts[0]).toMatchObject({ id: null, version: null, provider: 'webhook', idempotent_receiver: true, enabled: true });
    await page.getByRole('button', { name: 'Edit connection', exact: true }).click();
    await expect(page.getByLabel('API key, password or signing secret', { exact: true })).toHaveValue('');
    await page.getByLabel('Enable this connection', { exact: true }).uncheck();
    await page.getByRole('button', { name: 'Save connection', exact: true }).click();
    await expect.poll(() => posts.length).toBe(2);
    expect(posts[1]).toMatchObject({ id, version: 0, secret: '', enabled: false });
    expect(posts[1]).not.toHaveProperty('url');
    await page.setViewportSize({ width: 320, height: 900 });
    await accessibility(page);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
test('native designer maps a newsletter only to an explicit optional consent field and retains the approved destination', async ({ page }) => {
    await page.route('https://**', route => route.abort());
    await page.route('**/sis/website/form-access/choices/**', route => route.fulfill({ json: { items: [], next_after: null } }));
    await page.route('**/sis/website/newsletters/**', route => route.fulfill({ json: { items: [profile], next_after: null, delivery_enabled: true } }));
    await page.goto('/fixture/website-editor/?forms=1');
    await expect(page.locator('#phpb-loading')).toHaveClass(/loaded/);
    await expect.poll(() => page.evaluate(() => !!window.afterInitialRendering)).toBe(true);
    await page.frameLocator('iframe.gjs-frame').getByRole('heading', { name: 'Contact us', exact: true }).click();
    await page.getByRole('button', { name: 'Edit website form', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByLabel('Form title', { exact: true })).toHaveCSS('color', 'rgb(23, 52, 78)');
    await dialog.getByLabel('Newsletter connection', { exact: true }).selectOption(id);
    await expect(dialog.getByLabel('Email field', { exact: true })).toHaveValue('email');
    await expect(dialog.getByLabel('Newsletter consent field', { exact: true }).locator('option[value="consent"]')).toHaveCount(0);
    await dialog.getByRole('button', { name: 'Add newsletter consent checkbox', exact: true }).click();
    await expect(dialog.getByLabel('Newsletter consent field', { exact: true })).toHaveValue('newsletter_opt_in');
    await dialog.getByLabel('Name field (optional)', { exact: true }).selectOption('name');
    await dialog.getByRole('button', { name: 'Use this form design', exact: true }).click();
    await expect(dialog).toBeHidden();
    const saved = await page.evaluate(() => JSON.parse(window.editor.getSelected().getAttributes()['website-definition']));
    expect(saved.newsletter).toEqual({ connection_id: id, target_hash: target, email_field: 'email', name_field: 'name', consent_field: 'newsletter_opt_in' });
    expect(saved.fields.find(field => field.name === 'newsletter_opt_in').required).toBe(false);
    expect(JSON.stringify(saved)).not.toContain('secret');
});
test('the existing inquiry drawer shows uncertain newsletter delivery and queues an explicit versioned retry', async ({ page }) => {
    let state = 'review', issue = 'uncertain', posts = 0;
    const inquiry = { id, page_title: 'Fictional page', form_title: 'Fictional inquiry', submitted_at: '2026-11-26T12:00:00Z', status: 'open', version: 0 };
    await page.route('**/sis/website/form-inbox/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        if (request.method() === 'POST') {
            const body = JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json'));
            expect(body).toEqual({ version: 4 }); expect(path).toContain('/newsletter/retry/');
            expect(request.headers()['x-csrf-token']).toBe('fictional-test-token');
            posts++; state = 'queued'; issue = 'none'; return route.fulfill({ json: { queued: true } });
        }
        if (path.endsWith(`${id}/`)) return route.fulfill({ json: { ...inquiry,
            fields: [{ name: 'email', label: 'Email address', options: [] }], answers: { email: 'fictional@example.invalid' },
            newsletter: { state, issue, version: 4, delivery_enabled: true }
        } });
        return route.fulfill({ json: { items: [inquiry], next_after: null, can_manage: true } });
    });
    await page.goto('/fixture/website-manager/?mode=form-inbox');
    await page.getByRole('button', { name: 'Read inquiry', exact: true }).click();
    const drawer = page.locator('#site-review');
    await expect(drawer).toContainText('Needs review');
    await drawer.getByRole('button', { name: 'Queue subscription retry', exact: true }).click();
    await expect(drawer).toContainText('Check the provider first');
    await drawer.getByRole('button', { name: 'Back to inquiry', exact: true }).click();
    expect(posts).toBe(0);
    await drawer.getByRole('button', { name: 'Queue subscription retry', exact: true }).click();
    await drawer.getByRole('button', { name: 'Confirm subscription retry', exact: true }).click();
    await expect(drawer).toContainText('Queued');
    expect(posts).toBe(1);
});
test('lost integration permission clears an unfinished credential form', async ({ page }) => {
    await page.route('**/sis/website/newsletters/**', route => route.request().method() === 'POST'
        ? route.fulfill({ status: 403, json: { error: 'Access denied.' } })
        : route.fulfill({ json: { items: [], next_after: null, delivery_enabled: false } }));
    await page.goto('/fixture/website-manager/?mode=newsletters');
    await page.getByRole('button', { name: 'Add connection', exact: true }).click();
    await page.getByLabel('Connection name', { exact: true }).fill('Fictional denied connection');
    await page.getByLabel('HTTPS service URL', { exact: true }).fill('https://newsletter.example.invalid');
    await page.getByLabel('List, audience or segment reference', { exact: true }).fill('prospects');
    await page.getByLabel('API key, password or signing secret', { exact: true }).fill('fictional-secret');
    await page.getByRole('button', { name: 'Save connection', exact: true }).click();
    await expect(page.locator('#site-manager')).toBeEmpty();
    await expect(page.locator('input[type="password"]')).toHaveCount(0);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
