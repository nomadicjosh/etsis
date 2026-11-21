"use strict";
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');

test('registrar searches, reviews, preserves edits, approves and revokes a scoped prerequisite exception', async ({ page }) => {
    const student = id(1), pursuit = id(3), section = id(6), base = `/sis/students/${student}/programs/${pursuit}/prerequisite-exceptions/`;
    const calls = [], errors = [];
    let approval = null, position = 0;
    const sectionLabel = { id: section, code: 'MATH.201.01', course_title: 'Fictional <b>Calculus</b>', term_code: '26/FA' };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/students/') result = { items: [{ id: student, person_id: id(2), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true }], next_after: null };
        else if (path === `/sis/students/${student}/`) result = { id: student, person_id: id(2), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true, primary_program: { id: id(4), code: 'BS.CS', title: 'Fictional computing' }, entry_term: { id: id(5), code: '26/FA' }, revision: 'student' };
        else if (path.endsWith('/programs/')) result = { items: [{ id: pursuit, program_id: id(4), program_code: 'BS.CS', program_title: 'Fictional computing', term_code: '26/FA', status: 'active', is_primary: true }], next_after: null };
        else if (path === `${base}sections/`) result = { items: [sectionLabel], next_after: null };
        else if (path === `${base}${section}/view/`) result = { section: sectionLabel, state: position ? { approval, position } : null, revision: 'a'.repeat(64), editable: true, active: !!approval };
        else if (path.endsWith('/review/') && path.startsWith(base)) result = { stream_id: id(10), operation_id: id(11), revision: data.revision, reason: data.reason, before: approval,
            after: data.expires_at ? { term_id: id(5), course_version_id: id(9), expires_at: data.expires_at } : null,
            approval: data.expires_at ? { term_id: id(5), course_version_id: id(9), expires_at: data.expires_at } : null, review_token: 'fictional-exception-proof' };
        else if (path.endsWith('/commit/') && path.startsWith(base)) { approval = data.approval; position++; result = { status: approval ? 'approved' : 'revoked' }; }
        else if (path.endsWith('/history/') && path.startsWith(base)) result = { items: [{ version: position, approval, effective_at: '2026-08-10T17:00:00Z', reason: 'Fictional reviewed reason.' }], next_cursor: null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator('#directory-search').fill('Alex');
    await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await recordAction(page, 'Prerequisite exceptions');
    await page.getByLabel('Find a section, course or term', { exact: true }).fill('MATH');
    await page.getByRole('button', { name: 'Search sections', exact: true }).click();
    await page.getByRole('button', { name: /MATH\.201\.01 · Fictional/ }).click();
    await expect(page.locator('#drawer-body b')).toHaveCount(0);
    await page.getByLabel('Expires at', { exact: true }).fill('2026-08-20T17:00');
    await page.getByLabel('Reason', { exact: true }).fill('Fictional reviewed reason.');
    await page.getByRole('button', { name: 'Review approval', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Every other registration check still applies');
    await page.getByRole('button', { name: 'Back to edit', exact: true }).click();
    await expect(page.getByLabel('Expires at', { exact: true })).toHaveValue('2026-08-20T17:00');
    await expect(page.getByLabel('Reason', { exact: true })).toHaveValue('Fictional reviewed reason.');
    await page.setViewportSize({ width: 375, height: 812 });
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Review approval', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm exception', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Approved until');
    await page.getByLabel('Reason', { exact: true }).fill('Fictional revocation.');
    await page.getByRole('button', { name: 'Review revocation', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm exception', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('No prerequisite exception');
    await page.getByRole('button', { name: 'History', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional reviewed reason.');
    expect(calls.filter(c => c.path.endsWith('/commit/')).map(c => c.data.approval === null)).toEqual([false, true]);
    expect(calls.filter(c => c.data).every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
    await page.keyboard.press('Escape');
    await expect(page.locator('#record-drawer')).not.toBeVisible();
});
