const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const privateHeaders = { 'Cache-Control': 'private, no-store' };

async function prepare(page) {
    await page.route('**/portal/api/website-forms/**/prepare/**', route => route.fulfill({ json: {
        ticket: 'fictional-ticket', csrf: 'fictional-token', csrf_header: 'X-CSRF-Token',
    }, headers: privateHeaders }));
}

for (const theme of ['campus-harbor', 'campus-quad', 'campus-horizon', 'bootstrap-business']) {
    test(`visitor input purposes retain labels and separate form scopes in ${theme}`, async ({ page }) => {
        await prepare(page);
        await page.setViewportSize({ width: 320, height: 900 });
        await page.goto(`/fixture/website-blocks/?forms=1&purposes=1&second-form=1&count=1&theme=${theme}`);
        const forms = page.locator('[data-website-form]');
        await expect(forms).toHaveCount(2);
        const scopes = [];
        for (let index = 0; index < 2; index++) {
            const form = forms.nth(index);
            await expect(form.getByRole('button', { name: 'Send message', exact: true })).toBeEnabled();
            const scope = (await form.getByLabel(/Your name/).getAttribute('autocomplete')).split(' ')[0];
            expect(scope).toMatch(/^section-wf[a-f0-9]{24}$/);
            scopes.push(scope);
            for (const [label, purpose] of [['Your name', 'name'], ['Email address', 'email'],
                ['Contact telephone', 'tel'], ['Contact street address', 'street-address'],
                ['Contact city', 'address-level2'], ['Contact postal code', 'postal-code']]) {
                await expect(form.getByLabel(new RegExp(label))).toHaveAttribute('autocomplete', `${scope} ${purpose}`);
            }
            await expect(form.getByLabel(/Message/)).not.toHaveAttribute('autocomplete');
            await form.getByLabel(/Contact postal code/).fill('00123');
            await expect(form.getByLabel(/Contact postal code/)).toHaveValue('00123');
        }
        expect(scopes[0]).not.toBe(scopes[1]);
        await page.addStyleTag({ content: 'html { font-size: 200%; }' });
        expect((await new AxeBuilder({ page }).include('[data-website-form]')
            .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze()).violations
            .map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    });
}

test('designer preserves purposes through keyboard ordering, preview and reopening and clears incompatible types', async ({ page }) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.route('https://**', route => route.abort());
    await page.route('**/sis/website/form-access/choices/**', route => route.fulfill({ json: {
        items: [], next_after: null,
    }, headers: privateHeaders }));
    await page.goto('/fixture/website-editor/?forms=1');
    await expect(page.locator('#phpb-loading')).toHaveClass(/loaded/);
    await expect.poll(() => page.evaluate(() => !!window.afterInitialRendering)).toBe(true);
    await page.frameLocator('iframe.gjs-frame').getByRole('heading', { name: 'Contact us', exact: true }).click();
    await page.getByRole('button', { name: 'Edit website form', exact: true }).click();
    const dialog = page.getByRole('dialog');
    const card = index => dialog.locator(`[data-field-position="${index}"]`);
    await card(0).getByLabel('Input purpose', { exact: true }).selectOption('given-name');
    await card(0).getByRole('button', { name: 'Duplicate field', exact: true }).click();
    await expect(card(1).getByLabel('Input purpose', { exact: true })).toHaveValue('given-name');
    await card(1).getByRole('button', { name: 'Move field down', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(card(2).getByLabel('Input purpose', { exact: true })).toHaveValue('given-name');
    await card(2).getByRole('button', { name: 'Remove field', exact: true }).click();
    await card(0).getByLabel('Field type', { exact: true }).selectOption('email');
    await expect(card(0).getByLabel('Field type', { exact: true })).toBeFocused();
    await expect(card(0).getByLabel('Input purpose', { exact: true })).toHaveValue('');
    await expect(dialog.locator('[data-designer-status]')).toHaveText(/Input purpose cleared/);
    await card(0).getByLabel('Field type', { exact: true }).selectOption('text');
    await card(0).getByLabel('Input purpose', { exact: true }).selectOption('family-name');
    await dialog.getByRole('button', { name: 'Preview form', exact: true }).click();
    await expect(dialog.locator('[data-designer-preview]').getByLabel(/Your name/))
        .toHaveAttribute('autocomplete', /^section-wf[a-f0-9]{24} family-name$/);
    await expect(dialog.getByRole('button', { name: 'Send message', exact: true })).toBeDisabled();
    await dialog.getByRole('button', { name: 'Back to form fields', exact: true }).click();
    await dialog.getByRole('button', { name: 'Use this form design', exact: true }).click();
    await expect(dialog).toBeHidden();
    expect(await page.evaluate(() => JSON.parse(window.editor.getSelected().getAttributes()['website-definition'])
        .fields[0].input_purpose)).toBe('family-name');
    await page.getByRole('button', { name: 'Edit website form', exact: true }).click();
    await expect(card(0).getByLabel('Input purpose', { exact: true })).toHaveValue('family-name');
    await page.setViewportSize({ width: 320, height: 900 });
    expect((await new AxeBuilder({ page }).include('.website-form-designer')
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze()).violations
        .map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('autofilled DOM values are read on submit even without input events and hidden answers stay excluded', async ({ page }) => {
    await prepare(page);
    await page.goto('/fixture/website-blocks/?forms=1&purposes=1&count=1');
    const form = page.locator('[data-website-form]');
    await expect(form.getByRole('button', { name: 'Send message', exact: true })).toBeEnabled();
    await form.evaluate(form => {
        const values = { name: 'Fictional Alex', email: 'fictional@example.invalid', message: 'A fictional inquiry.',
            topic: 'general', postal: '00123', visit_date: '2026-12-10' };
        for (const [name, value] of Object.entries(values)) form.querySelector(`[name="${name}"]`).value = value;
        form.querySelector('[name="consent"]').checked = true;
    });
    let sent;
    await page.route('**/portal/api/website-forms/**/submit/', route => {
        sent = JSON.parse(new URLSearchParams(route.request().postData()).get('_workspace_json'));
        return route.fulfill({ json: { received: true, message: 'Fictional inquiry received.' }, headers: privateHeaders });
    });
    await form.getByRole('button', { name: 'Send message', exact: true }).click();
    await expect(form.locator('[data-form-status]')).toHaveText('Fictional inquiry received.');
    expect(sent.answers).toMatchObject({ name: 'Fictional Alex', email: 'fictional@example.invalid', postal: '00123' });
    expect(sent.answers).not.toHaveProperty('visit_date');
    expect(sent.answers).not.toHaveProperty('input_purpose');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
