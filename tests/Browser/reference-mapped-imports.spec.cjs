const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const identity = '01K00000000000000000000001';

for (const [target, title, idField] of [['subject', 'Subject', 'subject_id'], ['department', 'Department', 'classification_id']]) {
    test(`${target} import supports mapping, explicit links and catalog handoff without course controls`, async ({ page }) => {
        const calls = [], errors = [];
        const csv = 'External ID,Code,Title\nref-1,MATH,Mathematics\n';
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/sis/**', async route => {
            const request = route.request(), path = new URL(request.url()).pathname;
            const body = request.postData() ? JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json')) : {};
            calls.push({ path, body, csrf: request.headers()['x-csrf-token'] });
            let result = { items: [], next_after: null };
            if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
            if (path === '/sis/imports/preview/') {
                const mapped = Object.keys(body.mapping).length > 0;
                const approved = body.choices.some(choice => choice.action === 'link');
                result = {
                    headers: ['External ID', 'Code', 'Title'], samples: [['ref-1', 'MATH', 'Mathematics']], row_count: 1,
                    errors: [], ready: mapped && approved, review_token: mapped && approved ? 'fictional-proof' : null,
                    summary: { create: 0, link: approved ? 1 : 0, match: mapped && !approved ? 1 : 0, conflict: 0, omitted: 0, skip: 0 },
                    plan: mapped ? [{ row: 2, external_id: 'ref-1', code: 'MATH', title: 'Mathematics', [idField]: identity,
                        [`matched_${target}`]: { id: identity, code: 'MATH', title: 'Mathematics', active: true },
                        action: approved ? 'link' : 'match', message: 'Approve linking the matching reference.', source_references: {}, references: {}, reference_errors: [] }] : [],
                };
            }
            if (path === '/sis/imports/commit/') result = { imported: 0, linked: 1, omitted: 0, skipped: 0, total: 1 };
            await route.fulfill({ json: result });
        });
        await page.goto('/admin/');
        await page.locator('.workspace-nav-link[data-workspace="imports"]').click();
        await page.locator('#import-target').selectOption(target);
        await expect(page.locator('#import-sample')).toHaveAttribute('href', '/assets/examples/catalog-references.csv');
        await page.locator('#import-file').setInputFiles({ name: 'fictional-references.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
        await page.locator('#import-inspect').click();
        await expect(page.locator('#map-code')).toHaveValue('1');
        await expect(page.locator('#map-title')).toHaveValue('2');
        await expect(page.locator('#map-credits')).toHaveCount(0);
        await expect(page.locator('#import-optional-fields')).toHaveCount(0);
        await expect(page.getByRole('combobox', { name: new RegExp(`^${title} name`) })).toHaveValue('2');
        await page.locator('#import-review').click();
        await expect(page.getByRole('table', { name: `Reviewed ${target} import rows` })).toContainText('Mathematics');
        await expect(page.locator('#import-catalog-setup')).toBeHidden();
        await expect(page.locator('#import-commit')).toBeDisabled();
        await page.getByRole('button', { name: `Link ${target} for row 2`, exact: true }).click();
        await page.locator('#import-refresh').click();
        await expect(page.locator('#import-commit')).toBeEnabled();
        await page.setViewportSize({ width: 320, height: 812 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => item.id)).toEqual([]);
        await page.locator('#import-commit').click();
        await expect(page.locator('#import-summary')).toContainText('Rows queued for server processing: 1');
        await expect(page.locator('#import-draft-link')).toBeHidden();
        await page.locator('#import-reference-open').click();
        await expect(page.locator('#drawer-title')).toContainText(target === 'subject' ? 'Course subjects' : 'Departments');
        const sent = calls.find(call => call.path === '/sis/imports/commit/');
        expect(sent.csrf).toBe('fictional-test-token');
        expect(errors).toEqual([]);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    });
}

test('each reference destination uses its own name and changing destination clears pending source choices', async ({ page }) => {
    await page.route('**/sis/**', route => route.fulfill({ json: { items: [], next_after: null, cards: [], intake: [], pipeline: [], queue: [] } }));
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="imports"]').click();
    await expect(page.locator('#import-reference-open')).toHaveJSProperty('hidden', true);
    for (const target of ['subject', 'department', 'academic_level', 'course_level', 'award', 'instructional_method', 'campus', 'school', 'field_of_study']) {
        await page.locator('#import-file').setInputFiles({ name: 'fictional.csv', mimeType: 'text/csv', buffer: Buffer.from('ID,Code,Title\na,REF,Name\n') });
        await page.locator('#import-target').selectOption(target);
        await expect(page.locator('#import-source')).toHaveValue(`${target.replaceAll('_', '-')}-catalog`);
        expect(await page.locator('#import-file').evaluate(input => input.files.length)).toBe(0);
        await expect(page.locator('[data-import-panel="1"]')).toBeVisible();
        await expect(page.locator('#import-commit')).toBeDisabled();
        await expect(page.locator('#import-description')).not.toContainText('_');
    }
    await page.locator('.workspace-nav-link[data-workspace="overview"]').click();
    await page.locator('.workspace-nav-link[data-workspace="imports"]').click();
    await expect(page.locator('#import-catalog-setup')).toHaveJSProperty('hidden', true);
    await expect(page.locator('#import-draft-link')).toHaveJSProperty('hidden', true);
    await expect(page.locator('#import-reference-open')).toHaveJSProperty('hidden', false);
});
