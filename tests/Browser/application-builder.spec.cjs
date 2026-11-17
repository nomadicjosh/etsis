const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const program = '01K00000000000000000000001', level = '01K00000000000000000000002', draftId = '01K00000000000000000000003';

async function fixture(page, { levels = [{ id: level, code: 'DIP', title: 'Professional diploma' }], editor = false } = {}) {
    const writes = [], errors = [];
    let saved = null;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const req = route.request(), path = new URL(req.url()).pathname;
        const input = req.method() === 'POST' ? JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json')) : null;
        if (input) writes.push({ path, input });
        let json = {};
        if (path === '/sis/ui/metrics/') json = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) json = { items: [{ id: program, code: 'DIP.ART', title: 'Studio Arts' }], next_after: null };
        else if (path === '/sis/forms/academic-levels/') json = { items: levels };
        else if (path === '/sis/forms/' && input) { saved = { ...input, id: draftId, version: saved ? saved.version + 1 : 0, status: 'draft' }; json = saved; }
        else if (path === '/sis/forms/') json = { items: saved ? [{ ...saved, program: 'Professional diploma', enabled_id: saved.status === 'published' ? draftId : null }] : [] };
        else if (path === `/sis/forms/${draftId}/publish/`) { saved.status = 'published'; saved.version++; json = { status: 'published' }; }
        else if (path === `/sis/forms/${draftId}/`) json = saved;
        await route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(editor ? '/admin/form-editor-fixture/' : '/admin/');
    await page.locator('.workspace-nav-link[data-workspace="forms"]').click();
    await expect(page.locator('#form-level-help')).toContainText(levels.length ? 'configured by your institution' : 'No academic levels');
    return { writes, errors };
}
async function add(page, type, label) {
    await page.locator('#add-field-menu').click();
    await page.locator(`[data-add-field="${type}"]`).first().click();
    await page.getByLabel('Question or field label').last().fill(label);
}
async function accessible(page) {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
}

