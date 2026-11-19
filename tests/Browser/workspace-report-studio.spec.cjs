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
        for (const [name, alias] of Object.entries(mapping)) if (base.relationships[name].date_fields)
            graph.relationships[alias].date_fields = Object.fromEntries(Object.entries(base.relationships[name].date_fields)
                .map(([field, label]) => [`${key}__${field}`, label]));
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
    const fields = options.postings ? {
        institutional_id: { label: 'Institutional ID', type: 'string', relation: 'identity' },
        term_code: { label: 'Billing term', type: 'string', relation: 'term' },
        currency: { label: 'Currency', type: 'string', relation: 'account' },
    } : options.scheduling ? {
        room_code: { label: 'Room code', type: 'string', relation: 'room' },
        room_name: { label: 'Room name', type: 'string', relation: 'room' },
        building_name: { label: 'Building name', type: 'string', relation: 'building' },
        room_capacity: { label: 'Room capacity', type: 'integer', relation: 'room' },
        booking_title: { label: 'Booking title', type: 'string', relation: 'booking' },
        booking_status: { label: 'Booking status', type: 'string', relation: 'booking',
            choices: { approved: 'Approved', requested: 'Requested', cancelled: 'Cancelled' } },
        starts_at: { label: 'Meeting starts UTC', type: 'datetime', relation: 'meeting' },
        ends_at: { label: 'Meeting ends UTC', type: 'datetime', relation: 'meeting' },
        cancelled_at: { label: 'Meeting cancelled UTC', type: 'datetime', relation: 'meeting' },
    } : options.accounts ? {
        account_id: { label: 'Account reference', type: 'string', relation: 'account' },
        institutional_id: { label: 'Institutional ID', type: 'string', relation: 'identity' },
        full_name: { label: 'Student full name', type: 'string', relation: 'person' },
        term_code: { label: 'Billing term', type: 'string', relation: 'term' },
        currency: { label: 'Currency', type: 'string', relation: 'account' },
        balance: { label: 'Account balance', type: 'double', relation: 'balance' },
        charges: { label: 'Charges', type: 'double', relation: 'balance' },
        payments: { label: 'Payments', type: 'double', relation: 'balance' },
        refunds: { label: 'Refunds', type: 'double', relation: 'balance' },
    } : options.applications ? {
        application_id: { label: 'Application reference', type: 'string', relation: 'application' },
        status: { label: 'Application status', type: 'string', relation: 'application',
            choices: { submitted: 'Submitted', accepted: 'Accepted', rejected: 'Rejected', matriculated: 'Matriculated' } },
        institutional_id: { label: 'Institutional ID', type: 'string', relation: 'identity' },
        full_name: { label: 'Applicant full name', type: 'string', relation: 'person' },
        program_code: { label: 'Current program code', type: 'string', relation: 'program' },
        entry_term: { label: 'Intended entry term', type: 'string', relation: 'term' },
        term_starts_on: { label: 'Intended entry term starts', type: 'date', relation: 'term' },
        submitted_on: { label: 'Submitted on', type: 'date', relation: 'submission' },
        submission_channel: { label: 'Submission channel', type: 'string', relation: 'submission',
            choices: { direct: 'Direct staff entry', form: 'Application form' } },
    } : options.students ? {
        student_id: { label: 'Student reference', type: 'string', relation: 'student' },
        institutional_id: { label: 'Institutional ID', type: 'string', relation: 'identity' },
        full_name: { label: 'Student full name', type: 'string', relation: 'person' },
        active: { label: 'Active student', type: 'boolean', relation: 'student' },
        program_code: { label: 'Primary program code', type: 'string', relation: 'program' },
        entry_term: { label: 'Institutional entry term', type: 'string', relation: 'term' },
        latest_term_code: { label: 'Latest registered term', type: 'string', relation: 'latest_registration' },
        latest_term_starts_on: { label: 'Latest registered term starts', type: 'date', relation: 'latest_registration' },
        registration_status: { label: 'Registration status', type: 'string', relation: 'registration',
            choices: { A: 'Registered by staff', N: 'Self-registered', D: 'Dropped', W: 'Withdrawn', C: 'Cancelled' } },
        registration_term_code: { label: 'Registration term', type: 'string', relation: 'registration_term' },
        registration_term_starts_on: { label: 'Registration term starts', type: 'date', relation: 'registration_term' },
        registration_section_code: { label: 'Registered section code', type: 'string', relation: 'registered_section' },
    } : {
        section_code: { label: 'Section code', type: 'string', relation: 'section' },
        term_code: { label: 'Academic term', type: 'string', relation: 'term' },
        capacity: { label: 'Section capacity', type: 'integer', relation: 'section' },
        enrollment_id: { label: 'Enrollment reference', type: 'string', relation: 'enrollment' },
        enrollment_status: { label: 'Enrollment status', type: 'string', relation: 'enrollment' },
        course_title: { label: 'Course title', type: 'string', relation: 'course' },
    };
    if (options.postings || options.postingLinks) Object.assign(fields, {
        posting_id: { label: 'Posting reference', type: 'string', relation: 'entry' },
        posting_type: { label: 'Posting type', type: 'string', relation: 'entry',
            choices: { charge: 'Charge', payment: 'Payment', refund: 'Refund', charge_credit: 'Charge credit' } },
        posting_amount: { label: 'Posting amount', type: 'double', relation: 'entry' },
        posting_reversed: { label: 'Posting reversed', type: 'boolean', relation: 'entry' },
        posting_recorded_at: { label: 'Posting event recorded UTC', type: 'datetime', relation: 'entry' },
    });
    const dataset = options.postings ? {
        id: 'postings', label: 'Financial postings', description: 'One row per original or replacement posting.', fields,
        relationships: {
            account: { label: 'Student accounts', parent: 'entry', many: false },
            student: { label: 'Student records', parent: 'account', many: false },
            person: { label: 'Student names', parent: 'student', many: false },
            identity: { label: 'Institutional IDs', parent: 'person', many: false },
            term: { label: 'Academic terms', parent: 'account', many: false },
        },
    } : options.scheduling ? {
        id: options.scheduling, label: options.scheduling === 'rooms' ? 'Rooms and bookings' : 'Scheduled meetings',
        description: 'Booking occurrences. Times and filters use UTC.', fields,
        relationships: options.scheduling === 'rooms' ? {
            building: { label: 'Buildings', parent: 'room', many: false },
            meeting: { label: 'Room meetings', parent: 'room', many: true,
                date_fields: { starts_at: 'Meeting starts UTC', ends_at: 'Meeting ends UTC' } },
            booking: { label: 'Bookings', parent: 'meeting', many: false },
        } : {
            booking: { label: 'Bookings', parent: 'meeting', many: false },
            room: { label: 'Rooms', parent: 'meeting', many: false },
            building: { label: 'Buildings', parent: 'room', many: false },
        },
    } : options.accounts ? {
        id: 'accounts', label: 'Student account balances',
        description: 'One row per student and billing term. Currency stays with every amount.', fields,
        relationships: {
            balance: { label: 'Current balance', parent: 'account', many: false },
            student: { label: 'Student records', parent: 'account', many: false },
            person: { label: 'Student names', parent: 'student', many: false },
            identity: { label: 'Institutional IDs', parent: 'person', many: false },
            term: { label: 'Academic terms', parent: 'account', many: false },
        },
    } : options.applications ? {
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
            latest_registration: { label: 'Latest registered term', parent: 'student', many: false },
            registration: { label: 'Registrations', parent: 'student', many: true,
                date_fields: { registration_term_starts_on: 'Registration term starts' } },
            registration_term: { label: 'Registration terms', parent: 'registration', many: false },
            registered_section: { label: 'Registered sections', parent: 'registration', many: false },
        },
    } : { id: 'sections', label: 'Sections, teaching & enrollment counts', description: 'Explore sections and their registration relationships.', fields,
        relationships: { term: { label: 'Term', parent: 'section', many: false }, course: { label: 'Course', parent: 'section', many: false }, enrollment: { label: 'Enrollment', parent: 'section', many: true } } };
    if (options.postingLinks) dataset.relationships.entry = {
        label: 'Financial postings', parent: 'account', many: true,
        date_fields: { posting_recorded_at: 'Posting event recorded UTC' },
    };
    const selectionSummary = query => {
        const schema = copiedGraph(dataset, query?.aliases || {});
        return Object.entries(query?.record_selection || {}).map(([relation, selection]) => ({
            record: schema.relationships[relation].label, date: schema.fields[selection.date_field].label,
            direction: selection.direction, ties: selection.ties,
        }));
    };
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
            const values = { posting_amount: 12.5, posting_type: 'payment', posting_reversed: false, posting_recorded_at: '2026-11-19 18:00:00', booking_title: 'Fictional seminar', starts_at: '2026-11-19 18:00:00', ends_at: '2026-11-19 19:00:00', room_code: 'SCI.101', room_name: 'Science laboratory', building_name: 'Science building', room_capacity: 30, booking_status: 'approved', account_id: id(15), term_code: '26/FA', currency: 'USD', balance: 12.34, charges: 30, payments: 20.66, refunds: 3, institutional_id: 'S-FICTIONAL', full_name: 'Alex Example', program_code: 'BS.CS',
                entry_term: '26/FA', latest_term_code: options.never ? null : '27/SP',
                latest_term_starts_on: options.never ? null : '2027-01-04', status: 'accepted', term_starts_on: '2027-01-04',
                submitted_on: options.unknownSubmission ? null : '2026-08-10', submission_channel: 'form' };
            const rows = options.postings || options.scheduling || options.students || options.applications || options.accounts ? [Object.fromEntries(query.columns.map((column, i) => [`c${i}`, values[column.field] ?? null]))]
                : data.offset ? [{ c0: 'CHEM.101.A', c1: '26/FA', c2: 5 }] : [{ c0: 'MATH.101.A', c1: '26/FA', c2: 8 }, { c0: 'ENG.101.A', c1: '26/FA', c2: 0 }];
            result = { conditional_columns: query.columns.filter(column => column.conditions).map(column => column.label), row_selection: selectionSummary(query), columns: query.columns.map(column => column.label), items: rows, offset: data.offset,
                next_offset: options.postings || options.scheduling || options.students || options.applications || options.accounts || data.offset ? null : 50, limit: 50,
                grouped: options.postings || options.scheduling || options.accounts ? query.columns.some(column => column.aggregate !== 'none') : !options.students && !options.applications };
        } else if (path.endsWith('/export/')) { await route.fulfill({ body: 'Section,Term,Students\nMATH.101.A,26/FA,8\n', contentType: 'text/csv' }); return; }
        else if (/\/sis\/report-studio\/[^/]+\/$/.test(path)) result = { conditional_columns: definition.columns.filter(column => column.conditions).map(column => column.label), row_selection: selectionSummary(definition), id: path.split('/').at(-2), name, version, definition, can_manage: !options.recipient };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(options.recipient ? '/admin/report-fixture/' : '/admin/');
    await page.locator(`.workspace-nav-link[data-workspace="${options.recipient ? 'sharedreports' : 'reports'}"]`).click();
    await page.getByRole('button', { name: options.recipient ? name : 'Open Report Studio', exact: true }).click();
    if (!options.recipient && !options.collapsedTemplates) await page.getByText('Start from a template', { exact: true }).click();
    return { calls, errors, visited };
}

