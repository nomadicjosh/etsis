const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = number => `01K000000000000000000000${String(number).padStart(2, '0')}`;

async function fixture(page, options = {}) {
    const calls = [], errors = [], publications = [];
    let definition = { code: 'BSCS', title: 'Computer Science', description: options.description || null, classifications: { department: null, academic_level: null, award: null } };
    const rows = [
        { id: id(3), kind: 'department', code: 'SCI', title: 'Sciences', active: true },
        { id: id(4), kind: 'academic_level', code: 'UG', title: 'Undergraduate', active: true },
        { id: id(5), kind: 'award', code: 'BA', title: 'Bachelor of Arts <script>fictional</script>', active: true },
    ];
    let reviews = 0, accepted = null;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/programs/') result = { items: [{ id: id(1), code: definition.code, title: definition.title }], next_after: null };
        else if (path === `/sis/programs/${id(1)}/details/`) {
            if (data) definition = { code: data.code, title: data.title, description: data.description, classifications: Object.fromEntries(['department', 'academic_level', 'award'].map(kind => {
                const row = rows.find(row => row.id === data[`${kind}_id`]);
                return [kind, row ? { id: row.id, code: row.code, title: row.title } : null];
            })) };
            result = { id: id(1), definition, revision: 'a'.repeat(64) };
        } else if (path === `/sis/programs/${id(1)}/assistance/`) {
            if (data && options.assistanceFailure) {
                await route.fulfill({ status: 503, json: { error: 'The assistance provider rejected authentication. Ask an administrator to check the AI credential and authentication setting. Your draft is unchanged.' } }); return;
            }
            if (data && options.editWhileRunning) await page.getByRole('textbox', { name: 'Program description', exact: true }).fill('My later edit');
            result = data ? { task: data.task, advice: { summary: '<script>window.programAiExecuted = true</script> Review this suggestion.', suggestions: ['Verify the description.'], description: 'A fictional program <img src=x onerror=alert(1)>.' } } : {
                enabled: !options.assistanceDisabled, label: 'Fictional provider', tasks: options.tasks || ['description', ...(definition.description ? ['rewrite', 'expand'] : [])], revision: 'b'.repeat(64), saved_revision: 'a'.repeat(64),
                draft: { kind: 'academic_program', code: definition.code, title: definition.title, description: definition.description, classifications: Object.fromEntries(Object.entries(definition.classifications).map(([kind, row]) => [kind, row ? { code: row.code, title: row.title } : null])) },
            };
        } else if (path.startsWith('/sis/catalog/classifications/')) {
            result = { items: rows.filter(row => row.kind === path.split('/')[4] && `${row.code} ${row.title}`.toLowerCase().includes(data.search.toLowerCase())), next_after: null };
        } else if (path === '/sis/academic-years/') result = { items: [{ id: id(2), code: '2024-2025' }].filter(year => year.code.includes(data.search)), next_after: null };
        else if (path.endsWith('/publication/review/')) {
            accepted = { academic_year_id: data.academic_year_id, academic_year_code: '2024-2025', catalog_revision: publications.length + 1,
                definition: JSON.parse(JSON.stringify(definition)), reason: data.reason, review_token: `fictional-program-proof-${++reviews}` };
            result = accepted;
        } else if (path.endsWith('/publication/commit/')) {
            publications.push({ ...accepted, version_id: id(6), version: publications.length + 1, effective_at: '2026-11-10T12:00:00Z' });
            result = { operation_id: id(6) };
        } else if (path.endsWith('/publication/page/')) result = { items: publications, next_cursor: null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole('link', { name: 'Programs', exact: true }).click();
    await page.getByRole('button', { name: 'Computer Science', exact: true }).click();
    await page.getByRole('button', { name: 'Program details & publications', exact: true }).click();
    return { calls, errors };
}

async function accessible(page) {
    await page.setViewportSize({ width: 375, height: 812 });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}

test('program details and classifications are edited together and explicitly reviewed for an academic year', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('textbox', { name: 'Program title', exact: true }).fill('Fictional Science');
    await page.getByRole('textbox', { name: 'Program description', exact: true }).fill('A fictional description <img src=x onerror=alert(1)>');
    for (const [category, code] of [['department', 'SCI'], ['academic level', 'UG'], ['award', 'BA']]) {
        await page.getByRole('button', { name: `Choose ${category}`, exact: true }).click();
        await page.getByRole('button', { name: `Choose ${code}`, exact: true }).click();
    }
    await expect(page.getByRole('textbox', { name: 'Program title', exact: true })).toHaveValue('Fictional Science');
    await expect(page.getByRole('button', { name: 'Publish for an academic year', exact: true })).toBeDisabled();
    await accessible(page);
    await page.getByRole('button', { name: 'Save program details', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Program details saved.' })).toBeVisible();
    await page.getByRole('button', { name: 'Publish for an academic year', exact: true }).click();
    await page.getByLabel('Reason for program publication', { exact: true }).fill('Fictional reviewed 2024 requirements context');
    await page.getByRole('button', { name: 'Choose academic year', exact: true }).click();
    await page.getByRole('button', { name: 'Use academic year 2024-2025', exact: true }).click();
    await expect(page.getByLabel('Reason for program publication', { exact: true })).toHaveValue('Fictional reviewed 2024 requirements context');
    await page.getByRole('button', { name: 'Review program publication', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('2024-2025 · Catalog revision 1');
    await expect(page.locator('#drawer-body')).toContainText('BA — Bachelor of Arts <script>fictional</script>');
    await page.getByRole('button', { name: 'Revise program publication', exact: true }).click();
    await expect(page.getByLabel('Reason for program publication', { exact: true })).toHaveValue('Fictional reviewed 2024 requirements context');
    await page.getByRole('button', { name: 'Review program publication', exact: true }).click();
    await page.getByRole('button', { name: 'Refresh program review', exact: true }).click();
    await accessible(page);
    const confirm = page.getByRole('button', { name: 'Confirm program publication', exact: true });
    await confirm.focus(); await page.keyboard.press('Enter');
    await expect(page.getByRole('status').filter({ hasText: 'Catalog revision 1 published.' })).toBeVisible();
    expect(f.calls.find(call => call.path.endsWith('/publication/commit/'))).toMatchObject({ csrf: 'fictional-test-token', data: { review_token: 'fictional-program-proof-3' } });
    expect(f.calls.filter(call => call.path.endsWith('/publication/commit/'))).toHaveLength(1);
    await page.getByRole('button', { name: 'Published program details', exact: true }).click();
    await page.getByText('2024-2025 · Revision 1 · Fictional Science', { exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Sciences');
    expect(await page.locator('#drawer-body img, #drawer-body script').count()).toBe(0);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('choosers and publication history preserve unsaved program text without saving or publishing', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('textbox', { name: 'Program title', exact: true }).fill('Unsaved fictional program');
    await page.getByRole('textbox', { name: 'Program description', exact: true }).fill('A private draft in page memory.');
    await page.getByRole('button', { name: 'Choose award', exact: true }).click();
    await page.getByRole('button', { name: 'Back to program details', exact: true }).click();
    await expect(page.getByRole('textbox', { name: 'Program title', exact: true })).toHaveValue('Unsaved fictional program');
    await page.getByRole('button', { name: 'Published program details', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('No program details have been published yet.');
    await page.getByRole('button', { name: 'Back to program details', exact: true }).click();
    await expect(page.getByRole('textbox', { name: 'Program description', exact: true })).toHaveValue('A private draft in page memory.');
    expect(f.calls.filter(call => call.path.endsWith('/details/') || call.path.endsWith('/publication/commit/'))).toHaveLength(0);
    expect(f.errors).toEqual([]);
});


test('program assistance requires consent, renders plain text and needs explicit copy and save', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Optional AI help', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Optional program assistance' })).toBeFocused();
    await page.getByText('Inspect exactly what will be sent', { exact: true }).click();
    await expect(page.locator('#drawer-body pre')).toContainText('academic_program');
    await page.getByRole('button', { name: 'Suggest a program description', exact: true }).click();
    expect(f.calls.filter(call => call.path.endsWith('/assistance/'))).toHaveLength(0);
    await accessible(page);
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Suggest a program description', exact: true }).click();
    await expect(page.getByText('Suggestions received. No text has been saved or published.', { exact: true })).toBeVisible();
    await expect(page.getByRole('checkbox')).not.toBeChecked();
    await expect(page.getByRole('textbox', { name: 'Program description', exact: true })).toHaveValue('');
    expect(f.calls.filter(call => call.path.endsWith('/assistance/'))).toEqual([{ path: `/sis/programs/${id(1)}/assistance/`, csrf: 'fictional-test-token', data: { task: 'description', consent: true, revision: 'b'.repeat(64) } }]);
    expect(f.calls.filter(call => call.path.endsWith('/details/'))).toHaveLength(0);
    expect(await page.evaluate(() => window.programAiExecuted)).toBeUndefined();
    expect(await page.locator('#drawer-body script, #drawer-body img').count()).toBe(0);
    await page.getByRole('button', { name: 'Use suggested description in this editor', exact: true }).click();
    await expect(page.getByRole('textbox', { name: 'Program description', exact: true })).toHaveValue('A fictional program <img src=x onerror=alert(1)>.');
    await expect(page.getByRole('button', { name: 'Publish for an academic year', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Save program details', exact: true }).click();
    expect(f.calls.filter(call => call.path.endsWith('/details/'))).toHaveLength(1);
    expect(f.calls.filter(call => call.path.endsWith('/publication/commit/'))).toHaveLength(0);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('program assistance protects unsaved edits and offers only granted text actions', async ({ page }) => {
    const f = await fixture(page, { description: 'Saved fictional program.', tasks: ['rewrite'], editWhileRunning: true });
    await page.getByRole('button', { name: 'Optional AI help', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Suggest a program description', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Expand description', exact: true })).toHaveCount(0);
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Rewrite description', exact: true }).click();
    await page.getByRole('button', { name: 'Use suggested description in this editor', exact: true }).click();
    await expect(page.getByRole('textbox', { name: 'Program description', exact: true })).toHaveValue('My later edit');
    await expect(page.getByRole('alert').filter({ hasText: 'Your program has changed' })).toBeVisible();
    expect(f.calls.filter(call => call.path.endsWith('/details/'))).toHaveLength(0);
    expect(f.errors).toEqual([]);
});

test('disabled and failed program assistance leave editing available with fresh consent', async ({ page }) => {
    for (const options of [{ assistanceDisabled: true }, { assistanceFailure: true }]) {
        const f = await fixture(page, options);
        await page.getByRole('button', { name: 'Optional AI help', exact: true }).click();
        if (options.assistanceFailure) {
            await page.getByRole('checkbox').check();
            await page.getByRole('button', { name: 'Suggest a program description', exact: true }).click();
            await expect(page.getByRole('alert').filter({ hasText: 'rejected authentication' })).toBeVisible();
            await expect(page.getByRole('checkbox')).not.toBeChecked();
        } else await expect(page.locator('#drawer-body')).toContainText('Optional assistance is disabled');
        await page.getByRole('textbox', { name: 'Program description', exact: true }).fill('Staff can still edit.');
        await expect(page.getByRole('button', { name: 'Save program details', exact: true })).toBeEnabled();
        expect(f.calls.filter(call => call.path.endsWith('/details/'))).toHaveLength(0);
        expect(f.errors).toEqual([]);
    }
});
