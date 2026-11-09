const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K000000000000000000000${String(n).padStart(2, '0')}`;
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
}
async function fixture(page, options = {}) {
    const calls = [], errors = []; let definition = null, version = 0, name = '', operation = 1;
    const fields = {
        section_code: { label: 'Section code', type: 'string', relation: 'section' },
        term_code: { label: 'Academic term', type: 'string', relation: 'term' },
        capacity: { label: 'Section capacity', type: 'integer', relation: 'section' },
        enrollment_id: { label: 'Enrollment reference', type: 'string', relation: 'enrollment' },
        enrollment_status: { label: 'Enrollment status', type: 'string', relation: 'enrollment' },
        course_title: { label: 'Course title', type: 'string', relation: 'course' },
    };
    const dataset = { id: 'sections', label: 'Sections, teaching & enrollment counts', description: 'Explore sections and their registration relationships.', fields,
        relationships: { term: { label: 'Term', parent: 'section', many: false }, course: { label: 'Course', parent: 'section', many: false }, enrollment: { label: 'Enrollment', parent: 'section', many: true } } };
    const visited = [];
    if (options.recipient) {
        name = 'Fictional report from the registrar';
        definition = { dataset: 'sections', columns: [
            { field: 'section_code', label: 'Section', aggregate: 'none' },
            { field: 'term_code', label: 'Term', aggregate: 'none' },
            { field: 'enrollment_id', label: 'Students', aggregate: 'count_distinct' },
        ], joins: { enrollment: 'left' }, sort: [] };
    }
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        visited.push(path);
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/operation-id/') result = { id: id(operation++) };
        else if (path.startsWith('/sis/ui/data/')) result = { items: options.recipient ? [{ id: id(71), name }] : [], next_after: null };
        else if (path === `/sis/reports/${id(71)}/`) result = { id: id(71), name, relational: true };
        else if (path === '/sis/report-studio/catalog/') result = { datasets: [dataset] };
        else if (path === '/sis/report-studio/save/') { definition = data.definition; name = data.name; version = data.version + 1; result = { id: data.report_id }; }
        else if (path.endsWith('/preview/') || path.endsWith('/run/')) {
            const query = data.definition || definition;
            const rows = data.offset ? [{ c0: 'CHEM.101.A', c1: '26/FA', c2: 5 }] : [{ c0: 'MATH.101.A', c1: '26/FA', c2: 8 }, { c0: 'ENG.101.A', c1: '26/FA', c2: 0 }];
            result = { columns: query.columns.map(column => column.label), items: rows, offset: data.offset, next_offset: data.offset ? null : 50, limit: 50, grouped: true };
        } else if (path.endsWith('/export/')) { await route.fulfill({ body: 'Section,Term,Students\nMATH.101.A,26/FA,8\n', contentType: 'text/csv' }); return; }
        else if (/\/sis\/report-studio\/[^/]+\/$/.test(path)) result = { id: path.split('/').at(-2), name, version, definition, can_manage: !options.recipient };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(options.recipient ? '/admin/report-fixture/' : '/admin/');
    await page.locator(`.workspace-nav-link[data-workspace="${options.recipient ? 'sharedreports' : 'reports'}"]`).click();
    await page.getByRole('button', { name: options.recipient ? name : 'Open Report Studio', exact: true }).click();
    return { calls, errors, visited };
}

test('staff build a joined headcount report with visual conditions, live preview, charts, versioned save and page export', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Section headcounts, including empty sections', exact: true }).click();
    await page.getByLabel('Report name', { exact: true }).fill('Fictional section headcounts');
    await page.getByRole('tab', { name: 'Conditions', exact: true }).click();
    await expect(page.getByRole('group', { name: 'Enrollment conditions', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Preview report', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('ENG.101.A');
    await expect(page.getByRole('img', { name: /Bar chart/ })).toBeVisible();
    const preview = f.calls.find(call => call.path.endsWith('/preview/'));
    expect(preview).toMatchObject({ data: { offset: 0, definition: { dataset: 'sections', joins: { enrollment: 'left' }, join_filters: { enrollment: { condition: 'AND', rules: [{ id: 'enrollment_status', operator: 'in', value: ['A', 'N'] }] } } } }, csrf: 'fictional-test-token' });
    await page.getByRole('button', { name: 'Next result page', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('CHEM.101.A');
    await page.getByRole('button', { name: 'Previous result page', exact: true }).click();
    await page.screenshot({ path: test.info().outputPath('report-studio-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.screenshot({ path: test.info().outputPath('report-studio-mobile.png'), fullPage: true });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Save report definition', exact: true }).click();
    await expect(page.locator('.report-workbench')).toContainText('Definition revision 1');
    const download = page.waitForEvent('download'); await page.getByRole('button', { name: 'Export this result page', exact: true }).click();
    expect((await download).suggestedFilename()).toBe('report-studio-page.csv');
    await page.getByRole('button', { name: 'Edit report in Studio', exact: true }).click();
    await expect(page.getByLabel('Report name', { exact: true })).toHaveValue('Fictional section headcounts');
    await page.getByLabel('Column 3 label', { exact: true }).fill('Students <img src=x onerror=alert(1)>');
    await page.getByRole('button', { name: /^Column 3 settings:/ }).click();
    await page.getByRole('group', { name: 'Column 3 summary', exact: true }).getByRole('button', { name: 'Count', exact: true }).click();
    await expect(page.locator('.report-workbench img')).toHaveCount(0);
    await page.getByRole('button', { name: 'Save report definition', exact: true }).click();
    await expect(page.locator('.report-workbench')).toContainText('Definition revision 2');
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('report filters stay in the editor and support nested field comparisons without navigating away', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Section headcounts, including empty sections', exact: true }).click();
    await page.getByRole('tab', { name: 'Conditions', exact: true }).click();
    const rows = page.getByRole('group', { name: 'Row conditions', exact: true });
    await rows.getByLabel('Row conditions: field', { exact: true }).selectOption('term_code');
    await rows.getByLabel('Row conditions: value', { exact: true }).fill('26/FA');
    await page.getByRole('button', { name: 'Preview report', exact: true }).click();
    expect(f.calls.find(call => call.path.endsWith('/preview/')).data.definition.filters.rules[0]).toMatchObject({ id: 'term_code', operator: 'equal', value: '26/FA' });
    await expect(page.getByLabel('Row conditions: value', { exact: true })).toHaveValue('26/FA');
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    expect(f.errors).toEqual([]);
});

test('an uncertain save retains exactly the captured definition for a safe retry', async ({ page }) => {
    const f = await fixture(page);
    const attempts = [];
    await page.route('**/sis/report-studio/save/', async route => {
        attempts.push(JSON.parse(new URLSearchParams(route.request().postData()).get('_workspace_json')));
        if (attempts.length === 1) {
            await route.fulfill({ status: 500, json: { error: 'Fictional interrupted save' } });
            return;
        }
        await route.fallback();
    });
    await page.getByRole('button', { name: 'Section headcounts, including empty sections', exact: true }).click();
    await page.getByLabel('Report name', { exact: true }).fill('Fictional retry report');
    const save = page.getByRole('button', { name: 'Save report definition', exact: true });
    await save.click();
    await expect(page.getByLabel('Report name', { exact: true })).toBeDisabled();
    await expect(save).toBeEnabled();
    await save.click();
    await expect(page.locator('.report-workbench')).toContainText('Definition revision 1');
    expect(attempts).toHaveLength(2);
    expect(attempts[1]).toEqual(attempts[0]);
    expect(f.errors).toEqual([]);
});

test('an old preview response cannot replace the results after the report draft changes', async ({ page }) => {
    const f = await fixture(page);
    let release;
    const hold = new Promise(resolve => { release = resolve; });
    let started;
    const pending = new Promise(resolve => { started = resolve; });
    await page.route('**/sis/report-studio/preview/', async route => {
        started();
        await hold;
        await route.fallback();
    });
    await page.getByRole('button', { name: 'Section headcounts, including empty sections', exact: true }).click();
    await page.getByRole('button', { name: 'Preview report', exact: true }).click();
    await pending;
    await page.getByLabel('Column 3 label', { exact: true }).fill('Changed during request');
    release();
    await expect(page.locator('.report-workbench')).toContainText('The draft changed during the preview');
    await expect(page.getByRole('region', { name: 'Relational report results', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('the full-width visual map supports field checkboxes, field drops, column reordering and movable cards', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Section headcounts, including empty sections', exact: true }).click();
    const workspace = page.locator('.report-workbench');
    expect((await workspace.boundingBox()).width).toBeGreaterThan(1400);
    await expect(workspace.locator('select:visible')).toHaveCount(0);
    const capacity = page.locator('[data-report-field="capacity"] input');
    await capacity.check();
    await expect(page.getByLabel('Column 4 label', { exact: true })).toHaveValue('Section capacity');
    const dropArea = page.getByRole('list', { name: 'Selected report columns' });
    const dropBox = await dropArea.boundingBox();
    await page.locator('[data-report-field="term_code"] .report-field-grip').dragTo(dropArea, { targetPosition: { x: 20, y: dropBox.height - 2 } });
    await expect(page.getByLabel('Column 5 label', { exact: true })).toHaveValue('Academic term');
    await page.getByRole('button', { name: 'Move column 5', exact: true }).dragTo(page.locator('[data-column-index="0"]'));
    await expect(page.getByLabel('Column 1 label', { exact: true })).toHaveValue('Academic term');
    await expect(page.getByLabel('Column 2 label', { exact: true })).toHaveValue('Section');
    const card = page.locator('[data-relation="section"]');
    const before = await card.evaluate(element => element.style.left);
    const handle = page.getByRole('button', { name: 'Move Sections card', exact: true });
    await handle.focus(); await page.keyboard.press('ArrowRight');
    expect(await card.evaluate(element => element.style.left)).not.toBe(before);
    const box = await handle.boundingBox();
    await page.mouse.move(box.x + 50, box.y + 20); await page.mouse.down();
    await page.mouse.move(box.x + 110, box.y + 55, { steps: 6 }); await page.mouse.up();
    await expect(page.locator('.report-map-connections path')).toHaveCount(2);
    await page.getByRole('button', { name: 'Arrange cards', exact: true }).click();
    await workspace.evaluate(element => { element.scrollTop = 0; });
    await page.screenshot({ path: test.info().outputPath('visual-report-builder-desktop.png'), fullPage: true });
    await accessible(page);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    await page.screenshot({ path: test.info().outputPath('visual-report-builder-mobile.png'), fullPage: true });
    expect(await workspace.evaluate(element => element.scrollWidth <= element.clientWidth)).toBe(true);
    await page.getByRole('button', { name: 'Preview report', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Relational report results', exact: true })).toBeVisible();
    expect(f.calls.find(call => call.path.endsWith('/preview/')).data.definition.columns.map(c => c.label)).toEqual(['Academic term', 'Section', 'Term', 'Students', 'Section capacity']);
    await page.keyboard.press('Escape');
    await expect(workspace).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Open Report Studio', exact: true })).toBeFocused();
    expect(f.errors).toEqual([]);
});

test('summary filters and sort priorities follow their columns when a creator changes the output order', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Section headcounts, including empty sections', exact: true }).click();
    await page.getByRole('tab', { name: 'Conditions', exact: true }).click();
    const totals = page.getByRole('group', { name: 'Summary conditions', exact: true });
    await totals.getByLabel('Summary conditions: field', { exact: true }).selectOption('c2');
    await totals.getByLabel('Summary conditions: comparison', { exact: true }).selectOption('less');
    await totals.getByLabel('Summary conditions: value', { exact: true }).fill('12');
    await page.getByRole('tab', { name: 'Build', exact: true }).click();
    await page.getByRole('button', { name: 'Sort column 3: off', exact: true }).click();
    await page.getByRole('button', { name: 'Move column 3 up', exact: true }).click();
    await page.getByRole('button', { name: 'Preview report', exact: true }).click();
    const query = f.calls.find(call => call.path.endsWith('/preview/')).data.definition;
    expect(query.columns[1].label).toBe('Students');
    expect(query.having.rules[0]).toMatchObject({ id: 'c1', operator: 'less', value: 12 });
    expect(query.sort).toEqual([{ column: 1, direction: 'asc' }]);
    expect(f.errors).toEqual([]);
});

test('a faculty recipient opens a shared report without creator controls and revoked access clears results', async ({ page }) => {
    const f = await fixture(page, { recipient: true });
    const viewer = page.locator('.report-workbench');
    await expect(viewer).toContainText('SHARED WITH YOU');
    await expect(page.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('MATH.101.A');
    await expect(page.getByRole('button', { name: 'Edit report in Studio', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Manage report sharing', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Open Report Studio', exact: true })).toHaveCount(0);
    await expect(viewer.locator('input, select')).toHaveCount(0);
    expect(f.visited).not.toContain('/sis/report-studio/catalog/');
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.route('**/sis/report-studio/*/export/', route => route.fulfill({ status: 403, json: { error: 'Access denied.' } }));
    await page.getByRole('button', { name: 'Export this result page', exact: true }).click();
    await expect(viewer.getByRole('alert')).toContainText('Access denied.');
    await expect(viewer.locator('table')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Export this result page', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('removing a related record also removes its dependent columns, summaries and matching conditions', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Section headcounts, including empty sections', exact: true }).click();
    await page.getByRole('tab', { name: 'Conditions', exact: true }).click();
    const rows = page.getByRole('group', { name: 'Row conditions', exact: true });
    await rows.getByLabel('Row conditions: field', { exact: true }).selectOption('term_code');
    await rows.getByLabel('Row conditions: value', { exact: true }).fill('26/FA');
    await page.getByRole('tab', { name: 'Build', exact: true }).click();
    await page.getByRole('button', { name: 'Remove Term and its fields', exact: true }).click();
    await expect(page.locator('[data-relation="term"]')).toHaveCount(0);
    await page.getByRole('button', { name: 'Remove Enrollment and its fields', exact: true }).click();
    await expect(page.locator('[data-relation="enrollment"]')).toHaveCount(0);
    await expect(page.getByLabel('Column 1 label', { exact: true })).toHaveValue('Section');
    await expect(page.getByLabel('Column 2 label', { exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Preview report', exact: true }).click();
    const query = f.calls.find(call => call.path.endsWith('/preview/')).data.definition;
    expect(query.joins).toEqual({});
    expect(query.join_filters).toEqual({});
    expect(query.filters).toBeNull();
    expect(query.columns).toEqual([{ field: 'section_code', label: 'Section', aggregate: 'none' }]);
    expect(f.errors).toEqual([]);
});

test('a late page response cannot restore private results after a newer refresh denies access', async ({ page }) => {
    const f = await fixture(page, { recipient: true });
    await expect(page.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('MATH.101.A');
    let release, started;
    const hold = new Promise(resolve => { release = resolve; });
    const waiting = new Promise(resolve => { started = resolve; });
    await page.route('**/sis/report-studio/*/run/', async route => {
        const data = JSON.parse(new URLSearchParams(route.request().postData()).get('_workspace_json'));
        if (data.offset === 50) { started(); await hold; await route.fallback(); }
        else await route.fulfill({ status: 403, json: { error: 'Access denied.' } });
    });
    await page.getByRole('button', { name: 'Next result page', exact: true }).click();
    await waiting;
    await page.getByRole('button', { name: 'Refresh results', exact: true }).click();
    const viewer = page.locator('.report-workbench');
    await expect(viewer.getByRole('alert')).toContainText('Access denied.');
    const response = page.waitForResponse(r => r.url().endsWith('/run/') && r.status() === 200);
    release(); await response;
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    await expect(viewer.locator('table')).toHaveCount(0);
    await expect(viewer).not.toContainText('CHEM.101.A');
    expect(f.errors).toEqual([]);
});
