const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = number => `01K000000000000000000000${String(number).padStart(2, '0')}`;

async function fixture(page) {
    const calls = [], errors = [], rows = [];
    const classifications = { department: null, academic_level: null, course_level: null };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/courses/') result = { items: [{ id: id(1), code: 'MATH.101', title: 'Fictional Algebra', credits_milli: 3000, active: true }], next_after: null };
        else if (path.startsWith('/sis/catalog/classifications/')) {
            const kind = path.split('/')[4];
            if (path.endsWith('/page/')) {
                result = { items: rows.filter(row => row.kind === kind && `${row.code} ${row.title}`.toLowerCase().includes(data.search.toLowerCase())), next_after: null };
            } else {
                const row = { ...data, kind, id: data.id || id(rows.length + 2), revision: 'a'.repeat(64) };
                const old = rows.findIndex(item => item.id === row.id);
                if (old < 0) rows.push(row); else rows[old] = row;
                result = row;
            }
        } else if (path.endsWith('/classifications/')) {
            if (data) {
                Object.keys(classifications).forEach(kind => {
                    const row = rows.find(row => row.id === data[`${kind}_id`]);
                    classifications[kind] = row ? { id: row.id, code: row.code, title: row.title } : null;
                });
                result = { status: 'saved' };
            } else result = { course: { id: id(1), code: 'MATH.101', title: 'Fictional Algebra' }, classifications, revision: 'b'.repeat(64) };
        } else if (path.endsWith('/publication/page/')) result = { items: [], next_cursor: null };
        else if (path.endsWith('/publication/review/')) result = { expected_version: 0, reason: data.reason, review_token: 'fictional-classification-review',
            definition: { code: 'MATH.101', title: 'Fictional Algebra', credits_milli: 3000, prerequisites: [], transcript_title: null, description: null, subject: null, classifications } };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    return { calls, errors, rows };
}

async function add(page, category, code, title, active = true) {
    await page.getByRole('button', { name: `Add ${category.toLowerCase()}`, exact: true }).click();
    await page.getByRole('textbox', { name: `${category} code`, exact: true }).fill(code);
    await page.getByRole('textbox', { name: `${category} name`, exact: true }).fill(title);
    await page.getByLabel('Available for new assignments', { exact: true }).setChecked(active);
    await page.getByRole('button', { name: 'Save classification', exact: true }).click();
}

test('staff set up departments and levels, choose them together and review their retained publication labels', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Departments & levels', exact: true }).click();
    await add(page, 'Department', 'SCI', 'Science <script>fictional</script>');
    await expect(page.locator('#drawer-body')).toContainText('Science <script>fictional</script>');
    await page.getByRole('button', { name: 'Academic levels', exact: true }).click();
    await add(page, 'Academic level', 'UG', 'Undergraduate');
    await page.getByRole('button', { name: 'Course levels', exact: true }).click();
    await add(page, 'Course level', 'INTRO', 'Introductory');
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.getByRole('button', { name: 'Fictional Algebra', exact: true }).click();
    await page.locator('#drawer-body').getByRole('button', { name: 'Departments & levels', exact: true }).click();
    for (const [category, code] of [['department', 'SCI'], ['academic level', 'UG'], ['course level', 'INTRO']]) {
        await page.getByRole('button', { name: `Choose ${category}`, exact: true }).click();
        await page.getByRole('button', { name: `Choose ${code}`, exact: true }).click();
    }
    await expect(page.locator('#drawer-body')).toContainText('SCI — Science <script>fictional</script>');
    await expect(page.locator('#drawer-body')).toContainText('UG — Undergraduate');
    await expect(page.locator('#drawer-body')).toContainText('INTRO — Introductory');
    await expect(page.getByRole('button', { name: 'Review course publication', exact: true })).toBeDisabled();
    expect(f.calls.some(call => call.path.endsWith('/courses/' + id(1) + '/classifications/'))).toBe(false);
    await page.setViewportSize({ width: 375, height: 812 });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const save = page.getByRole('button', { name: 'Save draft classifications', exact: true });
    await save.focus(); await page.keyboard.press('Enter');
    await expect(page.getByRole('status').filter({ hasText: 'Draft classifications saved.' })).toBeVisible();
    expect(f.calls.find(call => call.path === `/sis/courses/${id(1)}/classifications/`)).toMatchObject({
        csrf: 'fictional-test-token', data: { department_id: id(2), academic_level_id: id(3), course_level_id: id(4), revision: 'b'.repeat(64) },
    });
    await page.getByRole('button', { name: 'Review course publication', exact: true }).click();
    await page.getByLabel('Reason for publication', { exact: true }).fill('Fictional curriculum classification approval');
    await page.getByRole('button', { name: 'Review current definition', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('SCI — Science <script>fictional</script>');
    await expect(page.locator('#drawer-body')).toContainText('UG — Undergraduate');
    await expect(page.locator('#drawer-body')).toContainText('INTRO — Introductory');
    expect(await page.locator('#drawer-body script').count()).toBe(0);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('classification searches can be cleared and retired entries cannot be chosen; returning preserves draft choices', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Departments & levels', exact: true }).click();
    await add(page, 'Department', 'OLD', 'Retired department', false);
    await add(page, 'Department', 'SCI', 'Science');
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.getByRole('button', { name: 'Fictional Algebra', exact: true }).click();
    await page.locator('#drawer-body').getByRole('button', { name: 'Departments & levels', exact: true }).click();
    await page.getByRole('button', { name: 'Choose department', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Choose OLD', exact: true })).toHaveCount(0);
    await page.getByLabel('Find departments', { exact: true }).fill('No matching entry');
    await page.getByRole('button', { name: 'Search classifications', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('No matching classifications');
    await page.getByLabel('Find departments', { exact: true }).fill('');
    await page.getByRole('button', { name: 'Search classifications', exact: true }).click();
    await page.getByRole('button', { name: 'Choose SCI', exact: true }).click();
    await page.getByRole('button', { name: 'Choose course level', exact: true }).click();
    await page.getByRole('button', { name: 'Back to course classifications', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('SCI — Science');
    await page.getByRole('button', { name: 'Clear department', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Department', exact: true })).toContainText('Not specified');
    expect(f.calls.filter(call => call.path === `/sis/courses/${id(1)}/classifications/`)).toHaveLength(0);
    expect(f.calls.some(call => call.path.endsWith('/department/page/') && call.data.search === '')).toBe(true);
    expect(f.errors).toEqual([]);
});
