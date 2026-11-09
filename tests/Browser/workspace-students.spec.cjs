const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

test('registrars manage programs and student activity in one accessible record', async ({ page }) => {
    const student = id(1), person = id(2), term = id(3), original = id(4), added = id(5);
    let active = true, primary = original, version = 0;
    const calls = [], errors = [];
    const programs = Array.from({ length: 51 }, (_, index) => ({ id: id(100 + index),
        program_id: index === 0 ? original : id(200 + index), program_code: index === 0 ? 'BS.CS' : `FIX.${index}`,
        program_title: index === 0 ? 'Computer Science' : 'Fictional program', term_code: '26/FA', status: 'active' }));
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        const primaryRow = programs.find(row => row.program_id === primary);
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/students/') result = { items: [{ id: student, person_id: person,
            given_name: 'Alex', family_name: 'Example', institutional_id: 'S-EXAMPLE', active }], next_after: null };
        else if (path === '/sis/ui/data/programs/') result = { items: [{ id: added, code: 'BA.ART', title: 'Fictional arts' }], next_after: null };
        else if (path === '/sis/ui/data/terms/') result = { items: [{ id: term, code: '26/FA' }], next_after: null };
        else if (path.includes('/persons/') && path.endsWith('/restrictions/')) result = { items: [], next_after: null };
        else if (path.startsWith('/sis/ui/data/') || path.startsWith('/sis/ui/context/')) result = { items: [], next_after: null };
        else if (path === `/sis/students/${student}/record/`) result = { id: student, person_id: person,
            given_name: 'Alex', family_name: 'Example', institutional_id: 'S-EXAMPLE', active,
            primary_program: { id: primary, code: primaryRow.program_code, title: primaryRow.program_title },
            entry_term: { id: term, code: '26/FA' }, revision: `student-${version}` };
        else if (path === `/sis/students/${student}/programs/`) result = {
            items: (data.after ? programs.slice(50) : programs.slice(0, 50)).map(row => ({ ...row,
                is_primary: row.program_id === primary && row.status === 'active', revision: `${row.id}-${row.status}` })),
            next_after: data.after ? null : programs[49].id,
        };
        else if (path.endsWith('/programs/assign/')) { programs.unshift({ id: id(999), program_id: added, program_code: 'BA.ART',
            program_title: 'Fictional <b>Arts</b>', term_code: '26/FA', status: 'active' }); result = { id: id(999) }; }
        else if (path.endsWith('/primary-program/')) { primary = programs.find(row => row.id === data.assignment_id).program_id; version++; }
        else if (path === `/sis/students/${student}/activity/`) { active = data.active; version++; }
        else if (path.includes('/programs/') && path.endsWith('/activity/')) {
            programs.find(row => row.id === path.split('/')[5]).status = data.active ? 'active' : 'closed';
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    const accessible = async () => {
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    };
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    const programSection = page.locator('section[aria-label="Student programs"]');
    await expect(programSection.locator('tbody tr')).toHaveCount(50);
    await page.getByRole('button', { name: 'Next program page' }).click();
    await expect(programSection.locator('tbody tr')).toHaveCount(1);
    await page.getByRole('button', { name: 'Previous program page' }).click();
    await expect(programSection.locator('tbody tr')).toHaveCount(50);
    await page.getByRole('button', { name: 'Assign an additional program' }).click();
    await page.locator('#drawer-body').getByLabel('Academic program').selectOption(added);
    await page.locator('#drawer-body').getByLabel('Program entry term').selectOption(term);
    await page.getByRole('button', { name: 'Assign program', exact: true }).click();
    await expect(programSection).toContainText('Fictional <b>Arts</b>');
    expect(calls.find(call => call.path.endsWith('/programs/assign/'))?.data).toEqual({ program_id: added, entry_term_id: term });
    await expect(page.locator('#drawer-body b')).toHaveCount(0);
    const arts = programSection.locator('tbody tr').filter({ hasText: 'BA.ART' });
    await arts.getByRole('button', { name: 'Close assignment', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm program closure' }).click();
    await expect(arts).toContainText('closed');
    await arts.getByRole('button', { name: 'Reopen assignment', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm program reopening' }).click();
    await expect(arts).toContainText('active');
    await arts.getByRole('button', { name: 'Make primary', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm primary program', exact: true }).click();
    await expect(arts).toContainText('Primary');
    await expect(arts.getByRole('button', { name: 'Close assignment', exact: true })).toHaveCount(0);
    expect(calls.find(call => call.path.endsWith('/primary-program/'))?.data).toEqual({ assignment_id: id(999), revision: 'student-0' });
    await page.getByRole('button', { name: 'Change student activity' }).click();
    await page.getByLabel('Active student record').uncheck();
    await accessible();
    await page.getByRole('button', { name: 'Save student activity' }).click();
    await expect(page.locator('#drawer-body')).toContainText('Inactive');
    await expect(page.getByRole('button', { name: 'Register for a section', exact: true })).toHaveCount(0);
    expect(calls.find(call => call.path === `/sis/students/${student}/activity/`)?.data).toEqual({ active: false, revision: 'student-1' });
    await expect(page.locator('#drawer-body')).toContainText('26/FA');
    await accessible();
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible();
    expect(errors).toEqual([]);
    expect(calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
