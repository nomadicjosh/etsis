const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K000000000000000000000${String(n).padStart(2, '0')}`;
function copiedGraph(base, aliases) {
    const graph = structuredClone(base);
    for (const [key, copy] of Object.entries(aliases)) {
        const mapping = { [copy.relation]: key };
        for (const name of Object.keys(base.relationships)) {
            for (let current = name; base.relationships[current]; current = base.relationships[current].parent)
                if (current === copy.relation && name !== current) { mapping[name] = `${key}__${name}`; break; }
        }
        for (const [name, alias] of Object.entries(mapping)) graph.relationships[alias] = {
            ...base.relationships[name], parent: mapping[base.relationships[name].parent] || base.relationships[name].parent,
            label: name === copy.relation ? copy.label : `${copy.label} · ${base.relationships[name].label}`,
            copy_of: name, copy_group: key,
        };
        for (const [name, field] of Object.entries(base.fields))
            if (mapping[field.relation]) graph.fields[`${key}__${name}`] = {
                ...field, relation: mapping[field.relation], copy_group: key,
            };
    }
    return graph;
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
}
async function fixture(page, options = {}) {
    const calls = [], errors = []; let definition = null, version = 0, name = '', operation = 1;
    const fields = options.applications ? {
        application_id: { label: 'Application reference', type: 'string', relation: 'application' },
        status: { label: 'Application status (submitted/accepted/rejected/matriculated)', type: 'string', relation: 'application' },
        institutional_id: { label: 'Institutional ID', type: 'string', relation: 'identity' },
        full_name: { label: 'Applicant full name', type: 'string', relation: 'person' },
        program_code: { label: 'Current program code', type: 'string', relation: 'program' },
        entry_term: { label: 'Intended entry term', type: 'string', relation: 'term' },
        term_starts_on: { label: 'Intended entry term starts', type: 'date', relation: 'term' },
        submitted_on: { label: 'Submitted on (submission timezone)', type: 'date', relation: 'submission' },
        submission_channel: { label: 'Submission channel (direct/form)', type: 'string', relation: 'submission' },
    } : options.students ? {
        student_id: { label: 'Student reference', type: 'string', relation: 'student' },
        institutional_id: { label: 'Institutional ID', type: 'string', relation: 'identity' },
        full_name: { label: 'Student full name', type: 'string', relation: 'person' },
        active: { label: 'Active student', type: 'boolean', relation: 'student' },
        program_code: { label: 'Primary program code', type: 'string', relation: 'program' },
        entry_term: { label: 'Institutional entry term', type: 'string', relation: 'term' },
        latest_term_code: { label: 'Latest registered term', type: 'string', relation: 'latest_registration' },
        latest_term_starts_on: { label: 'Latest registered term starts', type: 'date', relation: 'latest_registration' },
        registration_status: { label: 'Registration status (A/N/D/W/C)', type: 'string', relation: 'registration' },
        registration_term_code: { label: 'Registration term', type: 'string', relation: 'registration_term' },
        registration_section_code: { label: 'Registered section code', type: 'string', relation: 'registered_section' },
    } : {
        section_code: { label: 'Section code', type: 'string', relation: 'section' },
        term_code: { label: 'Academic term', type: 'string', relation: 'term' },
        capacity: { label: 'Section capacity', type: 'integer', relation: 'section' },
        enrollment_id: { label: 'Enrollment reference', type: 'string', relation: 'enrollment' },
        enrollment_status: { label: 'Enrollment status', type: 'string', relation: 'enrollment' },
        course_title: { label: 'Course title', type: 'string', relation: 'course' },
    };
    const dataset = options.applications ? {
        id: 'applications', label: 'Applications & admissions', description: 'One row per application.', fields,
        relationships: {
            person: { label: 'Applicant', parent: 'application', many: false },
            identity: { label: 'Institutional IDs', parent: 'person', many: false },
            program: { label: 'Academic programs', parent: 'application', many: false },
            term: { label: 'Academic terms', parent: 'application', many: false },
            submission: { label: 'Recorded submissions', parent: 'application', many: false },
        },
    } : options.students ? {
        id: 'students', label: 'Students & current primary program',
        description: 'One row per student, with the current primary program and institutional entry term.', fields,
        relationships: {
            person: { label: 'Student names', parent: 'student', many: false },
            identity: { label: 'Institutional IDs', parent: 'person', many: false },
            program: { label: 'Academic programs', parent: 'student', many: false },
            term: { label: 'Academic terms', parent: 'student', many: false },
            latest_registration: { label: 'Latest registered term (all pursuits)', parent: 'student', many: false },
            registration: { label: 'Registrations (all pursuits)', parent: 'student', many: true },
            registration_term: { label: 'Registration terms', parent: 'registration', many: false },
            registered_section: { label: 'Registered sections', parent: 'registration', many: false },
        },
    } : { id: 'sections', label: 'Sections, teaching & enrollment counts', description: 'Explore sections and their registration relationships.', fields,
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
        else if (path === '/sis/report-studio/graph/') result = { dataset: copiedGraph(dataset, data.aliases) };
        else if (path === '/sis/report-studio/save/') { definition = data.definition; name = data.name; version = data.version + 1; result = { id: data.report_id }; }
        else if (path.endsWith('/preview/') || path.endsWith('/run/')) {
            const query = data.definition || definition;
            const values = { institutional_id: 'S-FICTIONAL', full_name: 'Alex Example', program_code: 'BS.CS',
                entry_term: '26/FA', latest_term_code: options.never ? null : '27/SP',
                latest_term_starts_on: options.never ? null : '2027-01-04', status: 'accepted', term_starts_on: '2027-01-04',
                submitted_on: options.unknownSubmission ? null : '2026-08-10', submission_channel: 'form' };
            const rows = options.students || options.applications ? [Object.fromEntries(query.columns.map((column, i) => [`c${i}`, values[column.field] ?? null]))]
                : data.offset ? [{ c0: 'CHEM.101.A', c1: '26/FA', c2: 5 }] : [{ c0: 'MATH.101.A', c1: '26/FA', c2: 8 }, { c0: 'ENG.101.A', c1: '26/FA', c2: 0 }];
            result = { columns: query.columns.map(column => column.label), items: rows, offset: data.offset,
                next_offset: options.students || options.applications || data.offset ? null : 50, limit: 50,
                grouped: !options.students && !options.applications };
        } else if (path.endsWith('/export/')) { await route.fulfill({ body: 'Section,Term,Students\nMATH.101.A,26/FA,8\n', contentType: 'text/csv' }); return; }
        else if (/\/sis\/report-studio\/[^/]+\/$/.test(path)) result = { id: path.split('/').at(-2), name, version, definition, can_manage: !options.recipient };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(options.recipient ? '/admin/report-fixture/' : '/admin/');
    await page.locator(`.workspace-nav-link[data-workspace="${options.recipient ? 'sharedreports' : 'reports'}"]`).click();
    await page.getByRole('button', { name: options.recipient ? name : 'Open Report Studio', exact: true }).click();
    return { calls, errors, visited };
}

test('the Students source opens with useful columns and supports preview, save and reopening', async ({ page }) => {
    const f = await fixture(page, { students: true });
    await page.getByRole('button', { name: 'Explore Students & current primary program', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await expect(studio.locator('.report-local-error')).toBeHidden();
    await expect(studio.getByLabel('Column 1 label', { exact: true })).toHaveValue('Institutional ID');
    await expect(studio.getByLabel('Column 2 label', { exact: true })).toHaveValue('Student full name');
    await expect(studio.getByLabel('Column 3 label', { exact: true })).toHaveValue('Primary program code');
    await expect(studio.getByLabel('Column 4 label', { exact: true })).toHaveValue('Institutional entry term');
    await studio.getByLabel('Report name', { exact: true }).fill('Fictional current students');
    await studio.getByRole('button', { name: 'Preview report', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    expect(f.calls.find(call => call.path.endsWith('/preview/')).data.definition).toMatchObject({
        dataset: 'students', columns: [
            { field: 'institutional_id', aggregate: 'none' },
            { field: 'full_name', aggregate: 'none' },
            { field: 'program_code', aggregate: 'none' },
            { field: 'entry_term', aggregate: 'none' },
        ],
    });
    await studio.getByRole('button', { name: 'Save report definition', exact: true }).click();
    await expect(studio).toContainText('Definition revision 1');
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    await studio.getByRole('button', { name: 'Edit report in Studio', exact: true }).click();
    await expect(studio.getByLabel('Report name', { exact: true })).toHaveValue('Fictional current students');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(f.errors).toEqual([]);
});

for (const never of [false, true]) {
    test(`the latest-registration starting point supports ${never ? 'students with no retained registration' : 'latest terms'} without SQL`, async ({ page }) => {
        const f = await fixture(page, { students: true, never });
        await page.getByRole('button', { name: never ? 'Students with no retained registration'
            : 'Students and their latest registered term', exact: true }).click();
        const studio = page.locator('dialog.report-studio');
        await expect(studio.getByLabel('Column 4 label', { exact: true })).toHaveValue('Latest registered term');
        await studio.getByRole('tab', { name: 'Conditions', exact: true }).click();
        await expect(studio.getByLabel('Row conditions: field', { exact: true })
            .locator('option[value="latest_term_code"]')).toHaveText('Latest registered term');
        await studio.getByRole('button', { name: 'Preview report', exact: true }).click();
        await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
        const definition = f.calls.find(call => call.path.endsWith('/preview/')).data.definition;
        expect(definition.joins).toMatchObject({ latest_registration: 'left' });
        if (never) expect(definition.filters.rules[0]).toMatchObject({ id: 'latest_term_code', operator: 'is_null' });
        else {
            expect(definition.filters).toBeNull();
            await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('27/SP');
        }
        await page.setViewportSize({ width: 375, height: 812 });
        await accessible(page);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        expect(f.errors).toEqual([]);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    });
}

test('date conditions use native date inputs and submit typed ranges and date lists', async ({ page }) => {
    const f = await fixture(page, { students: true });
    await page.getByRole('button', { name: 'Students and their latest registered term', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await studio.getByLabel('Report name', { exact: true }).fill('Fictional latest-term dates');
    await studio.getByRole('tab', { name: 'Conditions', exact: true }).click();
    const conditions = studio.getByRole('group', { name: 'Row conditions', exact: true });
    await conditions.getByLabel('Row conditions: field', { exact: true }).selectOption('latest_term_starts_on');
    await conditions.getByLabel('Row conditions: comparison', { exact: true }).selectOption('between');
    const values = conditions.getByLabel('Row conditions: value', { exact: true });
    await expect(values).toHaveCount(2);
    await expect(values.first()).toHaveAttribute('type', 'date');
    await values.first().fill('2027-01-01');
    await values.nth(1).fill('2027-06-30');
    await studio.getByRole('button', { name: 'Preview report', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    expect(f.calls.find(call => call.path.endsWith('/preview/')).data.definition.filters.rules[0])
        .toMatchObject({ id: 'latest_term_starts_on', operator: 'between', value: ['2027-01-01', '2027-06-30'] });
    await studio.getByRole('tab', { name: 'Conditions', exact: true }).click();
    await conditions.getByLabel('Row conditions: comparison', { exact: true }).selectOption('in');
    await expect(values).toHaveCount(1);
    await expect(values).toHaveAttribute('type', 'text');
    await values.fill('2027-01-01,2028-01-01');
    await studio.getByRole('button', { name: 'Preview report', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    expect(f.calls.filter(call => call.path.endsWith('/preview/')).at(-1).data.definition.filters.rules[0])
        .toMatchObject({ id: 'latest_term_starts_on', operator: 'in', value: ['2027-01-01', '2028-01-01'] });
    await studio.getByRole('button', { name: 'Save report definition', exact: true }).click();
    await expect(studio).toContainText('Definition revision 1');
    await studio.getByRole('button', { name: 'Edit report in Studio', exact: true }).click();
    await studio.getByRole('tab', { name: 'Conditions', exact: true }).click();
    await expect(conditions.getByLabel('Row conditions: value', { exact: true }))
        .toHaveValue('2027-01-01,2028-01-01');
    await expect(studio.locator('.report-local-error')).toBeHidden();
    expect(f.errors).toEqual([]);
});

test('related-record cards preserve same-record conditions, modes and editable saves without adding output joins', async ({ page }) => {
    const f = await fixture(page, { students: true });
    await page.getByRole('button', { name: 'Students with active registrations', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await studio.getByLabel('Report name', { exact: true }).fill('Fictional matching students');
    await studio.getByRole('tab', { name: 'Conditions', exact: true }).click();
    const panel = studio.locator('[data-report-match="registration"]');
    await expect(panel.getByRole('button', { name: 'Has matching records', exact: true })).toHaveAttribute('aria-pressed', 'true');
    const matching = panel.getByRole('group', { name: 'Matching registrations (all pursuits)', exact: true });
    await matching.getByRole('button', { name: 'Add condition', exact: true }).click();
    await matching.getByLabel('Matching registrations (all pursuits): field', { exact: true }).nth(1)
        .selectOption('registration_term_code');
    await matching.getByLabel('Matching registrations (all pursuits): value', { exact: true }).nth(1).fill('26/FA');
    await studio.getByRole('button', { name: 'Preview report', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    const preview = f.calls.find(c => c.path.endsWith('/preview/')).data.definition;
    expect(preview.existence.registration).toMatchObject({ mode: 'any', filters: { condition: 'AND', rules: [
        { id: 'registration_status', operator: 'in', value: ['A', 'N'] },
        { id: 'registration_term_code', operator: 'equal', value: '26/FA' },
    ] } });
    expect(preview.joins).not.toHaveProperty('registration');
    expect(preview.joins).not.toHaveProperty('registration_term');
    await studio.getByRole('tab', { name: 'Conditions', exact: true }).click();
    await panel.getByRole('button', { name: 'Has no matching records', exact: true }).click();
    await expect(panel.getByRole('button', { name: 'Has no matching records', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(matching.getByLabel('Matching registrations (all pursuits): value', { exact: true }).nth(1)).toHaveValue('26/FA');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await studio.getByRole('button', { name: 'Save report definition', exact: true }).click();
    await expect(studio).toContainText('Definition revision 1');
    await studio.getByRole('button', { name: 'Edit report in Studio', exact: true }).click();
    await studio.getByRole('tab', { name: 'Conditions', exact: true }).click();
    await expect(panel.getByRole('button', { name: 'Has no matching records', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await studio.getByLabel('Related record source', { exact: true }).selectOption('latest_registration');
    await studio.getByRole('button', { name: 'Add related-record condition', exact: true }).click();
    const added = studio.locator('[data-report-match="latest_registration"]');
    await expect(added.getByRole('heading', { name: 'Latest registered term (all pursuits)', exact: true })).toBeFocused();
    await added.getByRole('button', { name: 'Remove Latest registered term (all pursuits) condition', exact: true }).click();
    await expect(added).toHaveCount(0);
    await expect(studio.getByLabel('Related record source', { exact: true })).toBeFocused();
    await panel.getByRole('button', { name: 'Remove Registrations (all pursuits) condition', exact: true }).click();
    await expect(panel).toHaveCount(0);
    await studio.getByRole('button', { name: 'Preview report', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    expect(f.calls.filter(c => c.path.endsWith('/preview/')).at(-1).data.definition.existence).toEqual({});
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('staff add named join copies, compare independent terms, save and reopen them without SQL', async ({ page }) => {
    const f = await fixture(page, { students: true });
    await page.getByRole('button', { name: 'Explore Students & current primary program', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await studio.getByLabel('Report name', { exact: true }).fill('Fictional Fall and Spring comparison');
    await studio.getByRole('button', { name: 'Add another Registrations (all pursuits)', exact: true }).click();
    const copy = studio.locator('[data-relation="copy1"]');
    await expect(copy.getByLabel('Name this record copy', { exact: true })).toBeFocused();
    await copy.getByLabel('Name this record copy', { exact: true }).fill('Spring registrations');
    await studio.getByRole('tab', { name: 'Conditions', exact: true }).click();
    for (const [source, label, field, value] of [
        ['registration', 'registrations (all pursuits)', 'registration_term_code', '26/FA'],
        ['copy1', 'spring registrations', 'copy1__registration_term_code', '27/SP'],
    ]) {
        await studio.getByLabel('Related record source', { exact: true }).selectOption(source);
        await studio.getByRole('button', { name: 'Add related-record condition', exact: true }).click();
        const panel = studio.locator(`[data-report-match="${source}"]`);
        await panel.getByLabel(`Matching ${label}: field`, { exact: true }).selectOption(field);
        await panel.getByLabel(`Matching ${label}: value`, { exact: true }).fill(value);
    }
    await studio.getByRole('button', { name: 'Preview report', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    const query = f.calls.find(c => c.path.endsWith('/preview/')).data.definition;
    expect(query.aliases).toEqual({ copy1: { relation: 'registration', label: 'Spring registrations' } });
    expect(query.existence.copy1.filters.rules[0]).toMatchObject({ id: 'copy1__registration_term_code', value: '27/SP' });
    expect(query.joins).not.toHaveProperty('copy1');
    await studio.getByRole('button', { name: 'Save report definition', exact: true }).click();
    await expect(studio).toContainText('Definition revision 1');
    await studio.getByRole('button', { name: 'Edit report in Studio', exact: true }).click();
    await expect(copy.getByLabel('Name this record copy', { exact: true })).toHaveValue('Spring registrations');
    await studio.getByRole('tab', { name: 'Conditions', exact: true }).click();
    await expect(studio.locator('[data-report-match="copy1"]').getByLabel('Matching spring registrations: value', { exact: true }))
        .toHaveValue('27/SP');
    await studio.getByRole('tab', { name: 'Build', exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await copy.getByRole('button', { name: 'Remove Spring registrations and its fields', exact: true }).click();
    await expect(copy).toHaveCount(0);
    await studio.getByRole('button', { name: 'Preview report', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    const removed = f.calls.filter(c => c.path.endsWith('/preview/')).at(-1).data.definition;
    expect(removed.aliases).toEqual({});
    expect(removed.existence).not.toHaveProperty('copy1');
    expect(f.calls.filter(c => c.path.endsWith('/graph/')).every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(f.errors).toEqual([]);
});

test('copied fields become ordinary output columns and removing the copy removes those references', async ({ page }) => {
    const f = await fixture(page, { students: true });
    await page.getByRole('button', { name: 'Explore Students & current primary program', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('button', { name: 'Add another Registrations (all pursuits)', exact: true }).click();
    const copy = studio.locator('[data-relation="copy1"]');
    await copy.getByRole('checkbox', { name: 'Registration status (A/N/D/W/C)', exact: true }).check();
    await studio.getByRole('button', { name: 'Preview report', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    expect(f.calls.find(c => c.path.endsWith('/preview/')).data.definition.columns.at(-1).field)
        .toBe('copy1__registration_status');
    await studio.getByRole('tab', { name: 'Build', exact: true }).click();
    await copy.getByRole('button', { name: 'Remove Registrations (all pursuits) 2 and its fields', exact: true }).click();
    await expect(copy).toHaveCount(0);
    await expect(studio.getByLabel('Column 5 label', { exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('admissions opens in the same field and condition builder and saves a configurable outcome report', async ({ page }) => {
    const f = await fixture(page, { applications: true });
    await page.getByRole('button', { name: 'Explore Applications & admissions', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await expect(studio.getByLabel('Column 2 label', { exact: true })).toHaveValue('Applicant full name');
    await studio.getByLabel('Report name', { exact: true }).fill('Fictional accepted applications');
    await studio.getByRole('tab', { name: 'Conditions', exact: true }).click();
    const rows = studio.getByRole('group', { name: 'Row conditions', exact: true });
    await rows.getByLabel('Row conditions: field', { exact: true }).selectOption('status');
    await rows.getByLabel('Row conditions: value', { exact: true }).fill('accepted');
    await studio.getByRole('button', { name: 'Preview report', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('accepted');
    expect(f.calls.find(c => c.path.endsWith('/preview/')).data.definition).toMatchObject({
        dataset: 'applications', filters: { rules: [{ id: 'status', operator: 'equal', value: 'accepted' }] },
    });
    await studio.getByRole('button', { name: 'Save report definition', exact: true }).click();
    await expect(studio).toContainText('Definition revision 1');
    expect(f.errors).toEqual([]);
});

test('submission dates use native date conditions and survive save and reopening without exposing history', async ({ page }) => {
    const f = await fixture(page, { applications: true });
    await page.getByRole('button', { name: 'Applications with recorded submission dates', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await expect(studio.getByLabel('Column 6 label', { exact: true })).toHaveValue('Submitted on (submission timezone)');
    await studio.getByLabel('Report name', { exact: true }).fill('Fictional August intake');
    await studio.getByRole('tab', { name: 'Conditions', exact: true }).click();
    const rows = studio.getByRole('group', { name: 'Row conditions', exact: true });
    await rows.getByLabel('Row conditions: comparison', { exact: true }).selectOption('between');
    const dates = rows.locator('input[type="date"]');
    await expect(dates).toHaveCount(2);
    await dates.nth(0).fill('2026-08-01');
    await dates.nth(1).fill('2026-08-31');
    await studio.getByRole('button', { name: 'Preview report', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('2026-08-10');
    const query = f.calls.find(call => call.path.endsWith('/preview/')).data.definition;
    expect(query.filters.rules[0]).toMatchObject({ id: 'submitted_on', operator: 'between', value: ['2026-08-01', '2026-08-31'] });
    await studio.getByRole('button', { name: 'Save report definition', exact: true }).click();
    await expect(studio).toContainText('Definition revision 1');
    await studio.getByRole('button', { name: 'Edit report in Studio', exact: true }).click();
    await studio.getByRole('tab', { name: 'Conditions', exact: true }).click();
    await expect(studio.locator('input[type="date"]').nth(0)).toHaveValue('2026-08-01');
    await expect(studio.locator('input[type="date"]').nth(1)).toHaveValue('2026-08-31');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(f.errors).toEqual([]);
    expect(JSON.stringify(query)).not.toMatch(/sis_history|source_event_id|sealed_event/);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('unknown submission dates are an explicit missing-date choice, separate from intended entry terms', async ({ page }) => {
    const f = await fixture(page, { applications: true, unknownSubmission: true });
    await expect(page.locator('dialog.report-studio')).toContainText('Earlier unknown dates stay blank');
    await page.getByRole('button', { name: 'Applications with unknown submission dates', exact: true }).click();
    await page.getByRole('button', { name: 'Preview report', exact: true }).click();
    const query = f.calls.find(call => call.path.endsWith('/preview/')).data.definition;
    expect(query.filters.rules[0]).toMatchObject({ id: 'submitted_on', operator: 'is_null' });
    expect(query.columns.map(c => c.field)).toContain('entry_term');
    const results = page.getByRole('region', { name: 'Relational report results', exact: true });
    await expect(results).toContainText('26/FA');
    await expect(results).not.toContainText('2026-08-10');
    expect(f.errors).toEqual([]);
});

test('sections with no active registrations use an absence condition rather than grouped output', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Sections with no active registrations', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('tab', { name: 'Conditions', exact: true }).click();
    const panel = studio.locator('[data-report-match="enrollment"]');
    await expect(panel.getByRole('button', { name: 'Has no matching records', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await studio.getByRole('button', { name: 'Preview report', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('MATH.101.A');
    const definition = f.calls.find(c => c.path.endsWith('/preview/')).data.definition;
    expect(definition.existence.enrollment).toMatchObject({ mode: 'none', filters: { rules: [
        { id: 'enrollment_status', operator: 'in', value: ['A', 'N'] },
    ] } });
    expect(definition.columns.every(column => column.aggregate === 'none')).toBe(true);
    expect(definition.joins).not.toHaveProperty('enrollment');
    expect(f.errors).toEqual([]);
});

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