test('financial posting templates keep reversed originals explicit and summarize payments by currency', async ({ page }) => {
    const f = await fixture(page, { postings: true });
    await page.getByRole('button', { name: 'Payments by billing term', exact: true }).click();
    await page.getByLabel('Report name', { exact: true }).fill('Fictional payment summary');
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    const query = f.calls.filter(call => call.path.endsWith('/preview/')).at(-1).data.definition;
    expect(query.columns.map(column => [column.field, column.aggregate])).toEqual([
        ['term_code', 'none'], ['currency', 'none'], ['posting_amount', 'sum'],
    ]);
    expect(query.filters.rules).toEqual([
        { id: 'posting_reversed', operator: 'equal', value: false },
        { id: 'posting_type', operator: 'equal', value: 'payment' },
    ]);
    await expect(page.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('USD');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.getByRole('button', { name: 'Edit', exact: true }).click();
    await expect(page.getByLabel('Column 3 label', { exact: true })).toHaveValue('Payments');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.errors).toEqual([]);
});

test('latest account payments retain candidate filters and explicit timestamp ties after reopening', async ({ page }) => {
    const f = await fixture(page, { accounts: true, postingLinks: true });
    await page.getByRole('button', { name: 'Latest payment postings', exact: true }).click();
    await page.getByLabel('Report name', { exact: true }).fill('Fictional latest payments');
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    const query = f.calls.filter(call => call.path.endsWith('/preview/')).at(-1).data.definition;
    expect(query.record_selection.entry).toEqual({ date_field: 'posting_recorded_at', direction: 'latest', ties: 'all' });
    expect(query.join_filters.entry.rules).toEqual([
        { id: 'posting_type', operator: 'equal', value: 'payment' },
        { id: 'posting_reversed', operator: 'equal', value: false },
    ]);
    await expect(page.getByRole('complementary', { name: 'Related row selection' })).toContainText('Equal dates are kept');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.getByRole('button', { name: 'Edit', exact: true }).click();
    const chooser = page.locator('[data-record-selection="entry"]');
    await chooser.locator('summary').click();
    await expect(chooser.getByLabel('Financial postings: date', { exact: true })).toHaveValue('posting_recorded_at');
    expect(f.errors).toEqual([]);
});

