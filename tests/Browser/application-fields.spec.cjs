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
async function fixture(page, { reject = false, questions = fields, containers = layout } = {}) {
    const posts = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route(/\/(?:portal\/api|sis\/forms)\//, async route => {
        const req = route.request(), path = new URL(req.url()).pathname;
        let json = {};
        if (path === '/portal/api/me/') json = { person: { id: person }, applications: [], students: [], contact: {}, accounts: [], news: [] };
        else if (path === '/portal/api/applications/') json = { person_id: person, items: [] };
        else if (path === `/sis/forms/programs/${program}/active/`) json = { id: formId, name: 'Diploma application', submission_id: receipt, fields: questions, layout: containers };
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
    await expect(page.getByRole('form', { name: 'Application answers', exact: true })).toBeVisible();
    return { posts, errors };
}
async function accessible(page) {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
}

test('portal shares retained columns, radio conditions, bounded answers and accessible validation', async ({ page }) => {
    const { posts, errors } = await fixture(page, { reject: true });
    const form = page.getByRole('form', { name: 'Application answers', exact: true });
    await page.getByRole('button', { name: 'Review answers', exact: true }).click();
    await expect(form.locator('.application-errors')).toContainText('Application route');
    await page.getByRole('radio', { name: 'Transfer', exact: true }).check();
    await page.getByRole('textbox', { name: 'Your goals', exact: true }).fill('hi');
    await page.getByRole('spinbutton', { name: 'Previous credits', exact: true }).fill('7');
    await page.getByRole('button', { name: 'Review answers', exact: true }).click();
    await expect(form.locator('.application-errors')).toContainText('Use at least 3 characters');
    await expect(form.locator('.application-errors')).toContainText('Enter 6 or less');
    expect(posts).toHaveLength(0);
    await form.locator('.application-errors').getByRole('link', { name: /Your goals/ }).click();
    await expect(page.getByRole('textbox', { name: 'Your goals', exact: true })).toBeFocused();
    await page.getByRole('textbox', { name: 'Your goals', exact: true }).fill('😀😀😀');
    await page.getByRole('spinbutton', { name: 'Previous credits', exact: true }).fill('6');
    await page.getByRole('button', { name: 'Review answers', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Review your answers' })).toBeFocused();
    expect(posts).toHaveLength(0);
    await page.getByRole('button', { name: 'Submit application', exact: true }).click();
    await expect(form.locator('.application-errors')).toContainText('Review this answer before submitting');
    await expect(page.getByRole('textbox', { name: 'Your goals', exact: true })).toHaveValue('😀😀😀');
    await page.getByRole('radio', { name: 'New student', exact: true }).check();
    await expect(page.getByRole('spinbutton', { name: 'Previous credits', exact: true })).toBeHidden();
    await page.getByRole('textbox', { name: 'Your goals', exact: true }).fill('hello');
    await accessible(page);
    await page.getByRole('button', { name: 'Review answers', exact: true }).click();
    await expect(page.locator('.application-answer-review')).not.toContainText('Previous credits');
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

test('section shortcuts and review/edit preserve answers without submitting or storing them', async ({ page }) => {
    const { posts, errors } = await fixture(page, {
        questions: [...fields,
            { key: 'updates', label: 'Receive updates?', type: 'checkbox' },
            { key: 'zero', label: 'Other credits', type: 'number' },
            { key: 'optional', label: 'Anything else?', type: 'text' },
        ],
        containers: [
            { key: 'about', label: 'About you', columns: [['route'], ['goals']] },
            { key: 'previous', label: 'Previous learning', columns: [['credits']] },
            { key: 'preferences', label: 'Your preferences', columns: [['updates'], ['zero', 'optional']] },
        ],
    });
    const navigation = page.getByRole('navigation', { name: 'Application sections' });
    await expect(navigation.getByRole('button', { name: 'Previous learning' })).toBeHidden();
    await page.getByRole('radio', { name: 'Transfer', exact: true }).check();
    const jump = navigation.getByRole('button', { name: 'Previous learning' });
    await jump.focus(); await page.keyboard.press('Enter');
    await expect(page.getByRole('group', { name: 'Previous learning' })).toBeFocused();
    await page.getByRole('spinbutton', { name: 'Previous credits', exact: true }).fill('6');
    await page.getByRole('radio', { name: 'New student', exact: true }).check();
    await page.getByRole('textbox', { name: 'Your goals', exact: true }).fill('hello');
    await page.getByRole('spinbutton', { name: 'Other credits', exact: true }).fill('0');
    await page.getByRole('button', { name: 'Review answers', exact: true }).click();
    const review = page.locator('.application-answer-review');
    await expect(review.getByRole('group', { name: 'About you' })).toBeVisible();
    await expect(review.getByRole('group', { name: 'Previous learning' })).toHaveCount(0);
    await expect(review.locator('[data-answer-key="updates"] dd')).toHaveText('No');
    await expect(review.locator('[data-answer-key="zero"] dd')).toHaveText('0');
    await expect(review.locator('[data-answer-key="optional"] dd')).toHaveText('No answer provided');
    expect(posts).toHaveLength(0);
    await accessible(page);
    await page.getByRole('button', { name: 'Edit answers' }).focus(); await page.keyboard.press('Enter');
    await expect(page.getByRole('textbox', { name: 'Your goals', exact: true })).toHaveValue('hello');
    await expect(page.getByRole('spinbutton', { name: 'Other credits', exact: true })).toHaveValue('0');
    await page.getByRole('radio', { name: 'Transfer', exact: true }).check();
    await expect(page.getByRole('spinbutton', { name: 'Previous credits', exact: true })).toHaveValue('6');
    await page.getByRole('radio', { name: 'New student', exact: true }).check();
    await page.getByRole('textbox', { name: 'Your goals', exact: true }).fill('again');
    await page.getByRole('button', { name: 'Review answers', exact: true }).click();
    await expect(review.locator('[data-answer-key="goals"] dd')).toHaveText('again');
    expect(await page.evaluate(() => ({ local: localStorage.length, session: sessionStorage.length }))).toEqual({ local: 0, session: 0 });
    expect(posts).toHaveLength(0);
    await page.getByRole('button', { name: 'Submit application', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('has been submitted');
    expect(posts[0].answers).toEqual({ route: 'New student', goals: 'again', updates: false, zero: 0 });
    expect(errors).toEqual([]);
});

test('pending confirmation blocks duplicates and a failed request retains the same reviewed receipt', async ({ page }) => {
    await fixture(page);
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    const posted = [];
    await page.route(`**/sis/forms/${formId}/submissions/`, async route => {
        posted.push(JSON.parse(new URLSearchParams(route.request().postData()).get('_workspace_json')));
        if (posted.length === 1) {
            await gate;
            return route.fulfill({ status: 503, json: { error: 'Fictional temporary outage. Please retry.' } });
        }
        await route.fulfill({ json: { status: 'submitted' } });
    });
    await page.getByRole('radio', { name: 'New student', exact: true }).check();
    await page.getByRole('textbox', { name: 'Your goals', exact: true }).fill('hello');
    await page.getByRole('button', { name: 'Review answers', exact: true }).click();
    const confirm = page.getByRole('button', { name: 'Submit application', exact: true });
    await confirm.click();
    await expect.poll(() => posted.length).toBe(1);
    await expect(confirm).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Edit answers' })).toBeDisabled();
    await expect(confirm).toHaveAttribute('aria-busy', 'true');
    await expect(page.locator('.application-intake').getByRole('status')).toHaveText('Submitting your application…');
    await confirm.evaluate(button => button.dispatchEvent(new MouseEvent('click', { bubbles: true })));
    expect(posted).toHaveLength(1);
    release();
    await expect(confirm).toBeEnabled();
    await expect(page.locator('.application-answer-review')).toContainText('hello');
    await confirm.click();
    await expect(page.locator('#campus-service-content')).toContainText('has been submitted');
    expect(posted).toHaveLength(2);
    expect(posted[1]).toEqual(posted[0]);
    expect(posted[1].submission_id).toBe(receipt);
});

test('valid question keys cannot replace native form methods or its visibility during review', async ({ page }) => {
    const { posts, errors } = await fixture(page, { containers: [], questions: [
        { key: 'append', label: 'Statement', type: 'text', required: true },
        { key: 'hidden', label: 'Additional detail', type: 'text' },
        { key: 'reset', label: 'Optional note', type: 'text' },
    ] });
    await page.getByRole('textbox', { name: 'Statement', exact: true }).fill('Fictional statement.');
    await page.getByRole('textbox', { name: 'Additional detail', exact: true }).fill('A detail.');
    await page.getByRole('button', { name: 'Review answers', exact: true }).click();
    await expect(page.getByRole('form', { name: 'Application answers' })).toBeHidden();
    await page.getByRole('button', { name: 'Edit answers' }).click();
    await expect(page.getByRole('textbox', { name: 'Statement', exact: true })).toHaveValue('Fictional statement.');
    await page.getByRole('button', { name: 'Review answers', exact: true }).click();
    await page.getByRole('button', { name: 'Submit application', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('has been submitted');
    expect(posts[0].answers).toEqual({ append: 'Fictional statement.', hidden: 'A detail.' });
    expect(errors).toEqual([]);
});
