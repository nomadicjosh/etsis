const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K000000000000000000000${String(n).padStart(2, '0')}`;
const yearId = id(1), typeId = id(2), termId = id(3), student = id(4), assignment = id(5), program = id(6), operation = id(7);
const year = { id: yearId, code: '2026-2027', start_year: 2026, end_year: 2027, starts_on: '2026-08-15', ends_on: '2026-12-15', term_count: 1, legacy: false };
const term = { id: termId, academic_year_id: yearId, academic_year_code: year.code, term_type_id: typeId, term_type_version: 0, code: '26/FA', name: 'Fall 2026', kind: 'standard', starts_on: year.starts_on, ends_on: year.ends_on, add_drop_ends_on: '2026-08-29', revision: 'b'.repeat(64), adopt_existing: false };
const dates = { starts_on: { month_day: '08-15', year: 'START_YEAR' }, ends_on: { month_day: '12-15', year: 'START_YEAR' }, add_drop_ends_on: { month_day: '08-29', year: 'START_YEAR' } };
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}
async function fixture(page, interrupted = false) {
    const calls = [], errors = []; let saved = false;
    let type = { id: typeId, code: 'FA', name_pattern: 'Fall {{START_YEAR}}', code_pattern: '{{START_YEAR_SHORT}}/FA', kind: 'standard', active: true, version: 0, dates };
    const plan = { ...year, terms: [term], revision: 'a'.repeat(64), operation_id: operation };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {}, status = 200;
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/operation-id/') result = { id: operation };
        else if (path.startsWith('/sis/ui/data/')) result = { items: path.includes('/terms/') ? [term] : path.includes('/students/') ? [{ id: student, given_name: 'Sadie', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true }] : [], next_after: null };
        else if (path === '/sis/academic-years/') result = { items: [year], next_after: null };
        else if (path === '/sis/term-types/') result = { items: [type], next_after: null };
        else if (path.startsWith('/sis/term-types/') && path.endsWith('/preview/')) {
            const replace = pattern => pattern.replaceAll('{{START_YEAR}}', String(data.start_year)).replaceAll('{{END_YEAR}}', String(data.start_year + 1)).replaceAll('{{START_YEAR_SHORT}}', String(data.start_year).slice(-2)).replaceAll('{{END_YEAR_SHORT}}', String(data.start_year + 1).slice(-2));
            result = { academic_year: `${data.start_year}-${data.start_year + 1}`, code: replace(data.code_pattern), name: replace(data.name_pattern),
                ...Object.fromEntries(['starts_on', 'ends_on', 'add_drop_ends_on'].map(key => [key, `${data.start_year + (data[`${key}_year`] === 'END_YEAR' ? 1 : 0)}-${data[`${key}_month_day`]}`])) };
        }
else if (path.startsWith('/sis/term-types/') && path.endsWith('/save/')) {
            type = { ...type, ...data, id: path.split('/')[3], version: data.version + 1, dates: Object.fromEntries(['starts_on', 'ends_on', 'add_drop_ends_on'].map(key => [key, { month_day: data[`${key}_month_day`], year: data[`${key}_year`] }])) }; result = type;
        } else if (path === '/sis/academic-years/review/') result = plan;
        else if (path === '/sis/academic-years/create/') { saved = true; result = plan; if (interrupted) { status = 500; result = { error: 'Fictional interrupted confirmation; check the saved year.' }; } }
        else if (path.includes('/receipts/')) result = plan;
        else if (path === `/sis/calendar/terms/${termId}/`) result = term;
        else if (path.endsWith('/record/')) result = { id: student, person_id: id(8), given_name: 'Sadie', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true, primary_program: { id: program, code: 'BA', title: 'Fictional Arts' }, entry_term: { id: termId, code: '24/FA' }, revision: 'c'.repeat(64) };
        else if (path.endsWith('/programs/')) result = { items: [{ id: assignment, program_id: program, program_code: 'BA', program_title: 'Fictional Arts', term_code: '24/FA', status: 'active', is_primary: true, catalog_year: '2024-2025', catalog_revision: 1 }], next_after: null };
        else if (path.endsWith('/catalog/')) result = { program: { code: 'BA', title: 'Fictional Arts' }, entry_term: { code: '24/FA' }, current: { year_code: '2024-2025', revision: 1 }, choices: [], next_after: null };
        else if (path.endsWith('/catalog/history/')) result = { items: [{ year_code: '2024-2025', catalog_revision: 1, created_at: '2024-08-15', note: 'Fictional <script>historical</script> note' }], next_after: null };
        else if (path.includes('/context/')) result = { items: [], next_after: null };
        await route.fulfill({ status, json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/'); await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    return { calls, errors };
}
async function review(page) {
    await page.getByRole('button', { name: 'Academic years', exact: true }).click();
    await page.getByRole('button', { name: 'Create academic year', exact: true }).click();
    await page.getByLabel('Start year', { exact: false }).fill('2026');
    await expect(page.locator('#drawer-body')).toContainText('2026–2027');
    await page.getByRole('button', { name: 'Review generated terms', exact: true }).click();
}

test('start-year-only review generates terms together without a separate catalog-year form', async ({ page }) => {
    const f = await fixture(page); await review(page);
    await expect(page.locator('#drawer-body')).toContainText('Fall 2026');
    await expect(page.locator('#drawer-body')).toContainText('26/FA');
    await expect(page.getByLabel('End year', { exact: false })).toHaveCount(0);
    expect(f.calls.some(call => call.path === '/sis/academic-years/create/')).toBe(false);
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.getByRole('button', { name: 'Confirm academic year and terms', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('2026-2027');
    expect(f.calls.find(call => call.path === '/sis/academic-years/review/')).toMatchObject({ data: { start_year: 2026 }, csrf: 'fictional-test-token' });
    expect(f.calls.find(call => call.path === '/sis/academic-years/create/')).toMatchObject({ data: { id: yearId, operation_id: operation, start_year: 2026, terms: [{ term_type_id: typeId, id: termId }], revision: 'a'.repeat(64) }, csrf: 'fictional-test-token' });
    expect(f.calls.some(call => call.path.includes('/catalog-years/'))).toBe(false); expect(f.errors).toEqual([]);
});

test('term types support year placeholders, anchored dates and future-only deactivation', async ({ page }) => {
    const f = await fixture(page); await page.getByRole('button', { name: 'Term types', exact: true }).click();
    await page.getByRole('button', { name: 'Add term type', exact: true }).click();
    await page.getByLabel('Term type code', { exact: false }).fill('SP');
    await page.getByLabel('Display name template', { exact: false }).fill('Spring {{END_YEAR}}');
    await page.getByLabel('Term code template', { exact: false }).fill('{{END_YEAR_SHORT}}/SP');
    for (const [label, day] of [['Term starts', '01-15'], ['Term ends', '05-15'], ['Drop/add deadline', '01-29']]) {
        await page.getByLabel(`${label}: month and day (MM-DD)`, { exact: false }).fill(day);
        await page.getByLabel(`${label}: year`, { exact: false }).selectOption('END_YEAR');
    }
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.getByRole('button', { name: 'Save term type', exact: true }).click();
    await page.getByRole('button', { name: 'Edit SP', exact: true }).click();
    await page.getByLabel('Use this type when generating new academic years', { exact: true }).uncheck();
    await page.getByRole('button', { name: 'Save term type', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Inactive');
    const writes = f.calls.filter(call => call.path.startsWith('/sis/term-types/') && call.path.endsWith('/save/'));
    expect(writes.map(call => call.data)).toMatchObject([{ version: -1, starts_on_year: 'END_YEAR', starts_on_month_day: '01-15', active: true }, { version: 0, active: false }]);
    expect(writes[0].csrf).toBe('fictional-test-token'); expect(f.errors).toEqual([]);
});

test('interrupted academic-year confirmation recovers its receipt without repeating generation', async ({ page }) => {
    const f = await fixture(page, true); await review(page);
    await page.getByRole('button', { name: 'Confirm academic year and terms', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('interrupted');
    await expect(page.getByRole('button', { name: 'Change start year', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Check saved academic year', exact: true }).click();
    await expect(page.locator('#drawer-title')).toHaveText('Academic year 2026-2027');
    await expect(page.locator('#drawer-body')).toContainText('Fall 2026');
    expect(f.calls.filter(call => call.path === '/sis/academic-years/create/')).toHaveLength(1);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0); expect(f.errors).toEqual([]);
});

test('separate Terms entry maintains generated terms and allows exceptional terms in an academic year', async ({ page }) => {
    const f = await fixture(page);
    await page.locator('#directory-tabs [data-workspace="terms"]').click();
    await page.getByRole('button', { name: '26/FA', exact: true }).click();
    await page.getByRole('button', { name: 'Edit catalog record', exact: true }).click();
    await expect(page.getByLabel('Academic year (required for new terms)', { exact: false })).toBeDisabled();
    await page.getByLabel('Term display name', { exact: false }).fill('Autumn 2026');
    await page.getByLabel('Last day to add or drop', { exact: false }).fill('2026-08-30');
    await page.getByRole('button', { name: 'Save academic term', exact: true }).click();
    await page.getByRole('button', { name: 'Add a term', exact: true }).click();
    await page.getByLabel('Academic year (required for new terms)', { exact: false }).selectOption(yearId);
    await page.getByLabel('Term code', { exact: false }).fill('26/MINI');
    await page.getByLabel('Term display name', { exact: false }).fill('Fall mini-session');
    await page.getByLabel('Term category', { exact: false }).selectOption('non_standard');
    await page.getByLabel('First day', { exact: false }).fill('2026-10-01');
    await page.locator('#drawer-body [name="ends_on"]').fill('2026-10-31');
    await page.getByLabel('Last day to add or drop', { exact: false }).fill('2026-10-03');
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.getByRole('button', { name: 'Save academic term', exact: true }).click();
    expect(f.calls.find(call => call.path === `/sis/calendar/terms/${termId}/save/`)).toMatchObject({ data: { academic_year_id: yearId, name: 'Autumn 2026', revision: 'b'.repeat(64), add_drop_ends_on: '2026-08-30' }, csrf: 'fictional-test-token' });
    expect(f.calls.find(call => call.path === '/sis/calendar/terms/create/')).toMatchObject({ data: { academic_year_id: yearId, code: '26/MINI', kind: 'non_standard', operation_id: operation } }); expect(f.errors).toEqual([]);
});

test('student cohort shows retained curriculum and escaped history without a manual year assignment', async ({ page }) => {
    const f = await fixture(page); await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator('#directory-search').fill('a');
    await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Sadie Example', exact: true }).click();
    await page.getByRole('button', { name: 'Matriculation cohort', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Academic year 2024-2025; retained curriculum version 1');
    await expect(page.locator('#drawer-body')).toContainText('Fictional <script>historical</script> note');
    await expect(page.locator('#drawer-body script')).toHaveCount(0);
    await expect(page.locator('#drawer-body form')).toHaveCount(0);
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    expect(f.calls.some(call => call.path.endsWith('/catalog/change/'))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0); expect(f.errors).toEqual([]);
});

test('revoked calendar access clears the active review and keeps a denied confirmation from displaying private data', async ({ page }) => {
    const f = await fixture(page); await review(page);
    await page.route('**/sis/academic-years/create/', route => route.fulfill({ status: 403, json: { error: 'Access denied.' } }));
    await page.getByRole('button', { name: 'Confirm academic year and terms', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('no longer available with your current access');
    await expect(page.locator('#drawer-body')).not.toContainText('Fall 2026');
    await expect(page.getByRole('button', { name: 'Confirm academic year and terms', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('a term-code collision stays in one structured drawer alert and staff can review the template', async ({ page }) => {
    const f = await fixture(page);
    const message = 'Term type FA generates code 26/FA for academic year 2027-2028. That code already belongs to academic year 2026-2027. Review the Term code template in Term types.';
    await page.route('**/sis/academic-years/review/', route => route.fulfill({ status: 422, json: { error: 'Please correct the highlighted fields.', errors: [{ field: 'terms', message }] } }));
    await page.getByRole('button', { name: 'Academic years', exact: true }).click();
    await page.getByRole('button', { name: 'Create academic year', exact: true }).click();
    await page.getByLabel('Start year', { exact: false }).fill('2027');
    await page.getByRole('button', { name: 'Review generated terms', exact: true }).click();
    await expect(page.locator('#drawer-error')).toBeFocused();
    await expect(page.locator('#drawer-error strong')).toHaveText('Please review the following details.');
    await expect(page.locator('#drawer-error li')).toHaveText(message);
    await expect(page.locator('#workspace-alert')).toBeHidden();
    await expect(page.locator('#workspace-alert')).toBeEmpty();
    await expect(page.getByLabel('Start year', { exact: false })).toHaveValue('2027');
    await expect(page.getByLabel('Start year', { exact: false })).not.toHaveAttribute('aria-invalid');
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    expect(f.calls.some(call => call.path === '/sis/academic-years/create/')).toBe(false);
    await page.getByRole('button', { name: 'Review term types', exact: true }).click();
    await expect(page.locator('#drawer-error')).toHaveCount(0);
    await page.getByRole('button', { name: 'Edit FA', exact: true }).click();
    await page.getByLabel('Term code template', { exact: false }).fill('26/FA');
    await expect(page.locator('#drawer-body')).toContainText('This code stays the same every year');
    await page.getByLabel('Term code template', { exact: false }).fill('{{START_YEAR_SHORT}}/FA');
    await expect(page.locator('#drawer-body')).toContainText('Example for 2027–2028: 27/FA. For 2028–2029: 28/FA.');
    await expect(page.locator('#drawer-body')).not.toContainText('This code stays the same every year');
    expect(f.errors).toEqual([]);
});

test('drawer errors highlight the active input and directory errors still appear after the drawer closes', async ({ page }) => {
    const f = await fixture(page);
    await page.route('**/sis/academic-years/review/', route => route.fulfill({ status: 422, json: { error: 'Please correct the highlighted fields.', errors: [{ field: 'start_year', message: 'Fictional start-year validation.' }] } }));
    await review(page);
    await expect(page.getByLabel('Start year', { exact: false })).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#drawer-error li')).toHaveText('Start year: Fictional start-year validation.');
    await expect(page.locator('#workspace-alert')).toBeHidden();
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await expect(page.locator('#drawer-error')).toHaveCount(0);
    await page.route('**/sis/ui/data/courses/', route => route.fulfill({ status: 500, json: { error: 'Fictional directory error.' } }));
    await page.getByLabel('Search this workspace', { exact: true }).fill('Example');
    await page.getByLabel('Search this workspace', { exact: true }).press('Enter');
    await expect(page.locator('#workspace-alert')).toBeVisible();
    await expect(page.locator('#workspace-alert')).toBeFocused();
    await expect(page.locator('#workspace-alert')).toHaveText('Fictional directory error.');
    expect(f.errors).toEqual([]);
});

test('Spring starting point shows explicit 2028/2029 choices and previews the following calendar year without saving', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Academic years', exact: true }).click();
    await page.getByRole('button', { name: 'Create academic year', exact: true }).click();
    await page.getByLabel('Start year', { exact: false }).fill('2028');
    await page.getByRole('button', { name: 'Review term types', exact: true }).click();
    await page.getByRole('button', { name: 'Add term type', exact: true }).click();
    await expect(page.getByLabel('Example academic year starts in', { exact: true })).toHaveValue('2028');
    await page.getByRole('button', { name: 'Use Spring starting point', exact: true }).click();
    for (const label of ['Term starts: year', 'Term ends: year', 'Drop/add deadline: year']) {
        await expect(page.getByLabel(label, { exact: false })).toHaveValue('END_YEAR');
        await expect(page.getByLabel(label, { exact: false }).locator('option:checked')).toHaveText('Second year — 2029 (Spring / Summer example)');
    }
    await expect(page.getByLabel('Term code template', { exact: false })).toHaveValue('{{END_YEAR_SHORT}}/SP');
    await page.getByRole('button', { name: 'Preview generated term', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Academic year 2028-2029: Spring 2029 (29/SP).');
    await expect(page.getByRole('region', { name: 'Example generated term dates', exact: true })).toContainText('2029-01-04');
    expect(f.calls.some(call => call.path.endsWith('/save/'))).toBe(false);
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.getByLabel('Term starts: month and day (MM-DD)', { exact: false }).fill('01-05');
    await expect(page.locator('#drawer-body')).not.toContainText('Academic year 2028-2029: Spring 2029 (29/SP).');
    await page.getByRole('button', { name: 'Preview generated term', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Example generated term dates', exact: true })).toContainText('2029-01-05');
    expect(f.calls.find(call => call.path.endsWith('/preview/'))).toMatchObject({ csrf: 'fictional-test-token', data: { start_year: 2028, starts_on_year: 'END_YEAR', code_pattern: '{{END_YEAR_SHORT}}/SP' } });
    expect(f.errors).toEqual([]);
});

test('Fall and Summer starting points keep dates and code years together and clear outdated previews', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Term types', exact: true }).click();
    await page.getByRole('button', { name: 'Add term type', exact: true }).click();
    await page.getByLabel('Example academic year starts in', { exact: true }).fill('2028');
    await page.getByRole('button', { name: 'Use Fall starting point', exact: true }).click();
    await page.getByRole('button', { name: 'Preview generated term', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Academic year 2028-2029: Fall 2028 (28/FA).');
    await expect(page.getByRole('region', { name: 'Example generated term dates', exact: true })).toContainText('2028-12-18');
    await page.locator('#drawer-body summary').filter({ hasText: 'Optional January and summer sessions' }).click();
    await page.getByRole('button', { name: 'Use Summer starting point', exact: true }).click();
    await expect(page.locator('#drawer-body')).not.toContainText('Academic year 2028-2029: Fall 2028 (28/FA).');
    await page.getByRole('button', { name: 'Preview generated term', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Academic year 2028-2029: Summer 2029 (29/SU).');
    await expect(page.getByRole('region', { name: 'Example generated term dates', exact: true })).toContainText('2029-08-30');
    await page.getByLabel('Example academic year starts in', { exact: true }).fill('2029');
    await expect(page.getByLabel('Term ends: year', { exact: false }).locator('option:checked')).toHaveText('Second year — 2030 (Spring / Summer example)');
    await expect(page.locator('#drawer-body')).not.toContainText('Academic year 2028-2029: Summer 2029 (29/SU).');
    expect(f.calls.some(call => call.path.endsWith('/save/'))).toBe(false); expect(f.errors).toEqual([]);
});

test('optional J-Term and summer choices remain simple and use editable second-year dates', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Term types', exact: true }).click();
    await page.getByRole('button', { name: 'Add term type', exact: true }).click();
    await page.getByLabel('Example academic year starts in', { exact: true }).fill('2028');
    await expect(page.getByRole('button', { name: 'Use J-Term starting point', exact: true })).toBeHidden();
    await page.locator('#drawer-body summary').filter({ hasText: 'Optional January and summer sessions' }).click();
    for (const [label, code, starts, ends] of [
        ['J-Term', '29/J', '2029-01-04', '2029-01-31'],
        ['February Spring', '29/SP', '2029-02-01', '2029-05-31'],
        ['Summer 1', '29/S1', '2029-06-01', '2029-07-15'],
        ['Summer 2', '29/S2', '2029-07-16', '2029-08-30'],
    ]) {
        await page.getByRole('button', { name: `Use ${label} starting point`, exact: true }).click();
        await page.getByRole('button', { name: 'Preview generated term', exact: true }).click();
        await expect(page.locator('#drawer-body')).toContainText(`(${code}).`);
        const dates = page.getByRole('region', { name: 'Example generated term dates', exact: true });
        await expect(dates).toContainText(starts); await expect(dates).toContainText(ends);
    }
    await page.getByLabel('Term starts: month and day (MM-DD)', { exact: false }).fill('07-20');
    await page.getByRole('button', { name: 'Preview generated term', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Example generated term dates', exact: true })).toContainText('2029-07-20');
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    expect(f.calls.some(call => call.path.endsWith('/save/'))).toBe(false); expect(f.errors).toEqual([]);
});