test('conditional summaries edit independently and retain their rules through save and reopening', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Active and withdrawn registrations', exact: true }).click();
    await page.getByLabel('Report name', { exact: true }).fill('Fictional registration comparison');
    await page.getByRole('button', { name: /^Column 2 settings:/ }).click();
    await page.getByRole('button', { name: 'Edit column conditions', exact: true }).click();
    const conditions = page.getByRole('group', { name: 'Column conditions', exact: true });
    await conditions.getByLabel('Column conditions: comparison', { exact: true }).selectOption('equal');
    await conditions.getByLabel('Column conditions: value', { exact: true }).fill('A');
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    const query = f.calls.filter(call => call.path.endsWith('/preview/')).at(-1).data.definition;
    expect(query.columns[1].conditions.rules[0]).toMatchObject({ id: 'enrollment_status', operator: 'equal', value: 'A' });
    expect(query.columns[2].conditions.rules[0]).toMatchObject({ id: 'enrollment_status', operator: 'equal', value: 'W' });
    await expect(page.getByRole('complementary', { name: 'Conditional summaries' })).toContainText('Active · Withdrawn');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.getByRole('button', { name: 'Edit', exact: true }).click();
    await page.getByRole('tab', { name: 'Filters', exact: true }).click();
    await expect(page.getByRole('group', { name: 'Column conditions', exact: true })
        .getByLabel('Column conditions: value', { exact: true })).toHaveValue('A');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.errors).toEqual([]);
});

test('duplicating a conditional column preserves its settings and clearing one does not clear the original', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Active and withdrawn registrations', exact: true }).click();
    await page.getByRole('button', { name: /^Column 2 settings:/ }).click();
    await page.getByRole('button', { name: 'Duplicate column', exact: true }).click();
    await page.getByRole('button', { name: 'Done', exact: true }).click();
    await page.getByLabel('Column 3 label', { exact: true }).fill('Other active');
    await page.getByRole('button', { name: /^Column 3 settings:/ }).click();
    await page.getByRole('button', { name: 'Edit column conditions', exact: true }).click();
    await page.getByRole('button', { name: 'Clear column conditions', exact: true }).click();
    await expect(page.locator('[data-report-column-editor] h3')).toBeFocused();
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    const query = f.calls.filter(call => call.path.endsWith('/preview/')).at(-1).data.definition;
    expect(query.columns).toHaveLength(4);
    expect(query.columns[1].conditions.rules[0].value).toEqual(['A', 'N']);
    expect(query.columns[2].aggregate).toBe('count_distinct');
    expect(query.columns[2].conditions || null).toBeNull();
    expect(query.columns[3].conditions.rules[0].value).toBe('W');
    expect(f.errors).toEqual([]);
});

test('removing a source removes dependent conditional columns instead of widening their totals', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Section headcounts, including empty sections', exact: true }).click();
    await page.getByRole('button', { name: /^Column 3 settings:/ }).click();
    await page.getByRole('button', { name: 'Add column conditions', exact: true }).click();
    const conditions = page.getByRole('group', { name: 'Column conditions', exact: true });
    await conditions.getByLabel('Column conditions: field', { exact: true }).selectOption('term_code');
    await conditions.getByLabel('Column conditions: value', { exact: true }).fill('26/FA');
    await page.getByRole('tab', { name: 'Fields', exact: true }).click();
    await page.getByRole('button', { name: 'Remove Term and its fields', exact: true }).click();
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    const query = f.calls.filter(call => call.path.endsWith('/preview/')).at(-1).data.definition;
    expect(query.columns.map(column => column.field)).toEqual(['section_code']);
    expect(f.errors).toEqual([]);
});

test('scheduled meetings keep UTC timestamp ranges through preview, save and reopening', async ({ page }) => {
    const f = await fixture(page, { scheduling: 'meetings' });
    await page.getByRole('button', { name: 'Approved meeting schedule', exact: true }).click();
    await page.getByLabel('Report name', { exact: true }).fill('Fictional schedule');
    await page.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    const conditions = page.getByRole('group', { name: 'Row conditions', exact: true });
    await conditions.getByLabel('Row conditions: field', { exact: true }).first().selectOption('starts_at');
    await conditions.getByLabel('Row conditions: comparison', { exact: true }).first().selectOption('between');
    const timestamps = conditions.locator('input[type="datetime-local"]');
    await timestamps.nth(0).fill('2026-11-19T00:00');
    await timestamps.nth(1).fill('2026-11-19T23:59:59');
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    const query = f.calls.find(call => call.path.endsWith('/preview/')).data.definition;
    expect(query.filters.rules[0]).toMatchObject({ id: 'starts_at', operator: 'between',
        value: ['2026-11-19 00:00:00', '2026-11-19 23:59:59'] });
    await expect(page.getByRole('table', { name: 'Relational report results' })).toContainText('2026-11-19 18:00:00');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.getByRole('button', { name: 'Edit', exact: true }).click();
    await page.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    const restored = page.getByRole('group', { name: 'Row conditions', exact: true }).locator('input[type="datetime-local"]');
    await expect(restored.nth(0)).toHaveValue('2026-11-19T00:00');
    await expect(restored.nth(1)).toHaveValue('2026-11-19T23:59:59');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.errors).toEqual([]);
});

