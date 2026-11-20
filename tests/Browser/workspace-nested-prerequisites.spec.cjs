"use strict";
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

test('staff arrange nested prerequisite routes, retain choices and review the same publication', async ({ page }) => {
    const target = { id: id(1), code: 'ADV.201', title: 'Fictional advanced course', credits_milli: 3000, active: true };
    const courses = ['A', 'B', 'C'].map((code, i) => ({ id: id(i + 2), code, title: `Fictional ${code}`, active: true }));
    const groups = courses.map(course => [course.id]);
    let rule = null, revision = 'a'.repeat(64);
    const calls = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const definition = () => ({ ...target, prerequisite_groups: groups, prerequisites: groups.flat(),
        prerequisite_group_minimums: [1, 1, 1], prerequisite_grade_points_milli: [null, 2675, null],
        ...(rule ? { prerequisite_rule: rule } : {}), corequisites: [], transcript_title: null, description: null,
        subject: null, classifications: { department: null, academic_level: null, course_level: null } });
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/courses/') result = { items: [target], next_after: null };
        else if (path.endsWith('/prerequisite-groups/')) {
            if (data) { rule = data.rule; revision = 'b'.repeat(64); }
            result = { course: target, groups, minimums: [1, 1, 1], grade_points_milli: [null, 2675, null],
                corequisites: [], minimum_requirements: null, rule, courses, revision };
        } else if (path === '/sis/prerequisite-labels/') result = { items: courses };
        else if (path.endsWith('/publication/page/')) result = { items: [], next_cursor: null };
        else if (path.endsWith('/publication/review/')) result = { expected_version: 0, definition: definition(),
            reason: data.reason, review_token: 'fictional-nested-proof' };
        else if (path.endsWith('/publication/commit/')) result = { operation_id: id(9), last_version: 1 };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole('button', { name: target.title, exact: true }).click();
    await page.getByRole('button', { name: 'Edit prerequisites', exact: true }).click();
    await page.getByRole('button', { name: 'Arrange rule', exact: true }).click();
    await page.getByLabel('Requirement 2', { exact: true }).check();
    await page.getByLabel('Requirement 3', { exact: true }).check();
    await page.getByRole('button', { name: 'Group selected', exact: true }).click();
    await page.getByLabel('Branch 2', { exact: true }).selectOption('any');
    await page.getByRole('button', { name: 'Move Requirement 3 up', exact: true }).click();
    await page.getByLabel('Overall rule', { exact: true }).selectOption('count');
    const needed = page.getByLabel('Branches needed', { exact: true }).first();
    await needed.fill('3');
    await page.getByRole('button', { name: 'Back to requirements', exact: true }).click();
    await expect(page.getByLabel('Overall rule', { exact: true })).toBeVisible();
    await page.getByLabel('Overall rule', { exact: true }).selectOption('all');
    await page.getByRole('button', { name: 'Back to requirements', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Requirement 1 · 1 of: A');
    await page.getByRole('button', { name: 'Arrange rule', exact: true }).click();
    await page.getByLabel('Overall rule', { exact: true }).selectOption('count');
    await needed.fill('2');
    await page.setViewportSize({ width: 375, height: 812 });
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Back to requirements', exact: true }).click();
    await expect(page.getByLabel('Allow alternative requirements', { exact: true })).toBeDisabled();
    await expect(page.locator('#drawer-body')).toContainText('Complete any one');
    await page.getByRole('button', { name: 'Save requirements', exact: true }).click();
    const expected = { minimum: 2, children: [0, { minimum: 1, children: [2, 1] }] };
    expect(rule).toEqual(expected);
    await page.getByRole('button', { name: 'Reload saved choices', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Complete any one');
    await expect(page.getByRole('region', { name: 'Requirement 2', exact: true }).getByLabel('Minimum grade points', { exact: true })).toHaveValue('2.675');
    await page.getByRole('button', { name: 'Review course publication', exact: true }).click();
    await page.getByLabel('Reason for publication', { exact: true }).fill('Fictional nested prerequisite approval.');
    await page.getByRole('button', { name: 'Review current definition', exact: true }).click();
    await page.getByText('Nested prerequisite rule', { exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Complete all');
    await expect(page.locator('#drawer-body')).toContainText('Complete any one');
    await expect(page.locator('#drawer-body')).toContainText('Minimum 2.675 grade points');
    await page.getByRole('button', { name: 'Publish reviewed definition', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('published');
    const saves = calls.filter(call => call.path.endsWith('/prerequisite-groups/') && call.data);
    expect(saves).toHaveLength(1);
    expect(saves[0].data.rule).toEqual(expected);
    expect(saves[0].data.minimum_requirements).toBeNull();
    expect(calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});