test('institution-defined levels, field cards, conditions and reviewed publication', async ({ page }) => {
    const { writes, errors } = await fixture(page);
    await expect(page.locator('#form-level option')).toHaveText(['Choose an academic level', 'Professional diploma']);
    await page.getByLabel('Form name', { exact: true }).fill('Diploma admission');
    await page.getByLabel('Academic level', { exact: true }).selectOption(level);
    await add(page, 'checkbox', 'Previously attended college?');
    await add(page, 'number', 'Credits completed');
    await add(page, 'text', 'Previous college');
    await page.getByLabel('An answer is required').last().check();
    await expect(page.locator('.designer-field-header h3').last()).toHaveText('Previous college');
    await page.getByRole('button', { name: 'Collapse all', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Expand field 3', exact: true })).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByLabel('Question or field label').last()).toBeHidden();
    await page.getByRole('button', { name: 'Expand field 3', exact: true }).click();
    await page.getByRole('button', { name: 'Edit conditions for field 3', exact: true }).click();
    const rules = page.locator('.form-condition-builder');
    await rules.getByLabel('Earlier question').selectOption({ label: 'Previously attended college?' });
    await rules.getByLabel('Condition value').selectOption('1');
    await rules.locator('[data-add="rule"]').first().click();
    await rules.getByLabel('Earlier question').last().selectOption({ label: 'Credits completed' });
    await rules.getByLabel('Comparison').last().selectOption('greater');
    await rules.getByLabel('Condition value').last().fill('5');
    await accessible(page);
    await page.getByRole('button', { name: 'Apply conditions', exact: true }).click();
    await expect(page.locator('#record-drawer')).not.toBeVisible();
    await page.getByRole('button', { name: 'Preview form', exact: true }).click();
    await expect(page.locator('#drawer-body').getByRole('textbox', { name: 'Previous college', exact: true })).toBeHidden();
    await page.locator('#drawer-body').getByRole('checkbox', { name: 'Previously attended college?', exact: true }).check();
    await page.locator('#drawer-body').getByRole('spinbutton', { name: 'Credits completed', exact: true }).fill('6');
    await expect(page.locator('#drawer-body').getByRole('textbox', { name: 'Previous college', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.locator('#designer-state')).toHaveText('Draft saved');
    const data = writes.find(write => write.path === '/sis/forms/').input;
    expect(data.program_id).toBeNull(); expect(data.academic_level_id).toBe(level);
    expect(data.fields[2].show_when.rules).toHaveLength(2);
    expect(data.fields[2].show_when.rules[0].value).toBe(true);
    expect(data.fields[2].show_when.rules[1].value).toBe(5);
    await page.getByRole('button', { name: 'Review publication', exact: true }).click();
    expect(writes.filter(write => write.path.endsWith('/publish/'))).toHaveLength(0);
    await expect(page.locator('#drawer-body')).toContainText('Program-specific forms take precedence');
    await page.getByRole('button', { name: 'Confirm publication', exact: true }).click();
    await expect(page.locator('#designer-state')).toHaveText('Published');
    expect(writes.filter(write => write.path.endsWith('/publish/'))[0].input).toEqual({ version: 0 });
    await page.locator('.form-library-item').click();
    await expect(page.locator('#designer-state')).toHaveText('New revision');
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Expand all', exact: true }).click();
    await accessible(page);
    await page.screenshot({ path: 'test-results/application-builder-desktop.png', fullPage: true });
    expect(errors).toEqual([]);
});

test('empty academic-level setup still allows program forms; keyboard and pointer sorting', async ({ page }) => {
    const { writes, errors } = await fixture(page, { levels: [], editor: true });
    await page.getByLabel('Use this form for').selectOption('program');
    await page.locator('#form-program').selectOption(program);
    await page.getByLabel('Form name', { exact: true }).fill('Program admission');
    await add(page, 'text', 'First question');
    await add(page, 'text', 'Second question');
    await page.getByRole('button', { name: 'Move field up: Second question', exact: true }).click();
    await expect(page.locator('.designer-field-header h3').first()).toHaveText('Second question');
    await page.getByRole('button', { name: 'Duplicate field 1', exact: true }).click();
    await expect(page.locator('.designer-field-header h3').nth(1)).toHaveText('Second question copy');
    await page.getByRole('button', { name: 'Collapse all', exact: true }).click();
    await page.locator('#designer-fields').scrollIntoViewIfNeeded();
    const handles = page.locator('.form-field-drag');
    const source = await handles.nth(2).boundingBox(), target = await handles.nth(0).boundingBox();
    await page.mouse.move(source.x + 12, source.y + 12); await page.mouse.down();
    await page.mouse.move(target.x + 12, target.y + 5, { steps: 15 }); await page.mouse.up();
    await expect(page.locator('.designer-field-header h3').first()).toHaveText('First question');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.locator('#designer-state')).toHaveText('Draft saved');
    const saved = writes.find(write => write.path === '/sis/forms/').input;
    expect(saved.program_id).toBe(program); expect(saved.academic_level_id).toBeNull();
    expect(new Set(saved.fields.map(field => field.key)).size).toBe(3);
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeHidden();
    await page.setViewportSize({ width: 320, height: 800 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    await page.screenshot({ path: 'test-results/application-builder-mobile.png', fullPage: true });
    expect(errors).toEqual([]);
});

test('editing while a draft saves retains newer local answers and blocks publication', async ({ page }) => {
    await fixture(page);
    await page.getByLabel('Form name', { exact: true }).fill('Diploma admission');
    await page.locator('#form-level').selectOption(level);
    await add(page, 'text', 'Original question');
    let release, captured;
    const pending = new Promise(resolve => { release = resolve; });
    await page.route('**/sis/forms/', async route => {
        if (route.request().method() !== 'POST') return route.fallback();
        captured = JSON.parse(new URLSearchParams(route.request().postData()).get('_workspace_json'));
        await pending;
        await route.fulfill({ json: { ...captured, id: draftId, version: 0, status: 'draft' } });
    });
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect.poll(() => Boolean(captured)).toBe(true);
    await page.getByLabel('Question or field label').fill('More recent question');
    release();
    await expect(page.locator('#designer-save-status')).toContainText('latest changes');
    await expect(page.getByLabel('Question or field label')).toHaveValue('More recent question');
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeDisabled();
    expect(captured.fields[0].label).toBe('Original question');
});

test('moving a conditional question ahead of its source blocks saving without destroying the rule', async ({ page }) => {
    await fixture(page);
    await page.getByLabel('Form name', { exact: true }).fill('Diploma admission');
    await page.locator('#form-level').selectOption(level);
    await add(page, 'checkbox', 'Prior study'); await add(page, 'text', 'Prior college');
    await page.getByRole('button', { name: 'Edit conditions for field 2', exact: true }).click();
    await page.locator('.form-condition-builder').getByLabel('Earlier question').selectOption({ label: 'Prior study' });
    await page.locator('.form-condition-builder').getByLabel('Condition value').selectOption('1');
    await page.getByRole('button', { name: 'Apply conditions', exact: true }).click();
    await page.getByRole('button', { name: 'Move field up: Prior college', exact: true }).click();
    await expect(page.locator('#designer-fields')).toContainText('missing or later question');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.locator('#workspace-alert')).toContainText('Review visibility conditions for field 1');
    await page.getByRole('button', { name: 'Move field down: Prior college', exact: true }).click();
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.locator('#designer-state')).toHaveText('Draft saved');
});

test('containers retain columns and reading order with keyboard moves, pointer moves and fewer columns', async ({ page }) => {
    const { writes, errors } = await fixture(page);
    await page.locator('#form-name').fill('Diploma admission'); await page.locator('#form-level').selectOption(level);
    await add(page, 'text', 'Preferred name'); await add(page, 'email', 'Contact email');
    await page.getByRole('button', { name: 'Add container', exact: true }).click();
    await page.getByLabel('Container title', { exact: true }).fill('About you');
    await page.getByLabel('Columns', { exact: true }).selectOption('2');
    await page.getByRole('button', { name: 'Move field 2 to another column', exact: true }).click();
    await page.getByLabel('Destination', { exact: true }).selectOption({ label: 'About you · Column 2' });
    await page.getByRole('button', { name: 'Move question', exact: true }).click();
    await expect(page.locator('.designer-column').nth(1)).toContainText('Contact email');
    await page.screenshot({ path: 'test-results/application-layout-designer-desktop.png', fullPage: true });
    await page.getByRole('button', { name: 'Collapse all', exact: true }).click();
    await page.getByText('Container settings', { exact: true }).click();
    await page.locator('.designer-columns').scrollIntoViewIfNeeded();
    const first = await page.locator('.designer-column').first().locator('.form-field-drag').boundingBox();
    const second = await page.locator('.designer-column').nth(1).locator('.designer-field').boundingBox();
    await page.mouse.move(first.x + 12, first.y + 12); await page.mouse.down();
    await page.mouse.move(second.x + second.width / 2, second.y + second.height - 12, { steps: 18 }); await page.mouse.up();
    await expect(page.locator('.designer-column').first().locator('.designer-field')).toHaveCount(0);
    await expect(page.locator('.designer-column').nth(1).locator('.designer-field')).toHaveCount(2);
    await page.getByRole('button', { name: 'Add container', exact: true }).click();
    await page.getByLabel('Container title', { exact: true }).last().fill('Study plans');
    const columnMenu = page.getByRole('button', { name: 'Add a field to Study plans, column 1', exact: true });
    const oldToggle = await columnMenu.elementHandle();
    await columnMenu.click();
    await page.getByRole('group', { name: 'Study plans, column 1', exact: true })
        .getByRole('button', { name: 'Single choice', exact: true }).click();
    expect(await oldToggle.evaluate(toggle => bootstrap.Dropdown.getInstance(toggle) === null)).toBe(true);
    await page.getByLabel('Question or field label').last().fill('Application route');
    await page.getByRole('button', { name: 'Move container up: Study plans', exact: true }).click();
    await expect(page.locator('.designer-container').first()).toContainText('Application route');
    await page.getByLabel('Columns', { exact: true }).last().selectOption('1');
    await expect(page.locator('.designer-container').last().locator('.designer-column')).toHaveCount(1);
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.locator('#designer-state')).toHaveText('Draft saved');
    const saved = writes.filter(write => write.path === '/sis/forms/').at(-1).input;
    expect(saved.layout.map(container => container.label)).toEqual(['Study plans', 'About you']);
    expect(saved.layout.flatMap(container => container.columns.flat())).toEqual(saved.fields.map(field => field.key));
    expect(new Set(saved.fields.map(field => field.key)).size).toBe(3);
    await page.getByRole('button', { name: 'Preview form', exact: true }).click();
    await expect(page.locator('#drawer-body .application-container')).toHaveCount(2);
    await expect(page.getByRole('radio', { name: 'Option one', exact: true })).toBeVisible();
    await accessible(page);
    await page.screenshot({ path: 'test-results/application-layout-preview.png', fullPage: true });
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.getByRole('button', { name: 'Collapse all', exact: true }).click();
    await page.setViewportSize({ width: 320, height: 800 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    for (const title of await page.locator('.form-container-heading h3').all())
        expect((await title.boundingBox()).height).toBeLessThan(80);
    await page.screenshot({ path: 'test-results/application-layout-mobile.png', fullPage: true });
    expect(errors).toEqual([]);
});

test('question settings validate preview answers and a saved card keeps later edits', async ({ page }) => {
    const { writes } = await fixture(page);
    await page.locator('#form-name').fill('Diploma admission'); await page.locator('#form-level').selectOption(level);
    await add(page, 'textarea', 'Personal statement');
    await page.getByLabel('An answer is required').check();
    await page.getByText('Field settings', { exact: true }).click();
    await page.getByLabel('Placeholder', { exact: true }).fill('Tell us about your goals');
    await page.getByLabel('Minimum characters', { exact: true }).fill('3');
    await page.getByLabel('Maximum characters', { exact: true }).fill('5');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.locator('#designer-state')).toHaveText('Draft saved');
    await page.getByLabel('Question or field label').fill('Your statement');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.locator('#designer-state')).toHaveText('Draft saved');
    expect(writes.filter(write => write.path === '/sis/forms/').at(-1).input.fields[0].label).toBe('Your statement');
    await page.getByRole('button', { name: 'Preview form', exact: true }).click();
    const answer = page.locator('#drawer-body').getByRole('textbox', { name: 'Your statement', exact: true });
    await expect(answer).toHaveAttribute('placeholder', 'Tell us about your goals');
    await answer.fill('hi'); await page.getByRole('button', { name: 'Test answers', exact: true }).click();
    await expect(page.locator('.application-errors')).toContainText('Use at least 3 characters');
    await page.locator('.application-errors').getByRole('link').click(); await expect(answer).toBeFocused();
    await answer.fill('😀😀😀'); await page.getByRole('button', { name: 'Test answers', exact: true }).click();
    await expect(page.locator('.application-errors')).toBeHidden();
    await accessible(page);
});