test('rooms without approved meetings use one independent matching card and can choose latest timestamps', async ({ page }) => {
    const f = await fixture(page, { scheduling: 'rooms' });
    await page.getByRole('button', { name: 'Rooms without approved meetings', exact: true }).click();
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    const query = f.calls.find(call => call.path.endsWith('/preview/')).data.definition;
    expect(query.existence.meeting).toMatchObject({ mode: 'none', filters: { rules: [
        { id: 'booking_status', operator: 'equal', value: 'approved' },
        { id: 'cancelled_at', operator: 'is_null' },
    ] } });
    await page.getByRole('tab', { name: 'Fields', exact: true }).click();
    await page.getByRole('button', { name: 'Add Room meetings', exact: true }).click();
    const chooser = page.locator('[data-record-selection="meeting"]');
    await chooser.locator('summary').click();
    await chooser.getByRole('button', { name: 'Latest', exact: true }).click();
    await expect(chooser.getByLabel('Room meetings: date', { exact: true })).toHaveValue('starts_at');
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    const latest = f.calls.filter(call => call.path.endsWith('/preview/')).at(-1).data.definition;
    expect(latest.record_selection.meeting).toEqual({ date_field: 'starts_at', direction: 'latest', ties: 'all' });
    expect(latest.existence.meeting).toEqual(query.existence.meeting);
    expect(f.errors).toEqual([]);
});

