const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

test('optional course classifications can be mapped, fixed in the catalog drawer and reviewed without losing the import', async ({ page }) => {
    const calls = [], errors = [];
    const subjectName = 'Mathematics <img src=x onerror=alert(1)>';
    let subjectAvailable = false;
    const source = 'External ID,Code,Title,Credits,Subject code,Department code,Academic level code,Course level code\n'
        + 'one,MATH.101,Algebra,3,math,sci,ug,100\n';
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const body = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : {};
        calls.push({ path, body, method: route.request().method(), csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        if (path === '/sis/catalog/subjects/') result = { items: subjectAvailable
            ? [{ id: id(1), code: 'MATH', title: subjectName, active: true, revision: 'fictional-revision' }] : [], next_after: null };
        if (path === '/sis/catalog/subjects/save/') { subjectAvailable = true; result = { id: id(1), code: 'MATH', title: subjectName, active: true }; }
        if (path === '/sis/imports/preview/') {
            const mapped = Object.keys(body.mapping).length > 0;
            const references = {
                department: { id: id(2), code: 'SCI', title: 'Sciences' },
                academic_level: { id: id(3), code: 'UG', title: 'Undergraduate' },
                course_level: { id: id(4), code: '100', title: 'Introductory' },
            };
            if (subjectAvailable) references.subject = { id: id(1), code: 'MATH', title: subjectName };
            const ready = mapped && subjectAvailable;
            result = { headers: ['External ID', 'Code', 'Title', 'Credits', 'Subject code', 'Department code', 'Academic level code', 'Course level code'],
                samples: [['one', 'MATH.101', 'Algebra', '3', 'math', 'sci', 'ug', '100']], row_count: 1, ready, errors: [],
                fingerprint: 'fictional-classified-file', review_token: ready ? 'fictional-reviewed-classifications' : null,
                summary: { create: ready ? 1 : 0, link: 0, skip: 0, omitted: 0, match: 0, conflict: mapped && !ready ? 1 : 0 },
                plan: mapped ? [{ row: 2, external_id: 'one', code: 'MATH.101', title: 'Algebra', credits_milli: 3000,
                    action: ready ? 'create' : 'conflict', course_id: null, matched_course: null,
                    source_references: { subject: 'MATH', department: 'SCI', academic_level: 'UG', course_level: '100' }, references,
                    reference_errors: ready ? [] : ['Subject MATH was not found.'],
                    message: ready ? 'Create an inactive course draft.' : 'Subject MATH was not found. Resolve it or leave this row out.' }] : [] };
        }
        if (path === '/sis/imports/commit/') result = { operation_id: id(10), imported: 1, linked: 0, skipped: 0, omitted: 0, total: 1 };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    const accessible = async () => {
        const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(result.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
    };
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="imports"]').click();
    await page.locator('#import-file').setInputFiles({ name: 'fictional-classifications.csv', mimeType: 'text/csv', buffer: Buffer.from(source) });
    await page.locator('#import-inspect').click();
    await expect(page.locator('#import-optional-fields')).toHaveAttribute('open', '');
    await expect(page.getByRole('combobox', { name: /^Subject code/ })).toHaveValue('4');
    await expect(page.getByRole('combobox', { name: /^Department code/ })).toHaveValue('5');
    await expect(page.getByRole('combobox', { name: /^Academic level code/ })).toHaveValue('6');
    await expect(page.getByRole('combobox', { name: /^Course level code/ })).toHaveValue('7');
    await accessible();
    await page.locator('#import-review').click();
    await expect(page.getByRole('table', { name: 'Reviewed course import rows' })).toContainText('Subject MATH was not found.');
    await expect(page.locator('#import-commit')).toBeDisabled();
    await page.getByRole('button', { name: 'Set up subjects', exact: true }).click();
    await page.getByRole('button', { name: 'Add subject', exact: true }).click();
    await page.locator('#drawer-body').getByRole('textbox', { name: /^Subject code/ }).fill('MATH');
    await page.locator('#drawer-body').getByRole('textbox', { name: /^Subject name/ }).fill(subjectName);
    await page.getByRole('button', { name: 'Save subject', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText(subjectName);
    await expect(page.locator('#drawer-body img')).toHaveCount(0);
    await accessible();
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.getByRole('button', { name: 'Review choices', exact: true }).click();
    await expect(page.locator('#import-commit')).toBeEnabled();
    await page.getByText('Catalog classifications', { exact: true }).click();
    await expect(page.getByRole('table', { name: 'Reviewed course import rows' })).toContainText(subjectName);
    await expect(page.getByRole('table', { name: 'Reviewed course import rows' })).toContainText('Academic level: UG · Undergraduate');
    await expect(page.locator('[data-import-panel="3"] img')).toHaveCount(0);
    await page.setViewportSize({ width: 320, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible();
    await page.locator('#import-commit').click();
    await expect(page.locator('#import-summary')).toContainText('Inactive drafts created: 1');
    const commit = calls.find(call => call.path === '/sis/imports/commit/').body;
    expect(commit.csv).toBe(source);
    expect(commit.mapping).toEqual({ external_id: 0, code: 1, title: 2, credits: 3,
        subject_code: 4, department_code: 5, academic_level_code: 6, course_level_code: 7 });
    expect(commit.choices).toEqual([]);
    expect(errors).toEqual([]);
    expect(calls.filter(call => call.method === 'POST').every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
