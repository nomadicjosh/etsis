const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const fs = require('node:fs/promises');
const id = number => String(number).padStart(26, '0');
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
}

test('teaching pages, assignment edits, attendance corrections and explicit roster download', async ({ page }) => {
    const section = id(1); const assignment = id(2); const student = id(100);
    const calls = []; const errors = []; let title = 'Quiz one'; let mark = 'present';
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/teaching/') result = { items: [{ id: section, code: 'MATH.101.A', course_code: 'MATH.101', course_title: 'Algebra', term_code: '26/FA', capacity: 60 }], next_after: null };
        else if (path.startsWith('/sis/ui/data/')) result = { items: [], next_after: null };
        else if (path === `/sis/ui/context/roster/${section}/`) {
            const rows = Array.from({ length: 51 }, (_, index) => ({ id: id(200 + index), student_id: id(100 + index), given_name: index === 0 ? 'Alex' : `Student ${index}`, family_name: 'Example', institutional_id: `S-${index}`, status: 'A', grade_version: 0, grade_code: null }));
            result = { items: data.after ? rows.slice(50) : rows.slice(0, 50), next_after: data.after ? null : id(249), can_grade: true };
        } else if (path === `/sis/ui/context/assignments/${section}/`) result = { items: [{ id: assignment, title, possible_hundredths: 1000, due_on: '2026-08-11' }], next_after: null };
        else if (path === `/sis/assignments/${assignment}/`) result = { id: assignment, title, possible_hundredths: 1000, due_on: '2026-08-11', revision: 'fictional-assignment-revision' };
        else if (path === `/sis/assignments/${assignment}/edit/`) title = data.title;
        else if (path.endsWith('/score-page/')) result = { items: [{ assignment_id: assignment, title, possible_hundredths: 1000, earned_hundredths: 0, version: 1 }], next_after: null };
        else if (path === `/sis/sections/${section}/students/${student}/attendance/`) result = { items: [{ id: id(500), held_on: '2026-08-11', status: mark, revision: 'fictional-attendance-revision' }], counts: { [mark]: 1 }, term: { starts_on: '2026-08-01', ends_on: '2026-12-20' }, next_after: null };
        else if (path === `/sis/sections/${section}/attendance/`) mark = data.status;
        else if (path.startsWith('/sis/ui/exports/')) {
            await route.fulfill({ contentType: 'text/csv; charset=UTF-8', body: 'Institutional ID,Given name\nS-0,Alex\n', headers: { 'Cache-Control': 'private, no-store' } }); return;
        }
        await route.fulfill({ json: result });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="teaching"]').click();
    await expect(page.locator('#directory-results')).toContainText('26/FA');
    await page.getByRole('button', { name: 'MATH.101.A', exact: true }).click();
    const roster = page.locator('section[aria-label="Section roster"]');
    await expect(roster.locator('tbody tr')).toHaveCount(50);
    await roster.getByRole('button', { name: 'Next page', exact: true }).click();
    await expect(roster.locator('tbody tr')).toHaveCount(1);
    await roster.getByRole('button', { name: 'Previous page', exact: true }).click();
    await expect(roster.locator('tbody tr')).toHaveCount(50);
    const downloadReady = page.waitForEvent('download');
    await roster.getByRole('button', { name: 'Download this roster page (CSV)', exact: true }).click();
    const download = await downloadReady;
    expect(download.suggestedFilename()).toBe('section-roster.csv');
    expect(await fs.readFile(await download.path(), 'utf8')).toContain('S-0,Alex');
    expect(calls.find(item => item.path.includes('/exports/'))).toMatchObject({ data: { after: '' }, csrf: 'fictional-test-token' });
    await page.getByRole('button', { name: 'Edit assignment', exact: true }).click();
    await page.getByLabel('Assignment title').fill('Revised quiz');
    await page.getByLabel('Possible points').fill('12.50');
    await accessible(page);
    await page.getByRole('button', { name: 'Save assignment changes', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Revised quiz');
    expect(calls.find(item => item.path.endsWith('/edit/'))?.data).toMatchObject({ possible_hundredths: 1250, revision: 'fictional-assignment-revision' });
    await page.getByRole('button', { name: 'Grade & attendance', exact: true }).first().click();
    await expect(page.locator('#drawer-body')).toContainText('0 / 10');
    await page.getByRole('button', { name: 'Correct mark', exact: true }).click();
    await expect(page.getByLabel('Class date')).toHaveAttribute('readonly', '');
    await expect(page.getByLabel('Class date')).toHaveAttribute('min', '2026-08-01');
    await page.getByRole('combobox', { name: 'Attendance' }).selectOption('late');
    await page.getByRole('button', { name: 'Save attendance correction', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('late: 1');
    expect(calls.find(item => item.path === `/sis/sections/${section}/attendance/`)?.data).toMatchObject({ student_id: student, status: 'late', revision: 'fictional-attendance-revision' });
    await accessible(page);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('private native validation failures have an accessible recovery screen with no payload leak', async ({ page }) => {
    const response = await page.goto('/admin/error-fixture/');
    expect(response.status()).toBe(422);
    expect(response.headers()['cache-control']).toContain('no-store');
    await expect(page.getByRole('heading', { name: 'Please check the form' })).toBeVisible();
    await expect(page.locator('body')).not.toContainText('fictional-secret');
    await accessible(page);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
});
