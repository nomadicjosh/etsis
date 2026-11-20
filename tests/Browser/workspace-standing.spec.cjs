"use strict";
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');

for (const historical of [false, true]) test(`registrar ${historical ? 'corrects historical standing' : 'configures standing'} and reviews dated decisions with a return from degree progress`, async ({ page }) => {
    const student = id(1), person = id(2), pursuit = id(3), program = id(4), term = id(5);
    const title = 'Fictional <b>Good standing</b>';
    let definitions = [], rows = [], revision = 'a'.repeat(64), sequence = 10;
    const calls = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/operation-id/') result = { id: id(sequence++) };
        else if (path === '/sis/academic-standing/save/') {
            result = { ...data, id: data.id || id(6), version: (data.version || 0) + 1 };
            definitions = [result];
        } else if (path === '/sis/academic-standing/definitions/' || path === '/sis/academic-standing/choices/') result = { items: definitions, next_after: null };
        else if (path === '/sis/ui/data/students/') result = { items: [{ id: student, person_id: person,
            given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true }], next_after: null };
        else if (path === `/sis/students/${student}/`) result = { id: student, person_id: person,
            given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true,
            primary_program: { id: program, code: 'BS.CS', title: 'Fictional computing' }, entry_term: { id: term, code: '26/FA' }, revision: 'student-revision' };
        else if (path === `/sis/students/${student}/programs/`) result = { items: [{ id: pursuit,
            program_id: program, program_code: 'BS.CS', program_title: 'Fictional computing', term_code: '26/FA',
            status: historical ? 'closed' : 'active', is_primary: true }], next_after: null };
        else if (path.endsWith('/standing/')) result = { rows, revision, editable: true, known_history: !!rows.length,
            program: { id: program, code: 'BS.CS', title: 'Fictional computing' }, status: historical ? 'closed' : 'active', historical_correction: historical };
        else if (path.endsWith('/standing/review/')) result = { operation_id: id(30), revision: data.revision,
            rows: data.rows, before: rows, after: data.rows, reason: data.reason, historical_evidence: data.historical_evidence, review_token: 'fictional-standing-proof' };
        else if (path.endsWith('/standing/commit/')) { rows = data.rows; revision = 'b'.repeat(64); result = { status: 'updated' }; }
        else if (path.endsWith('/standing/history/')) result = { items: [{ version: 1, rows, reason: 'Fictional retained decision.', recorded_at: '2026-08-10T17:00:00Z' }], next_cursor: null };
        else if (path.endsWith('/degree-requirements/progress/')) result = { status: 'needs_selection' };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.getByRole('button', { name: 'Configure academic standing definitions', exact: true }).click();
    await page.getByRole('button', { name: 'Add definition', exact: true }).click();
    await page.getByLabel('Code', { exact: true }).fill('GOOD');
    await page.getByLabel('Name', { exact: true }).fill(title);
    await page.getByRole('button', { name: 'Save definition', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText(title);
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.locator('#directory-search').fill('Alex');
    await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await recordAction(page, 'Academic standing');
    await page.getByRole('button', { name: 'Add period', exact: true }).click();
    await page.getByRole('button', { name: 'Use standing', exact: true }).click();
    await page.getByRole('button', { name: 'Review changes', exact: true }).click();
    expect(calls.filter(call => call.path.endsWith('/standing/review/'))).toHaveLength(0);
    await page.getByLabel('Effective from', { exact: true }).fill('2026-08-01');
    await page.getByLabel('Policy reference (optional)', { exact: true }).fill('Fictional handbook 2026');
    await page.getByLabel('Reason for change', { exact: true }).fill('Fictional registrar review.');
    if (historical) {
        await page.getByRole('button', { name: 'Review changes', exact: true }).click();
        expect(calls.filter(call => call.path.endsWith('/standing/review/'))).toHaveLength(0);
        await page.getByLabel('I verified institutional evidence for this historical correction', { exact: true }).check();
    }
    await page.getByRole('button', { name: 'Degree progress', exact: true }).click();
    await page.getByRole('button', { name: 'Back to academic standing', exact: true }).click();
    await expect(page.getByLabel('Reason for change', { exact: true })).toHaveValue('Fictional registrar review.');
    await expect(page.getByLabel('Effective from', { exact: true })).toHaveValue('2026-08-01');
    await page.setViewportSize({ width: 375, height: 812 });
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Review changes', exact: true }).click();
    await page.getByRole('button', { name: 'Keep editing', exact: true }).click();
    await expect(page.getByLabel('Reason for change', { exact: true })).toHaveValue('Fictional registrar review.');
    await page.getByRole('button', { name: 'Review changes', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm changes', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText(`${title} · 2026-08-01 through open`);
    await page.getByRole('button', { name: 'Standing history', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional retained decision.');
    await page.getByRole('button', { name: 'Back to standing', exact: true }).click();
    await expect(page.locator('#drawer-body b')).toHaveCount(0);
    expect(calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(calls.find(call => call.path.endsWith('/standing/commit/')).data.historical_evidence).toBe(historical);
    expect(errors).toEqual([]);
});

test('Report Studio opens the standing source with useful fields and a private bounded preview', async ({ page }) => {
    const errors = [], calls = [];
    page.on('pageerror', error => errors.push(error.message));
    const field = (label, type, relation) => ({ label, type, relation });
    const dataset = { id: 'standing', label: 'Academic standing periods', description: 'One row per recorded standing period.',
        fields: { institutional_id: field('Institutional ID', 'string', 'identity'), full_name: field('Student full name', 'string', 'person'),
            program_code: field('Program code', 'string', 'program'), standing_title: field('Standing name', 'string', 'standing'),
            effective_from: field('Effective from', 'date', 'standing') },
        relationships: { student: { label: 'Student records', parent: 'standing', many: false },
            person: { label: 'Student names', parent: 'student', many: false },
            identity: { label: 'Institutional IDs', parent: 'person', many: false },
            program: { label: 'Academic programs', parent: 'student', many: false } } };
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/report-studio/catalog/') result = { datasets: [dataset] };
        else if (path === '/sis/report-studio/graph/') result = { dataset };
        else if (path.endsWith('/preview/')) result = { columns: data.definition.columns.map(column => column.label),
            items: [{ c0: 'S-FICTIONAL', c1: 'Alex Example', c2: 'BS.CS', c3: 'Retained good standing', c4: '2026-08-01' }],
            offset: 0, next_offset: null, limit: 50, grouped: false };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="reports"]').click();
    await page.getByRole('button', { name: 'Open Report Studio', exact: true }).click();
    await page.getByRole('button', { name: 'Start report: Academic standing periods', exact: true }).click();
    await page.getByLabel('Report name', { exact: true }).fill('Fictional academic standing report');
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Retained good standing');
    const query = calls.find(call => call.path.endsWith('/preview/')).data.definition;
    expect(query.dataset).toBe('standing');
    expect(query.columns.map(column => column.field)).toEqual(['institutional_id', 'full_name', 'program_code', 'standing_title', 'effective_from']);
    await page.setViewportSize({ width: 375, height: 812 });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(errors).toEqual([]);
});
