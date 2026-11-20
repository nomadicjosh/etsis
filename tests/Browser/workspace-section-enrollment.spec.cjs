const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { workspaceTool, recordAction } = require('./support/workspace-actions.cjs');
const id = n => `01K000000000000000000000${String(n).padStart(2, '0')}`;

test('section enrollment review preserves its scope, shows current and selected targets and returns from a section', async ({ page }) => {
    const errors = [], sent = [];
    const row = { id: id(1), code: 'MATH.101.A', course_code: 'MATH.101', course_title: 'Fictional algebra <b>example</b>',
        term_id: id(2), term_code: '26/FA', capacity: 20, active: true, enrolled: 5,
        minimum_enrollment: 12, published_minimum: 10, below_target: true, target_changed: true };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const input = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (input) sent.push({ path, input, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/terms/') result = { items: [{ id: id(2), code: '26/FA' }], next_after: null };
        else if (path === `/sis/ui/catalog/sections/${id(1)}/`) {
            if (input) row.minimum_enrollment = input.minimum_enrollment;
            result = { ...row, course_id: id(3), faculty_user_id: null, fee_minor: 0, currency: 'USD',
                delivery_mode: 'online', web_registration: true, revision: 'a'.repeat(64) };
        }
        else if (path.endsWith('/publication/setup/')) result = { section: row,
            term: { id: id(2), code: '26/FA', starts_on: '2026-08-01', ends_on: '2026-12-20' },
            course_definition: null, latest: null, selected_offering: null, has_student_records: false,
            publication_status: 'unpublished', faculty_label: 'Not assigned' };
        else if (path.endsWith('/publication/page/')) result = { items: [], next_cursor: null };
        else if (path === '/sis/sections/enrollment-review/') result = { items: input.after ? [] : [row], next_after: input.after ? null : id(1) };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await workspaceTool(page, 'Enrollment targets');
    await expect(page.getByRole('region', { name: 'Section enrollment review', exact: true })).toBeVisible();
    await expect(page.locator('#drawer-body')).toContainText('5 of 20 seats');
    await expect(page.locator('#drawer-body')).toContainText('12 · 7 needed');
    await expect(page.locator('#drawer-body')).toContainText('10 · Draft target changed');
    await page.getByLabel('Academic term', { exact: true }).selectOption(id(2));
    await page.getByLabel('Find a section', { exact: true }).fill('MATH.101');
    await page.getByRole('combobox', { name: /^Enrollment review/ }).selectOption('all — All sections');
    await page.getByRole('button', { name: 'Review sections', exact: true }).click();
    await page.getByRole('button', { name: 'MATH.101.A', exact: true }).click();
    await recordAction(page, 'Edit catalog record');
    await page.getByLabel('Minimum enrollment target', { exact: true }).fill('21');
    await page.getByRole('button', { name: 'Save catalog changes', exact: true }).click();
    expect(sent.filter(x => x.path.includes('/ui/catalog/sections/'))).toHaveLength(0);
    await page.getByLabel('Minimum enrollment target', { exact: true }).fill('14');
    await page.getByRole('button', { name: 'Save catalog changes', exact: true }).click();
    await page.getByRole('button', { name: 'Published section details', exact: true }).click();
    await page.getByRole('button', { name: 'Back to section', exact: true }).click();
    await page.getByRole('button', { name: 'Back to enrollment targets', exact: true }).click();
    await expect(page.getByLabel('Find a section', { exact: true })).toHaveValue('MATH.101');
    await expect(page.locator('#drawer-body')).toContainText('14 · 9 needed');
    await expect(page.getByLabel('Academic term', { exact: true })).toHaveValue(id(2));
    await page.setViewportSize({ width: 375, height: 812 });
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'More sections', exact: true }).click();
    await expect(page.getByText(/No sections match this review/)).toBeVisible();
    await page.getByRole('button', { name: 'Previous sections', exact: true }).click();
    await expect(page.getByRole('button', { name: 'MATH.101.A', exact: true })).toBeVisible();
    expect(sent.filter(x => x.path.endsWith('/enrollment-review/') && x.input.search === 'MATH.101')
        .every(x => x.input.term_id === id(2) && x.input.state === 'all')).toBe(true);
    expect(sent.every(x => x.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});