test('the Students source opens with useful columns and supports preview, save and reopening', async ({ page }) => {
    const f = await fixture(page, { students: true });
    await page.getByRole('button', { name: 'Start report: Students & current primary program', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await expect(studio.locator('.report-local-error')).toBeHidden();
    await expect(studio.getByLabel('Column 1 label', { exact: true })).toHaveValue('Institutional ID');
    await expect(studio.getByLabel('Column 2 label', { exact: true })).toHaveValue('Student full name');
    await expect(studio.getByLabel('Column 3 label', { exact: true })).toHaveValue('Primary program code');
    await expect(studio.getByLabel('Column 4 label', { exact: true })).toHaveValue('Institutional entry term');
    await studio.getByLabel('Report name', { exact: true }).fill('Fictional current students');
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    expect(f.calls.find(call => call.path.endsWith('/preview/')).data.definition).toMatchObject({
        dataset: 'students', columns: [
            { field: 'institutional_id', aggregate: 'none' },
            { field: 'full_name', aggregate: 'none' },
            { field: 'program_code', aggregate: 'none' },
            { field: 'entry_term', aggregate: 'none' },
        ],
    });
    await studio.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(studio).toContainText('Definition revision 1');
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    await studio.getByRole('button', { name: 'Edit', exact: true }).click();
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
        await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
        await expect(studio.getByLabel('Row conditions: field', { exact: true })
            .locator('option[value="latest_term_code"]')).toHaveText('Latest registered term');
        await studio.getByRole('button', { name: 'Preview', exact: true }).click();
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
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    const conditions = studio.getByRole('group', { name: 'Row conditions', exact: true });
    await conditions.getByLabel('Row conditions: field', { exact: true }).selectOption('latest_term_starts_on');
    await conditions.getByLabel('Row conditions: comparison', { exact: true }).selectOption('between');
    const values = conditions.getByLabel('Row conditions: value', { exact: true });
    await expect(values).toHaveCount(2);
    await expect(values.first()).toHaveAttribute('type', 'date');
    await values.first().fill('2027-01-01');
    await values.nth(1).fill('2027-06-30');
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    expect(f.calls.find(call => call.path.endsWith('/preview/')).data.definition.filters.rules[0])
        .toMatchObject({ id: 'latest_term_starts_on', operator: 'between', value: ['2027-01-01', '2027-06-30'] });
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    await conditions.getByLabel('Row conditions: comparison', { exact: true }).selectOption('in');
    await expect(values).toHaveCount(1);
    await expect(values).toHaveAttribute('type', 'text');
    await values.fill('2027-01-01,2028-01-01');
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    expect(f.calls.filter(call => call.path.endsWith('/preview/')).at(-1).data.definition.filters.rules[0])
        .toMatchObject({ id: 'latest_term_starts_on', operator: 'in', value: ['2027-01-01', '2028-01-01'] });
    await studio.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(studio).toContainText('Definition revision 1');
    await studio.getByRole('button', { name: 'Edit', exact: true }).click();
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
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
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    const panel = studio.locator('[data-report-match="registration"]');
    await expect(panel.getByRole('button', { name: 'Has a match', exact: true })).toHaveAttribute('aria-pressed', 'true');
    const matching = panel.getByRole('group', { name: 'Matching registrations', exact: true });
    await matching.getByRole('button', { name: 'Add condition', exact: true }).click();
    await matching.getByLabel('Matching registrations: field', { exact: true }).nth(1)
        .selectOption('registration_term_code');
    await matching.getByLabel('Matching registrations: value', { exact: true }).nth(1).fill('26/FA');
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    const preview = f.calls.find(c => c.path.endsWith('/preview/')).data.definition;
    expect(preview.existence.registration).toMatchObject({ mode: 'any', filters: { condition: 'AND', rules: [
        { id: 'registration_status', operator: 'in', value: ['A', 'N'] },
        { id: 'registration_term_code', operator: 'equal', value: '26/FA' },
    ] } });
    expect(preview.joins).not.toHaveProperty('registration');
    expect(preview.joins).not.toHaveProperty('registration_term');
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    await panel.getByRole('button', { name: 'No match', exact: true }).click();
    await expect(panel.getByRole('button', { name: 'No match', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(matching.getByLabel('Matching registrations: value', { exact: true }).nth(1)).toHaveValue('26/FA');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await studio.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(studio).toContainText('Definition revision 1');
    await studio.getByRole('button', { name: 'Edit', exact: true }).click();
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    await expect(panel.getByRole('button', { name: 'No match', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await studio.getByLabel('Record source', { exact: true }).selectOption('latest_registration');
    await studio.getByRole('button', { name: 'Add match', exact: true }).click();
    const added = studio.locator('[data-report-match="latest_registration"]');
    await expect(added.getByRole('heading', { name: 'Latest registered term', exact: true })).toBeFocused();
    await added.getByRole('button', { name: 'Remove Latest registered term condition', exact: true }).click();
    await expect(added).toHaveCount(0);
    await expect(studio.getByLabel('Record source', { exact: true })).toBeFocused();
    await panel.getByRole('button', { name: 'Remove Registrations condition', exact: true }).click();
    await expect(panel).toHaveCount(0);
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    expect(f.calls.filter(c => c.path.endsWith('/preview/')).at(-1).data.definition.existence).toEqual({});
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('staff add named join copies, compare independent terms, save and reopen them without SQL', async ({ page }) => {
    const f = await fixture(page, { students: true });
    await page.getByRole('button', { name: 'Start report: Students & current primary program', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await studio.getByLabel('Report name', { exact: true }).fill('Fictional Fall and Spring comparison');
    await studio.getByRole('button', { name: 'Add another Registrations', exact: true }).click();
    const copy = studio.locator('[data-relation="copy1"]');
    await expect(copy.getByLabel('Name this record copy', { exact: true })).toBeFocused();
    await copy.getByLabel('Name this record copy', { exact: true }).fill('Spring registrations');
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    for (const [source, label, field, value] of [
        ['registration', 'registrations', 'registration_term_code', '26/FA'],
        ['copy1', 'spring registrations', 'copy1__registration_term_code', '27/SP'],
    ]) {
        await studio.getByLabel('Record source', { exact: true }).selectOption(source);
        await studio.getByRole('button', { name: 'Add match', exact: true }).click();
        const panel = studio.locator(`[data-report-match="${source}"]`);
        await panel.getByLabel(`Matching ${label}: field`, { exact: true }).selectOption(field);
        await panel.getByLabel(`Matching ${label}: value`, { exact: true }).fill(value);
    }
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    const query = f.calls.find(c => c.path.endsWith('/preview/')).data.definition;
    expect(query.aliases).toEqual({ copy1: { relation: 'registration', label: 'Spring registrations' } });
    expect(query.existence.copy1.filters.rules[0]).toMatchObject({ id: 'copy1__registration_term_code', value: '27/SP' });
    expect(query.joins).not.toHaveProperty('copy1');
    await studio.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(studio).toContainText('Definition revision 1');
    await studio.getByRole('button', { name: 'Edit', exact: true }).click();
    await expect(copy.getByLabel('Name this record copy', { exact: true })).toHaveValue('Spring registrations');
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    await expect(studio.locator('[data-report-match="copy1"]').getByLabel('Matching spring registrations: value', { exact: true }))
        .toHaveValue('27/SP');
    await studio.getByRole('tab', { name: 'Fields', exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await copy.getByRole('button', { name: 'Remove Spring registrations and its fields', exact: true }).click();
    await expect(copy).toHaveCount(0);
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    const removed = f.calls.filter(c => c.path.endsWith('/preview/')).at(-1).data.definition;
    expect(removed.aliases).toEqual({});
    expect(removed.existence).not.toHaveProperty('copy1');
    expect(f.calls.filter(c => c.path.endsWith('/graph/')).every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(f.errors).toEqual([]);
});

test('copied fields become ordinary output columns and removing the copy removes those references', async ({ page }) => {
    const f = await fixture(page, { students: true });
    await page.getByRole('button', { name: 'Start report: Students & current primary program', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('button', { name: 'Add another Registrations', exact: true }).click();
    const copy = studio.locator('[data-relation="copy1"]');
    await copy.getByRole('checkbox', { name: 'Registration status', exact: true }).check();
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('Alex Example');
    expect(f.calls.find(c => c.path.endsWith('/preview/')).data.definition.columns.at(-1).field)
        .toBe('copy1__registration_status');
    await studio.getByRole('tab', { name: 'Fields', exact: true }).click();
    await copy.getByRole('button', { name: 'Remove Registrations 2 and its fields', exact: true }).click();
    await expect(copy).toHaveCount(0);
    await expect(studio.getByLabel('Column 5 label', { exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('admissions opens in the same field and condition builder and saves a configurable outcome report', async ({ page }) => {
    const f = await fixture(page, { applications: true });
    await page.getByRole('button', { name: 'Start report: Applications & admissions', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await expect(studio.getByLabel('Column 2 label', { exact: true })).toHaveValue('Applicant full name');
    await studio.getByLabel('Report name', { exact: true }).fill('Fictional accepted applications');
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    const rows = studio.getByRole('group', { name: 'Row conditions', exact: true });
    await rows.getByLabel('Row conditions: field', { exact: true }).selectOption('status');
    await rows.getByLabel('Row conditions: value', { exact: true }).selectOption('accepted');
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('accepted');
    expect(f.calls.find(c => c.path.endsWith('/preview/')).data.definition).toMatchObject({
        dataset: 'applications', filters: { rules: [{ id: 'status', operator: 'equal', value: 'accepted' }] },
    });
    await studio.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(studio).toContainText('Definition revision 1');
    expect(f.errors).toEqual([]);
});

test('readable status choices preserve codes, multiple values and saved custom text comparisons', async ({ page }) => {
    const f = await fixture(page, { students: true });
    await page.getByRole('button', { name: 'Students with active registrations', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await studio.getByLabel('Report name', { exact: true }).fill('Fictional custom status');
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    const matching = studio.getByRole('group', { name: 'Matching registrations', exact: true });
    const comparison = matching.getByLabel('Matching registrations: comparison', { exact: true });
    const value = matching.getByLabel('Matching registrations: value', { exact: true });
    await expect(value).toHaveValues(['A', 'N']);
    await expect(value.getByRole('option', { name: 'Registered by staff', exact: true })).toHaveAttribute('value', 'A');
    await comparison.selectOption('equal');
    await value.selectOption({ label: 'Dropped' });
    await comparison.selectOption('in');
    await value.selectOption(['D', 'W']);
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    expect(f.calls.find(c => c.path.endsWith('/preview/')).data.definition.existence.registration.filters.rules[0])
        .toMatchObject({ id: 'registration_status', operator: 'in', value: ['D', 'W'] });
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    await comparison.selectOption('contains');
    await value.fill('X');
    await studio.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(studio).toContainText('Definition revision 1');
    await studio.getByRole('button', { name: 'Edit', exact: true }).click();
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    await expect(value).toHaveValue('X');
    await comparison.selectOption('equal');
    await expect(value).toHaveValue('X');
    await value.selectOption('A');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test('submission dates use native date conditions and survive save and reopening without exposing history', async ({ page }) => {
    const f = await fixture(page, { applications: true });
    await page.getByRole('button', { name: 'Applications with recorded submission dates', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await expect(studio.getByLabel('Column 6 label', { exact: true })).toHaveValue('Submitted on');
    await studio.getByLabel('Report name', { exact: true }).fill('Fictional August intake');
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    const rows = studio.getByRole('group', { name: 'Row conditions', exact: true });
    await rows.getByLabel('Row conditions: comparison', { exact: true }).selectOption('between');
    const dates = rows.locator('input[type="date"]');
    await expect(dates).toHaveCount(2);
    await dates.nth(0).fill('2026-08-01');
    await dates.nth(1).fill('2026-08-31');
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('2026-08-10');
    const query = f.calls.find(call => call.path.endsWith('/preview/')).data.definition;
    expect(query.filters.rules[0]).toMatchObject({ id: 'submitted_on', operator: 'between', value: ['2026-08-01', '2026-08-31'] });
    await studio.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(studio).toContainText('Definition revision 1');
    await studio.getByRole('button', { name: 'Edit', exact: true }).click();
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
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
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
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
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    const panel = studio.locator('[data-report-match="enrollment"]');
    await expect(panel.getByRole('button', { name: 'No match', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
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
    await page.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    await expect(page.getByRole('group', { name: 'Enrollment conditions', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
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
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.locator('.report-workbench')).toContainText('Definition revision 1');
    const download = page.waitForEvent('download'); await page.getByRole('button', { name: 'Export page', exact: true }).click();
    expect((await download).suggestedFilename()).toBe('report-studio-page.csv');
    await page.getByRole('button', { name: 'Edit', exact: true }).click();
    await expect(page.getByLabel('Report name', { exact: true })).toHaveValue('Fictional section headcounts');
    await page.getByLabel('Column 3 label', { exact: true }).fill('Students <img src=x onerror=alert(1)>');
    await page.getByRole('button', { name: /^Column 3 settings:/ }).click();
    await page.getByRole('group', { name: 'Column 3 summary', exact: true }).getByRole('button', { name: 'Count', exact: true }).click();
    await expect(page.locator('.report-workbench img')).toHaveCount(0);
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.locator('.report-workbench')).toContainText('Definition revision 2');
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('report filters stay in the editor and support nested field comparisons without navigating away', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Section headcounts, including empty sections', exact: true }).click();
    await page.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    const rows = page.getByRole('group', { name: 'Row conditions', exact: true });
    await rows.getByLabel('Row conditions: field', { exact: true }).selectOption('term_code');
    await rows.getByLabel('Row conditions: value', { exact: true }).fill('26/FA');
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
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
    const save = page.getByRole('button', { name: 'Save', exact: true });
    await save.click();
    await expect(page.getByLabel('Report name', { exact: true })).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Preview', exact: true })).toBeDisabled();
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
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
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
    await page.getByRole('button', { name: 'Auto arrange', exact: true }).click();
    await workspace.evaluate(element => { element.scrollTop = 0; });
    await page.screenshot({ path: test.info().outputPath('visual-report-builder-desktop.png'), fullPage: true });
    await accessible(page);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    await page.screenshot({ path: test.info().outputPath('visual-report-builder-mobile.png'), fullPage: true });
    expect(await workspace.evaluate(element => element.scrollWidth <= element.clientWidth)).toBe(true);
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
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
    await page.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    const totals = page.getByRole('group', { name: 'Summary conditions', exact: true });
    await totals.getByLabel('Summary conditions: field', { exact: true }).selectOption('c2');
    await totals.getByLabel('Summary conditions: comparison', { exact: true }).selectOption('less');
    await totals.getByLabel('Summary conditions: value', { exact: true }).fill('12');
    await page.getByRole('tab', { name: 'Fields', exact: true }).click();
    await page.getByRole('button', { name: 'Sort column 3: off', exact: true }).click();
    await page.getByRole('button', { name: 'Move column 3 up', exact: true }).click();
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
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
    await expect(page.getByRole('button', { name: 'Edit', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Manage report sharing', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Open Report Studio', exact: true })).toHaveCount(0);
    await expect(viewer.locator('input, select')).toHaveCount(0);
    expect(f.visited).not.toContain('/sis/report-studio/catalog/');
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.route('**/sis/report-studio/*/export/', route => route.fulfill({ status: 403, json: { error: 'Access denied.' } }));
    await page.getByRole('button', { name: 'Export page', exact: true }).click();
    await expect(viewer.getByRole('alert')).toContainText('Access denied.');
    await expect(viewer.locator('table')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Export page', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('removing a related record also removes its dependent columns, summaries and matching conditions', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Section headcounts, including empty sections', exact: true }).click();
    await page.getByRole('tab', { name: 'Filters', exact: true }).click();
    await page.locator('.report-advanced-filters > summary').click();
    const rows = page.getByRole('group', { name: 'Row conditions', exact: true });
    await rows.getByLabel('Row conditions: field', { exact: true }).selectOption('term_code');
    await rows.getByLabel('Row conditions: value', { exact: true }).fill('26/FA');
    await page.getByRole('tab', { name: 'Fields', exact: true }).click();
    await page.getByRole('button', { name: 'Remove Term and its fields', exact: true }).click();
    await expect(page.locator('[data-relation="term"]')).toHaveCount(0);
    await page.getByRole('button', { name: 'Remove Enrollment and its fields', exact: true }).click();
    await expect(page.locator('[data-relation="enrollment"]')).toHaveCount(0);
    await expect(page.getByLabel('Column 1 label', { exact: true })).toHaveValue('Section');
    await expect(page.getByLabel('Column 2 label', { exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
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

for (const startingPoint of ['Outstanding student balances', 'Available student credit', 'Balances by billing term']) {
    test(`account balances open ${startingPoint} in the shared visual builder`, async ({ page }) => {
        const f = await fixture(page, { accounts: true });
        await expect(page.getByRole('button', { name: 'Applications with recorded submission dates', exact: true })).toHaveCount(0);
        await page.getByRole('button', { name: startingPoint, exact: true }).click();
        const studio = page.locator('dialog.report-studio');
        await expect(studio.locator('.report-local-error')).toBeHidden();
        await expect(studio).toContainText('Keep Currency in the results.');
        await studio.getByRole('button', { name: 'Preview', exact: true }).click();
        await expect(studio.getByRole('region', { name: 'Relational report results', exact: true })).toContainText('USD');
        const query = f.calls.find(call => call.path.endsWith('/preview/')).data.definition;
        expect(query.dataset).toBe('accounts');
        expect(query.columns).toContainEqual({ field: 'currency', label: 'Currency', aggregate: 'none' });
        if (startingPoint === 'Balances by billing term') {
            expect(query.columns).toContainEqual({ field: 'balance', label: 'Net balance', aggregate: 'sum' });
            expect(query.columns.map(column => column.field)).toEqual(['term_code', 'currency', 'charges', 'payments', 'refunds', 'balance', 'account_id']);
            for (const field of ['charges', 'payments', 'refunds']) {
                expect(query.columns.find(column => column.field === field).aggregate).toBe('sum');
            }
        } else {
            expect(query.filters.rules).toEqual([{ id: 'balance', operator: startingPoint.startsWith('Outstanding') ? 'greater' : 'less', value: 0 }]);
            expect(query.columns.map(column => column.field)).toEqual(['institutional_id', 'full_name', 'term_code', 'currency', 'balance']);
        }
        await studio.getByLabel('Report name', { exact: true }).fill('Fictional account report');
        await studio.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(studio).toContainText('Definition revision 1');
        await page.setViewportSize({ width: 320, height: 812 });
        await accessible(page);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
        expect(f.calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
        expect(f.errors).toEqual([]);
    });
}

test('the workspace presents short primary actions and discloses templates and saved options', async ({ page }) => {
    const f = await fixture(page, { students: true, collapsedTemplates: true });
    const studio = page.locator('dialog.report-studio');
    await expect(studio.locator('.report-templates')).not.toHaveAttribute('open');
    await studio.getByRole('button', { name: 'Start report: Students & current primary program', exact: true }).click();
    await expect(studio.getByRole('tab', { name: 'Fields', exact: true })).toHaveAttribute('aria-selected', 'true');
    await expect(studio.locator('.report-editor-actions').getByRole('button', { name: 'Preview', exact: true })).toBeVisible();
    await expect(studio.getByRole('button', { name: 'Check save', exact: true })).toBeHidden();
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await expect(studio.getByRole('heading', { name: 'Summary filters', exact: true })).toHaveCount(0);
    await expect(studio.locator('.report-advanced-filters')).not.toHaveAttribute('open');
    await studio.getByLabel('Report name', { exact: true }).fill('Fictional clear controls');
    await studio.getByRole('button', { name: 'Save', exact: true }).click();
    await studio.getByRole('button', { name: 'Report options', exact: true }).click();
    await expect(studio.getByRole('button', { name: 'Share', exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(studio).toBeVisible();
    await expect(studio.getByRole('button', { name: 'Report options', exact: true })).toHaveAttribute('aria-expanded', 'false');
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test('nested related-record groups support keyboard moves, preserve criteria and reopen after saving', async ({ page }) => {
    const f = await fixture(page, { students: true });
    await page.getByRole('button', { name: 'Start report: Students & current primary program', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    for (const source of ['registration', 'program']) {
        await studio.locator('[data-report-match-picker]').selectOption(source);
        await studio.getByRole('button', { name: 'Add match', exact: true }).click();
    }
    const registered = studio.locator('[data-report-match="registration"]');
    const criteria = registered.getByRole('group', { name: 'Matching registrations', exact: true });
    await criteria.getByRole('button', { name: 'Add condition', exact: true }).click();
    await criteria.getByLabel('Matching registrations: field', { exact: true }).last().selectOption('registration_term_code');
    await criteria.getByLabel('Matching registrations: value', { exact: true }).last().fill('26/FA');
    await studio.locator('[data-match-group=""] > .report-match-group-tools').getByRole('button', { name: 'Add group', exact: true }).click();
    for (const source of ['registration', 'program']) {
        const card = studio.locator(`[data-report-match="${source}"]`);
        await card.locator('.report-match-move > summary').click();
        await card.getByLabel(/Move .* to group/).selectOption('1');
        await card.getByRole('button', { name: 'Move to group', exact: true }).click();
    }
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    const definition = f.calls.filter(c => c.path.endsWith('/preview/')).at(-1).data.definition;
    expect(definition.existence_logic).toEqual({ condition: 'AND', rules: [{ condition: 'OR', rules: [
        { relation: 'registration' }, { relation: 'program' },
    ] }] });
    expect(definition.existence.registration.filters.rules.some(rule => rule.value === '26/FA')).toBe(true);
    await studio.getByLabel('Report name', { exact: true }).fill('Fictional Any records');
    await studio.getByRole('button', { name: 'Save', exact: true }).click();
    await studio.getByRole('button', { name: 'Edit', exact: true }).click();
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await expect(studio.getByRole('group', { name: 'Group 1 logic', exact: true }).getByRole('button', { name: 'Any', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await studio.locator('.report-match-group').first().scrollIntoViewIfNeeded();
    await expect(studio.getByRole('button', { name: 'Preview', exact: true })).toBeInViewport();
    await page.setViewportSize({ width: 375, height: 812 });
    await studio.locator('[data-report-match="registration"] .report-match-move > summary').click();
    await accessible(page);
    expect(await studio.evaluate(element => element.scrollWidth <= element.clientWidth)).toBe(true);
    expect(f.errors).toEqual([]);
});

test('match cards drag into a group and empty groups stop preview without losing filters', async ({ page }) => {
    const f = await fixture(page, { students: true });
    await page.getByRole('button', { name: 'Start report: Students & current primary program', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('tab', { name: 'Filters', exact: true }).click();
    await studio.locator('[data-report-match-picker]').selectOption('registration');
    await studio.getByRole('button', { name: 'Add match', exact: true }).click();
    await studio.locator('[data-match-group=""] > .report-match-group-tools').getByRole('button', { name: 'Add group', exact: true }).click();
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    await expect(studio.locator('.report-local-error')).toContainText('empty groups');
    expect(f.calls.filter(c => c.path.endsWith('/preview/'))).toHaveLength(0);
    const grip = studio.locator('[data-report-match="registration"] .report-match-grip');
    await grip.scrollIntoViewIfNeeded();
    const handle = await grip.boundingBox();
    await page.mouse.move(handle.x + handle.width / 2, handle.y + handle.height / 2);
    await page.mouse.down();
    await page.mouse.move(handle.x + handle.width / 2 + 12, handle.y + handle.height / 2 + 12);
    const destination = studio.locator('[data-match-group="1"] > .report-match-group-content');
    await destination.scrollIntoViewIfNeeded();
    const target = await destination.boundingBox();
    await page.mouse.move(target.x + target.width / 2, target.y + target.height / 2, { steps: 10 });
    await page.mouse.up();
    await expect(studio.locator('[data-match-group="0"] [data-report-match="registration"]')).toBeVisible();
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    expect(f.calls.filter(c => c.path.endsWith('/preview/')).at(-1).data.definition.existence_logic).toEqual({
        condition: 'AND', rules: [{ condition: 'OR', rules: [{ relation: 'registration' }] }],
    });
    expect(f.errors).toEqual([]);
});


test('latest related rows use compact controls, keep ties by default and survive save and reopening', async ({ page }) => {
    const f = await fixture(page, { students: true });
    await page.getByRole('button', { name: 'Start report: Students & current primary program', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('button', { name: 'Add Registrations', exact: true }).click();
    const chooser = studio.locator('[data-record-selection="registration"]');
    await expect(chooser).not.toHaveAttribute('open');
    await chooser.locator('summary').click();
    await chooser.getByRole('button', { name: 'Latest', exact: true }).click();
    await expect(chooser.getByLabel('Keep equal dates', { exact: true })).toBeChecked();
    await expect(chooser.getByLabel('Registrations: date', { exact: true })).toHaveValue('registration_term_starts_on');
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    await expect(studio.getByRole('complementary', { name: 'Related row selection' })).toContainText('Equal dates are kept');
    expect(f.calls.filter(c => c.path.endsWith('/preview/')).at(-1).data.definition.record_selection.registration)
        .toEqual({ date_field: 'registration_term_starts_on', direction: 'latest', ties: 'all' });
    await studio.getByLabel('Report name', { exact: true }).fill('Fictional latest related rows');
    await studio.getByRole('button', { name: 'Save', exact: true }).click();
    await studio.getByRole('button', { name: 'Edit', exact: true }).click();
    await chooser.locator('summary').click();
    await expect(chooser.getByRole('button', { name: 'Latest', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await chooser.getByLabel('Keep equal dates', { exact: true }).uncheck();
    await expect(chooser).toContainText('does not establish which happened first');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await studio.evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    expect(f.calls.filter(c => c.path.endsWith('/preview/')).at(-1).data.definition.record_selection.registration.ties).toBe('one');
    expect(f.errors).toEqual([]);
});


test('record copies keep separate date choices and removing one clears only its selector', async ({ page }) => {
    const f = await fixture(page, { students: true });
    await page.getByRole('button', { name: 'Start report: Students & current primary program', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('button', { name: 'Add Registrations', exact: true }).click();
    const original = studio.locator('[data-record-selection="registration"]');
    await original.locator('summary').click();
    await original.getByRole('button', { name: 'Latest', exact: true }).click();
    await studio.getByRole('button', { name: 'Add another Registrations', exact: true }).click();
    const copyCard = studio.locator('[data-relation="copy1"]');
    await copyCard.getByLabel('Name this record copy', { exact: true }).fill('First registration');
    const copied = studio.locator('[data-record-selection="copy1"]');
    await copied.locator('summary').click();
    await copied.getByRole('button', { name: 'Earliest', exact: true }).click();
    await expect(copied.getByLabel('First registration: date', { exact: true })).toHaveValue('copy1__registration_term_starts_on');
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    let query = f.calls.filter(c => c.path.endsWith('/preview/')).at(-1).data.definition;
    expect(query.record_selection.registration.direction).toBe('latest');
    expect(query.record_selection.copy1.direction).toBe('earliest');
    await studio.getByRole('tab', { name: 'Fields', exact: true }).click();
    await copyCard.getByRole('button', { name: 'Remove First registration and its fields', exact: true }).click();
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    query = f.calls.filter(c => c.path.endsWith('/preview/')).at(-1).data.definition;
    expect(query.record_selection).not.toHaveProperty('copy1');
    expect(query.record_selection.registration.direction).toBe('latest');
    expect(f.errors).toEqual([]);
});

test('a date-selection change invalidates an older in-flight preview', async ({ page }) => {
    const f = await fixture(page, { students: true });
    await page.getByRole('button', { name: 'Start report: Students & current primary program', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('button', { name: 'Add Registrations', exact: true }).click();
    const chooser = studio.locator('[data-record-selection="registration"]');
    await chooser.locator('summary').click();
    await chooser.getByRole('button', { name: 'Latest', exact: true }).click();
    let release, started;
    const waiting = new Promise(resolve => { release = resolve; });
    const pending = new Promise(resolve => { started = resolve; });
    await page.route('**/sis/report-studio/preview/', async route => { started(); await waiting; await route.fallback(); });
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    await pending;
    await chooser.getByRole('button', { name: 'Earliest', exact: true }).click();
    release();
    await expect(studio).toContainText('The draft changed during the preview');
    await expect(studio.getByRole('tab', { name: 'Fields', exact: true })).toHaveAttribute('aria-selected', 'true');
    await studio.getByRole('button', { name: 'Preview', exact: true }).click();
    await expect(studio.getByRole('complementary', { name: 'Related row selection' })).toContainText('earliest');
    expect(f.calls.filter(c => c.path.endsWith('/preview/')).at(-1).data.definition.record_selection.registration.direction).toBe('earliest');
    expect(f.errors).toEqual([]);
});
