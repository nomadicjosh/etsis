"use strict";
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

for (const [counted, pathways] of [[false, false], [true, false], [true, true]]) test(`staff build ${pathways ? "alternative pathways with grade minima" : counted ? 'counted and shared' : 'alternative'} prerequisites, preserve stale edits and review publication`, async ({ page }) => {
    const target = { id: id(1), code: 'MATH.201', title: 'Advanced mathematics', credits_milli: 3000, active: true };
    const choices = [
        { id: id(2), code: 'MATH.101', title: 'Algebra <b>fictional</b>', active: true },
        { id: id(3), code: 'MATH.110', title: 'Foundations', active: true },
        { id: id(4), code: 'ENG.101', title: 'Academic writing', active: true },
        { id: id(5), code: 'CHEM.101L', title: 'Fictional laboratory', active: true },
    ];
    let minimumRequirements = null, creditOrigin = null;
    let gradePoints = [], minimums = [], groups = [], corequisites = [], revision = 'a'.repeat(64), stale = true;
    const calls = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const definition = () => ({ ...(creditOrigin ? { prerequisite_credit_origin: creditOrigin } : {}), code: target.code, title: target.title, credits_milli: 3000,
        prerequisites: [...new Set(groups.flat())].sort(), prerequisite_group_minimums: minimums, prerequisite_grade_points_milli: gradePoints, prerequisite_groups: groups.map(group => [...group]), corequisites: [...corequisites],
        ...(minimumRequirements == null ? {} : { prerequisite_requirement_minimum: minimumRequirements }),
        transcript_title: null, description: null, subject: null,
        classifications: { department: null, academic_level: null, course_level: null } });
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/courses/') result = { items: data?.search
            ? choices.filter(row => `${row.code} ${row.title}`.toLowerCase().includes(data.search.toLowerCase()))
            : [target, ...choices], next_after: null };
        else if (path.endsWith('/prerequisite-groups/')) {
            if (data && stale) { stale = false; return route.fulfill({ status: 409, json: { error: 'Reload the prerequisite editor before saving your changes.' } }); }
            if (data) {
                creditOrigin = data.credit_origin ?? null; minimumRequirements = data.minimum_requirements ?? null; corequisites = [...data.corequisites]; groups = data.groups.map(group => [...group].sort()); minimums = [...data.minimums]; gradePoints = [...data.grade_points_milli]; revision = 'b'.repeat(64);
                await page.getByRole('button', { name: 'Add requirement', exact: true }).click();
            }
            result = { course: target, credit_origin: creditOrigin, minimum_requirements: minimumRequirements, groups, minimums, grade_points_milli: gradePoints, corequisites, courses: choices.filter(row => [...groups.flat(), ...corequisites].includes(row.id)), revision };
        } else if (path === '/sis/prerequisite-labels/') result = { items: choices.filter(row => data.ids.includes(row.id)) };
        else if (path.endsWith('/publication/page/')) result = { items: [], next_cursor: null };
        else if (path.endsWith('/publication/review/')) result = { expected_version: 0, definition: definition(),
            reason: data.reason, review_token: 'fictional-choice-proof' };
        else if (path.endsWith('/publication/commit/')) result = { operation_id: id(9), last_version: 1 };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole('button', { name: target.title, exact: true }).click();
    await page.getByRole('button', { name: 'Edit prerequisites', exact: true }).click();
    await page.getByRole('button', { name: 'Add requirement', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Save requirements', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Add course to requirement 1', exact: true }).click();
    await page.getByRole('button', { name: 'Choose MATH.101', exact: true }).click();
    await page.getByRole('button', { name: 'Add course to requirement 1', exact: true }).click();
    await page.getByLabel('Find a course', { exact: true }).fill('MATH.110');
    await page.getByRole('button', { name: 'Search courses', exact: true }).click();
    await page.getByRole('button', { name: 'Choose MATH.110', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Requirement 1', exact: true })).toContainText('Complete any one');
    if (counted) {
        await page.getByLabel('Minimum grade points', { exact: true }).fill('11');
        await expect(page.getByRole('button', { name: 'Save requirements', exact: true })).toBeDisabled();
        await page.getByLabel('Minimum grade points', { exact: true }).fill('2.6751');
        await expect(page.getByRole('button', { name: 'Save requirements', exact: true })).toBeDisabled();
        await page.getByLabel('Minimum grade points', { exact: true }).fill('2.675');
        await page.getByLabel('Courses required', { exact: true }).fill('2');
        await page.getByRole('button', { name: 'Remove MATH.110 from requirement 1', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Save requirements', exact: true })).toBeDisabled();
        await expect(page.getByLabel('Courses required', { exact: true })).toHaveValue('2');
        for (const code of ['MATH.110', 'ENG.101']) {
            await page.getByRole('button', { name: 'Add course to requirement 1', exact: true }).click();
            await expect(page.getByRole('button', { name: 'Choose MATH.101', exact: true })).toHaveCount(0);
            await page.getByRole('button', { name: `Choose ${code}`, exact: true }).click();
        }
        await expect(page.getByLabel('Courses required', { exact: true })).toHaveValue('2');
        await expect(page.getByRole('region', { name: 'Requirement 1', exact: true })).toContainText('at least 2 of these 3');
        await expect(page.getByLabel('Minimum grade points', { exact: true })).toHaveValue('2.675');
    }
    await page.getByRole('button', { name: 'Add requirement', exact: true }).click();
    await page.getByRole('button', { name: 'Add course to requirement 2', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Choose MATH.101', exact: true })).toBeVisible();
    await page.getByRole('button', { name: counted ? 'Choose MATH.110' : 'Choose ENG.101', exact: true }).click();
    if (counted) await page.getByRole('region', { name: 'Requirement 2', exact: true }).getByLabel('Minimum grade points', { exact: true }).fill('0');
    await page.getByLabel('Qualifying prerequisite coursework', { exact: true }).selectOption(counted ? 'transfer' : 'institutional');
    await page.getByRole('button', { name: 'Add co-requisite', exact: true }).click();
    await page.getByRole('button', { name: 'Choose CHEM.101L', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Remove co-requisite CHEM.101L', exact: true })).toBeVisible();
    if (pathways) {
        await page.getByLabel('Allow alternative requirements', { exact: true }).check();
        await page.getByLabel('Requirements needed', { exact: true }).fill('3');
        await expect(page.getByRole('button', { name: 'Save requirements', exact: true })).toBeDisabled();
        await page.getByLabel('Requirements needed', { exact: true }).fill('1');
        await expect(page.getByText(/Meet at least 1 of 2 requirements/)).toBeVisible();
    }
    await expect(page.getByRole('button', { name: 'Review course publication', exact: true })).toBeDisabled();
    await page.setViewportSize({ width: 375, height: 812 });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Save requirements', exact: true }).click();
    await expect(page.getByLabel('Qualifying prerequisite coursework', { exact: true })).toHaveValue(counted ? 'transfer' : 'institutional');
    await expect(page.getByRole('region', { name: 'Requirement 1', exact: true })).toContainText('MATH.110');
    await expect(page.getByRole('region', { name: 'Requirement 2', exact: true })).toContainText(counted ? 'MATH.110' : 'ENG.101');
    if (counted) await expect(page.getByRole('region', { name: 'Requirement 1', exact: true }).getByLabel('Minimum grade points', { exact: true })).toHaveValue('2.675');
    await page.getByRole('button', { name: 'Save requirements', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Requirement 3', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Review course publication', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Remove requirement 3', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review course publication', exact: true })).toBeEnabled();
    await page.getByRole('button', { name: 'Review course publication', exact: true }).click();
    await page.getByLabel('Reason for publication', { exact: true }).fill('Fictional reviewed prerequisite choices');
    await page.getByRole('button', { name: 'Review current definition', exact: true }).click();
    await page.getByText(pathways ? 'Meet 1 of 2 prerequisite requirements' : counted ? 'Meet the course counts in 2 requirements' : 'Complete one course from each of 2 requirements', { exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText(counted ? 'At least 2 of: MATH.101 · Algebra <b>fictional</b>; MATH.110 · Foundations; ENG.101 · Academic writing' : 'MATH.101 · Algebra <b>fictional</b> OR MATH.110 · Foundations');
    await expect(page.locator('#drawer-body')).toContainText('ENG.101 · Academic writing');
    if (counted) await expect(page.locator('#drawer-body')).toContainText('Minimum 2.675 grade points per counted course');
    await expect(page.locator('#drawer-body')).toContainText('Co-requisites — all required');
    await expect(page.locator('#drawer-body')).toContainText('CHEM.101L · Fictional laboratory');
    await expect(page.locator('#drawer-body b,#drawer-body script')).toHaveCount(0);
    const confirm = page.getByRole('button', { name: 'Publish reviewed definition', exact: true });
    await confirm.focus(); await page.keyboard.press('Enter');
    await expect(page.locator('#drawer-body')).toContainText('published');
    expect(groups).toEqual(counted ? [[id(2), id(3), id(4)], [id(3)]] : [[id(2), id(3)], [id(4)]]);
    expect(minimumRequirements).toBe(pathways ? 1 : null);
    expect(minimums).toEqual(counted ? [2, 1] : [1, 1]);
    expect(corequisites).toEqual([id(5)]);
    expect(gradePoints).toEqual(counted ? [2675, 0] : [null, null]);
    const saves = calls.filter(call => call.path.endsWith('/prerequisite-groups/') && call.data);
    expect(saves).toHaveLength(2);
    expect(saves[0].data).toEqual(saves[1].data);
    expect(calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});
