const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K000000000000000000000${String(n).padStart(2, '0')}`;
const program = '01K00000000000000000000001', term = '01K00000000000000000000002', person = '01K00000000000000000000003';
const formId = '01K00000000000000000000008', receipt = '01K00000000000000000000009', application = '01K00000000000000000000010';
const fields = [
    { key: 'goals', label: 'Your plans', type: 'textarea', required: true, settings: { max_length: 100 } },
    { key: 'previous', label: 'Previous college?', type: 'checkbox' },
    { key: 'college', label: 'Previous college name', type: 'text', show_when: { condition: 'AND', rules: [{ id: 'previous', operator: 'equal', value: true }] } },
    { key: 'credits', label: 'Previous credits', type: 'number' },
    { key: 'optional', label: 'Optional information', type: 'text' },
];
const layout = [
    { key: 'plans', label: 'Your next chapter', help: 'A fictional application.', columns: [['goals']] },
    { key: 'history', label: 'Previous learning', columns: [['previous', 'college'], ['credits', 'optional']] },
];
async function fixture(page) {
    const writes = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const req = route.request(), path = new URL(req.url()).pathname;
        let json = {};
        if (path === '/sis/ui/metrics/') json = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) {
            const source = path.split('/')[4];
            json = { items: source === 'people' ? [{ id: person, given_name: 'Fictional', family_name: 'Applicant', institutional_id: 'S-FICTIONAL' }]
                : source === 'programs' ? [{ id: program, code: 'DIP.ART', title: 'Studio Arts' }]
                    : source === 'terms' ? [{ id: term, code: '26/FA' }]
                        : source === 'applications' ? [{ id: application, given_name: 'Fictional', family_name: 'Applicant', status: 'submitted' }] : [], next_after: null };
        } else if (path === `/sis/forms/programs/${program}/active/`) {
            json = { id: formId, name: 'Studio Arts application', application_program_id: program, submission_id: receipt, fields, layout };
        } else if (path === `/sis/forms/${formId}/submissions/`) {
            expect(req.headers()['x-csrf-token']).toBe('fictional-test-token');
            writes.push(JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json')));
            json = { status: 'submitted' };
        } else if (path === `/sis/applications/${application}/answers/`) {
            json = { form: { id: formId, name: 'Original Arts application', fields, layout },
                answers: { goals: '<img src=x onerror=alert(1)>\nOriginal retained answer', previous: false, college: 'Recorded at submission', credits: 0, unlisted: 'Do not show unlisted answers' },
                versions: [], can_revise: false, can_request: false };
        } else if (path === `/sis/applications/${application}/review/`) {
            json = {
                application: { id: { value: application }, status: 'submitted' },
                person: { id: { value: person }, givenName: 'Fictional', familyName: 'Applicant', institutionalId: { value: 'S-FICTIONAL' } },
                labels: { program_code: 'DIP.ART', program_title: 'Studio Arts', term_code: '26/FA' },
                submission: { form: { id: formId, name: 'Original Arts application', fields, layout },
                    answers: { goals: '<img src=x onerror=alert(1)>\nOriginal retained answer', previous: false, college: 'Recorded at submission', credits: 0, unlisted: 'Do not show unlisted answers' } },
            };
        } else throw new Error(`Unexpected request: ${path}`);
        await route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="applications"]').click();
    return { writes, errors };
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
}

test('staff-assisted intake retains context and reviews answers before one protected submission', async ({ page }) => {
    const { writes, errors } = await fixture(page);
    await page.locator('#directory-create').click();
    const body = page.locator('#drawer-body');
    await body.getByRole('combobox', { name: 'Person', exact: true }).selectOption(person);
    await body.getByRole('combobox', { name: 'Academic program', exact: true }).selectOption(program);
    await body.getByRole('combobox', { name: 'Entry term', exact: true }).selectOption(term);
    await body.getByRole('button', { name: 'Open program application' }).click();
    await expect(body).toContainText('Fictional Applicant · S-FICTIONAL');
    await expect(body).toContainText('DIP.ART · Studio Arts');
    await expect(body).toContainText('26/FA');
    await body.getByRole('textbox', { name: 'Your plans', exact: true }).fill('Study fictional art.');
    await body.getByRole('button', { name: 'Review answers', exact: true }).click();
    await expect(body.getByRole('heading', { name: 'Review your answers' })).toBeFocused();
    await expect(body.locator('.application-answer-review')).toContainText('Study fictional art.');
    expect(writes).toHaveLength(0);
    await body.getByRole('button', { name: 'Edit answers' }).click();
    await expect(body.getByRole('textbox', { name: 'Your plans', exact: true })).toHaveValue('Study fictional art.');
    await body.getByRole('textbox', { name: 'Your plans', exact: true }).fill('A revised fictional plan.');
    await body.getByRole('button', { name: 'Review answers', exact: true }).click();
    await accessible(page);
    await body.getByRole('button', { name: 'Submit application', exact: true }).click();
    await expect(body).toContainText('has been submitted');
    expect(writes).toEqual([{ submission_id: receipt, person_id: person, program_id: program, entry_term_id: term,
        answers: { goals: 'A revised fictional plan.', previous: false } }]);
    expect(errors).toEqual([]);
});

test('admissions read retains original sections, values and plain text without reevaluating conditional answers', async ({ page }) => {
    const { writes, errors } = await fixture(page);
    await page.getByRole('button', { name: 'Fictional Applicant', exact: true }).click();
    const body = page.locator('#drawer-body'), review = body.locator('.application-answer-review');
    await expect(body.getByRole('heading', { name: 'Original Arts application' })).toBeVisible();
    await expect(review.getByRole('group', { name: 'Your next chapter' })).toBeVisible();
    await expect(review.locator('.application-columns[data-columns="2"]')).toBeVisible();
    await expect(review.locator('[data-answer-key="previous"] dd')).toHaveText('No');
    await expect(review.locator('[data-answer-key="credits"] dd')).toHaveText('0');
    await expect(review.locator('[data-answer-key="college"] dd')).toHaveText('Recorded at submission');
    await expect(review.locator('[data-answer-key="optional"] dd')).toHaveText('No answer provided');
    await expect(review).toContainText('<img src=x onerror=alert(1)>');
    await expect(review.locator('img, input, textarea')).toHaveCount(0);
    await expect(review).not.toContainText('Do not show unlisted answers');
    await accessible(page);
    await page.screenshot({ path: 'test-results/application-staff-review-desktop.png' });
    await page.setViewportSize({ width: 320, height: 800 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await review.locator('.application-columns[data-columns="2"]').evaluate(element => getComputedStyle(element).gridTemplateColumns.split(' ').length)).toBe(1);
    await accessible(page);
    await review.getByRole('group', { name: 'Previous learning' }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: 'test-results/application-staff-review-mobile.png' });
    expect(writes).toHaveLength(0);
    expect(errors).toEqual([]);
});

test('returning to application setup retains selections and resumes the same unfinished answers', async ({ page }) => {
    const { writes, errors } = await fixture(page); await page.locator('#directory-create').click();
    const body = page.locator('#drawer-body');
    await body.getByRole('combobox', { name: 'Person', exact: true }).selectOption(person);
    await body.getByRole('combobox', { name: 'Academic program', exact: true }).selectOption(program);
    await body.getByRole('combobox', { name: 'Entry term', exact: true }).selectOption(term);
    await body.getByRole('button', { name: 'Open program application' }).click();
    await body.getByRole('textbox', { name: 'Your plans', exact: true }).fill('Unsaved fictional application plans.');
    await body.getByRole('button', { name: 'Back to application setup', exact: true }).click();
    await expect(body.getByRole('combobox', { name: 'Academic program', exact: true })).toHaveValue(program);
    await body.getByRole('button', { name: 'Open program application' }).click();
    await expect(body.getByRole('textbox', { name: 'Your plans', exact: true })).toHaveValue('Unsaved fictional application plans.');
    expect(writes).toHaveLength(0); expect(errors).toEqual([]);
});
test('application setup has grouped applicant and intake fields with phone and enlarged-text reflow', async ({ page }) => {
    const { writes, errors } = await fixture(page); await page.locator('#directory-create').click();
    await expect(page.getByRole('group', { name: 'Applicant', exact: true })).toBeVisible(); await expect(page.getByRole('group', { name: 'Program & intake', exact: true })).toBeVisible();
    await expect(page.locator('#record-drawer')).not.toHaveClass(/showing|hiding/);
    await expect(page.getByRole('combobox', { name: 'Person', exact: true })).toBeVisible();
    await page.screenshot({ path: '/tmp/etsis-admission-start-desktop.png', animations: 'disabled' });
    await page.setViewportSize({ width: 320, height: 800 }); await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.locator('#drawer-body').evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true); await accessible(page);
    await page.getByRole('group', { name: 'Program & intake', exact: true }).scrollIntoViewIfNeeded(); await page.screenshot({ path: '/tmp/etsis-admission-start-phone.png' });
    await page.getByRole('button', { name: 'Back to Admissions', exact: true }).click(); await expect(page.locator('#record-drawer')).toBeHidden();
    expect(writes).toHaveLength(0); expect(errors).toEqual([]);
});

test('changing the intake selection starts fresh answers instead of copying another program draft', async ({ page }) => {
    const { writes, errors } = await fixture(page); const otherProgram = id(91);
    await page.route('**/sis/ui/data/programs/', route => route.fulfill({ json: { items: [
        { id: program, code: 'DIP.ART', title: 'Studio Arts' }, { id: otherProgram, code: 'ARTS-UG', title: 'Fictional Arts' },
    ], next_after: null } }));
    await page.route(`**/sis/forms/programs/${otherProgram}/active/`, route => route.fulfill({ json: {
        id: id(92), name: 'Fictional other program application', application_program_id: otherProgram, submission_id: id(93),
        fields: [{ key: 'goals', label: 'New program plans', type: 'textarea', required: true, settings: {} }], layout: [],
    } }));
    await page.locator('#directory-create').click(); const body = page.locator('#drawer-body');
    await body.getByRole('combobox', { name: 'Person', exact: true }).selectOption(person);
    await body.getByRole('combobox', { name: 'Academic program', exact: true }).selectOption(program);
    await body.getByRole('combobox', { name: 'Entry term', exact: true }).selectOption(term);
    await body.getByRole('button', { name: 'Open program application' }).click();
    await body.getByRole('textbox', { name: 'Your plans', exact: true }).fill('Original private fictional draft.');
    await body.getByRole('button', { name: 'Back to application setup', exact: true }).click();
    await body.getByRole('combobox', { name: 'Academic program', exact: true }).selectOption(otherProgram);
    await body.getByRole('button', { name: 'Open program application' }).click();
    await expect(body.getByRole('textbox', { name: 'New program plans', exact: true })).toHaveValue('');
    await expect(body).not.toContainText('Original private fictional draft.'); expect(writes).toHaveLength(0); expect(errors).toEqual([]);
});
test('a denied application-form response after closing leaves the workspace free of stale errors', async ({ page }) => {
    const { writes, errors } = await fixture(page); await page.locator('#directory-create').click(); const body = page.locator('#drawer-body');
    await body.getByRole('combobox', { name: 'Person', exact: true }).selectOption(person);
    await body.getByRole('combobox', { name: 'Academic program', exact: true }).selectOption(program);
    await body.getByRole('combobox', { name: 'Entry term', exact: true }).selectOption(term);
    let release, start; const pending = new Promise(resolve => { start = resolve; }); const held = new Promise(resolve => { release = resolve; });
    await page.route(`**/sis/forms/programs/${program}/active/`, async route => { start(); await held; await route.fulfill({ status: 403, json: { error: 'Fictional denied form.' } }); });
    await body.getByRole('button', { name: 'Open program application' }).click(); await pending;
    await page.getByRole('button', { name: 'Close record', exact: true }).click(); await expect(page.locator('#record-drawer')).toBeHidden();
    const finished = page.waitForResponse(response => response.url().endsWith(`/programs/${program}/active/`)); release(); await finished;
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    await expect(page.locator('#workspace-alert')).toBeHidden(); await expect(page.locator('#record-drawer')).toBeHidden(); expect(writes).toHaveLength(0); expect(errors).toEqual([]);
});
