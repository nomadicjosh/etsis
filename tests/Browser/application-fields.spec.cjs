const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const program = '01K00000000000000000000001', term = '01K00000000000000000000002', person = '01K00000000000000000000003';
const formId = '01K00000000000000000000008', receipt = '01K00000000000000000000009';
const fields = [
    { key: 'route', label: 'Application route', type: 'radio', required: true, options: ['New student', 'Transfer'] },
    { key: 'goals', label: 'Your goals', type: 'textarea', required: true, help: 'A short fictional statement.', settings: { min_length: 3, max_length: 5, placeholder: 'Your next step' } },
    { key: 'credits', label: 'Previous credits', type: 'number', required: true, settings: { min_value: 1, max_value: 6 }, show_when: { condition: 'AND', rules: [{ id: 'route', operator: 'equal', value: 'Transfer' }] } },
];
const layout = [{ key: 'about', label: 'About you', help: 'Tell us about your plans.', columns: [['route'], ['goals', 'credits']] }];
async function fixture(page, reject = false) {
    const posts = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route(/\/(?:portal\/api|sis\/forms)\//, async route => {
        const req = route.request(), path = new URL(req.url()).pathname;
        let json = {};
        if (path === '/portal/api/me/') json = { person: { id: person }, applications: [], students: [], contact: {}, accounts: [], news: [] };
        else if (path === `/sis/forms/programs/${program}/active/`) json = { id: formId, name: 'Diploma application', submission_id: receipt, fields, layout };
        else if (path === `/sis/forms/${formId}/submissions/`) {
            const input = JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json'));
            expect(req.headers()['x-csrf-token']).toBe('fictional-test-token'); posts.push(input);
            if (reject) { reject = false; return route.fulfill({ status: 422, json: { error: 'Please correct the highlighted fields.', errors: [{ field: 'goals', message: 'Review this answer before submitting.' }] } }); }
            json = { status: 'submitted' };
        }
        await route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/portal/apply/');
    await page.getByLabel('Choose your academic program').selectOption(program);
    await page.getByLabel('When would you like to begin?').selectOption(term);
    await page.getByRole('button', { name: 'Continue to your application' }).click();
    await expect(page.getByRole('group', { name: 'About you', exact: true })).toBeVisible();
    return { posts, errors };
}
async function accessible(page) {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
}

test('portal shares retained columns, radio conditions, bounded answers and accessible validation', async ({ page }) => {
    const { posts, errors } = await fixture(page, true);
    const form = page.getByRole('form', { name: 'Application answers', exact: true });
    await page.getByRole('button', { name: 'Submit application', exact: true }).click();
    await expect(form.locator('.application-errors')).toContainText('Application route');
    await page.getByRole('radio', { name: 'Transfer', exact: true }).check();
    await page.getByRole('textbox', { name: 'Your goals', exact: true }).fill('hi');
    await page.getByRole('spinbutton', { name: 'Previous credits', exact: true }).fill('7');
    await page.getByRole('button', { name: 'Submit application', exact: true }).click();
    await expect(form.locator('.application-errors')).toContainText('Use at least 3 characters');
    await expect(form.locator('.application-errors')).toContainText('Enter 6 or less');
    expect(posts).toHaveLength(0);
    await form.locator('.application-errors').getByRole('link', { name: /Your goals/ }).click();
    await expect(page.getByRole('textbox', { name: 'Your goals', exact: true })).toBeFocused();
    await page.getByRole('textbox', { name: 'Your goals', exact: true }).fill('😀😀😀');
    await page.getByRole('spinbutton', { name: 'Previous credits', exact: true }).fill('6');
    await page.getByRole('button', { name: 'Submit application', exact: true }).click();
    await expect(form.locator('.application-errors')).toContainText('Review this answer before submitting');
    await expect(page.getByRole('textbox', { name: 'Your goals', exact: true })).toHaveValue('😀😀😀');
    await page.getByRole('radio', { name: 'New student', exact: true }).check();
    await expect(page.getByRole('spinbutton', { name: 'Previous credits', exact: true })).toBeHidden();
    await page.getByRole('textbox', { name: 'Your goals', exact: true }).fill('hello');
    await accessible(page);
    await page.screenshot({ path: 'test-results/application-portal-desktop.png', fullPage: true });
    await page.setViewportSize({ width: 320, height: 800 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    await page.screenshot({ path: 'test-results/application-portal-mobile.png', fullPage: true });
    await page.getByRole('button', { name: 'Submit application', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('has been submitted');
    expect(posts.at(-1).answers).toEqual({ route: 'New student', goals: 'hello' });
    expect(posts.at(-1).program_id).toBe(program); expect(posts.at(-1).submission_id).toBe(receipt);
    expect(errors).toEqual([]);
});

test('unanswered prototype-name questions do not satisfy exclusion conditions', async ({ page }) => {
    await fixture(page);
    const result = await page.evaluate(() => {
        const instance = window.createApplicationForm({ fields: [
            { key: 'constructor', label: 'Optional value', type: 'text' },
            { key: 'dependent', label: 'Dependent', type: 'text', show_when: { condition: 'AND', rules: [{ id: 'constructor', operator: 'not_equal', value: 'excluded' }] } },
        ] });
        document.body.append(instance.form);
        return { hidden: instance.form.querySelector('[data-answer-key="dependent"]').hidden, answers: instance.answers() };
    });
    expect(result).toEqual({ hidden: true, answers: {} });
});
