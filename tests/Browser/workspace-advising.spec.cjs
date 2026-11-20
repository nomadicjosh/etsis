"use strict";
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');

test('registrar reviews dated advisor changes and returns to the same student programs', async ({ page }) => {
    const student = id(1), person = id(2), pursuit = id(3), program = id(4), term = id(5), advisor = id(6);
    const name = 'Fictional <b>Advisor</b>';
    let rows = [], revision = 'a'.repeat(64), sequence = 10;
    const calls = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/operation-id/') result = { id: id(sequence++) };
        else if (path === '/sis/ui/data/students/') result = { items: [{ id: student, person_id: person,
            given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true }], next_after: null };
        else if (path === `/sis/students/${student}/`) result = { id: student, person_id: person,
            given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true,
            primary_program: { id: program, code: 'BS.CS', title: 'Fictional computing' }, entry_term: { id: term, code: '26/FA' }, revision: 'student-revision' };
        else if (path === `/sis/students/${student}/programs/`) result = { items: [{ id: pursuit,
            program_id: program, program_code: 'BS.CS', program_title: 'Fictional computing', term_code: '26/FA',
            status: 'active', is_primary: true }], next_after: null };
        else if (path.endsWith('/advisors/')) result = { rows, revision, editable: true, known_history: !!rows.length,
            program: { id: program, code: 'BS.CS', title: 'Fictional computing' }, status: 'active' };
        else if (path === '/sis/advisors/candidates/') result = { items: [{ id: advisor, given_name: 'Fictional', family_name: '<b>Advisor</b>' }], next_after: null };
        else if (path.endsWith('/advisors/review/')) result = { operation_id: id(30), revision: data.revision,
            rows: data.rows, before: rows, after: data.rows.map(row => ({ ...row, name })), reason: data.reason,
            review_token: 'fictional-advising-proof' };
        else if (path.endsWith('/advisors/commit/')) { rows = data.rows.map(row => ({ ...row, name })); revision = 'b'.repeat(64); result = { status: 'updated' }; }
        else if (path.endsWith('/advisors/history/')) result = { items: [{ version: 1, rows, reason: 'Fictional retained decision.', recorded_at: '2026-08-10T17:00:00Z' }], next_cursor: null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator('#directory-search').fill('Alex');
    await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await recordAction(page, 'Academic advisors');
    await page.getByRole('button', { name: 'Add advisor', exact: true }).click();
    await expect(page.getByLabel('Find a faculty or staff member', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Choose advisor', exact: true }).click();
    await page.getByRole('button', { name: 'Review changes', exact: true }).click();
    expect(calls.filter(call => call.path.endsWith('/advisors/review/'))).toHaveLength(0);
    await page.getByLabel('Starts on', { exact: true }).fill('2026-08-10');
    await page.getByLabel('Reason for change', { exact: true }).fill('Fictional first advisor.');
    await page.setViewportSize({ width: 375, height: 812 });
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Review changes', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional first advisor.');
    await page.getByRole('button', { name: 'Keep editing', exact: true }).click();
    await expect(page.getByLabel('Reason for change', { exact: true })).toHaveValue('Fictional first advisor.');
    await page.getByRole('button', { name: 'Review changes', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm changes', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText(`${name} · Primary · 2026-08-10 through open`);
    await page.getByRole('button', { name: 'Assignment history', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional retained decision.');
    await page.getByRole('button', { name: 'Back to assignments', exact: true }).click();
    await page.getByRole('button', { name: 'Back to programs', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Student programs', exact: true })).toContainText('BS.CS');
    expect(calls.find(call => call.path === '/sis/advisors/candidates/').data.search).toBe('');
    expect(calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});

test('assigned advisors search their portal worklist and open a private on-demand review', async ({ page }) => {
    const calls = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/portal/api/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { students: [] };
        if (path === '/portal/api/advising/') result = { items: [{ student_id: id(1), pursuit_id: id(3),
            name: 'Fictional <b>Learner</b>', institutional_id: 'S-FICTIONAL',
            program: { code: 'BS.CS', title: 'Fictional computing' }, entry_term: { code: '26/FA' } }], next_after: null };
        else if (path.endsWith('/progress/')) result = { status: 'needs_selection' };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/portal/student/advising/');
    await expect(page.getByRole('heading', { name: 'Fictional <b>Learner</b>', exact: true })).toBeVisible();
    expect(calls.some(call => call.path.endsWith('/progress/'))).toBe(false);
    await page.getByLabel('Find an advisee by name, ID or program', { exact: true }).fill('S-FICTIONAL');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await page.getByRole('button', { name: 'Program and degree progress', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('degree');
    expect(calls.some(call => call.path.endsWith('/progress/'))).toBe(true);
    await page.setViewportSize({ width: 375, height: 812 });
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await expect(page.locator('#campus-service-content b')).toHaveCount(0);
    expect(calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});
