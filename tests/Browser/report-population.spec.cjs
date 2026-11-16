const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
}
async function fixture(page, options = {}) {
    const report = id(1), section = id(2), term = id(3), base = `/sis/report-studio/${report}/`;
    const students = Array.from({ length: options.many ? 26 : 3 }, (_, i) => ({ id: id(10 + i),
        institutional_id: `S-${i + 1}`, name: `Fictional <b>Student ${i + 1}</b>`, active: true }));
    const state = { version: 0, denied: false, conflict: false, calls: [], errors: [], report, section, term, students, base };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        state.calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) {
            const dataset = path.split('/')[4];
            result = { items: dataset === 'terms' ? [{ id: term, code: '26/FA' }]
                : dataset === 'sections' ? [{ id: section, code: 'ENG.201.A', course_title: 'Fictional English', term_code: '26/FA' }]
                : ['reports', 'sharedreports'].includes(dataset) ? [{ id: report, name: 'Fictional advising population' }] : [], next_after: null };
        } else if (path === `/sis/reports/${report}/`) result = { id: report, relational: true };
        else if (path === base) result = { id: report, name: 'Fictional advising population', version: state.version,
            can_manage: false, student_actions: ['registration', 'billing'], definition: { dataset: 'programs' } };
        else if (path === `${base}run/`) result = { columns: ['Institutional ID'], items: [{ c0: 'S-1' }],
            offset: 0, next_offset: null, grouped: false };
        else if (path === `${base}population/`) result = { items: options.many ? students : data.offset ? students.slice(2) : students.slice(0, 2),
            offset: data.offset, next_offset: options.many || data.offset ? null : 50, report_version: state.version };
        else if (path === `${base}population/select/`) {
            if (state.denied) return route.fulfill({ status: 403, json: { error: 'Access denied.' } });
            if (state.conflict) return route.fulfill({ status: 409, json: { error: 'The report membership changed.' } });
            result = { items: students.filter(s => data.student_ids.includes(s.id)), report_version: state.version };
        } else if (path === '/sis/billing-batches/review/') result = {
            term: { id: term, code: '26/FA' }, rules: [{ id: id(91), name: 'Fictional term fee', minor: 250, currency: 'USD' }],
            students: data.students.map(({ student_id }) => ({ student_id, name: students.find(s => s.id === student_id).name,
                institutional_id: students.find(s => s.id === student_id).institutional_id, program_label: 'BSCS',
                account_id: null, currency: 'USD', balance_before_minor: 0, balance_after_minor: 250,
                charge_minor: 250, new_charges: 1, already_posted: 0, previously_reversed: 0,
                items: [{ rule_id: id(91), status: 'new' }], problems: [] })),
            blocked: 0, new_charges: data.students.length,
            totals: [{ currency: 'USD', minor: data.students.length * 250 }], review_token: 'fictional-billing-review',
        };
        else if (path === '/sis/billing-batches/') result = {
            posted: 2, accounts_created: 2, totals: [{ currency: 'USD', minor: 500 }], students: [],
        };
        else if (path === '/sis/registration-batches/review/') result = {
            operation_id: id(90), revision: 'a'.repeat(64),
            section: { id: section, code: 'ENG.201.A', course_code: 'ENG.201', course_title: 'Fictional English',
                credits_milli: 3000, seats_remaining: 20, fee_minor: 0, currency: 'USD', active: true },
            students: data.student_ids.map(student => ({ id: student, institutional_id: students.find(s => s.id === student).institutional_id,
                given_name: 'Fictional', family_name: 'Student', active: true, enrollment_id: null,
                prerequisites: { status: 'not_required', missing_count: 0 } })),
        };
        else if (path === '/sis/registration-batches/commit/') result = { batch_id: id(90), section_id: section,
            created: data.student_ids.length, existing: 0, registrations: [] };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(options.recipient ? '/admin/report-fixture/' : options.billingOnly ? '/admin/report-billing-fixture/' : '/admin/');
    await page.locator(`.workspace-nav-link[data-workspace="${options.recipient || options.billingOnly ? 'sharedreports' : 'reports'}"]`).click();
    await page.getByRole('button', { name: 'Fictional advising population', exact: true }).click();
    return state;
}

test('a report shortlist opens the existing registration review with paging, back navigation and explicit confirmation', async ({ page }) => {
    const f = await fixture(page);
    expect(f.calls.some(c => c.path.includes('/population/'))).toBe(false);
    await page.getByRole('button', { name: 'Choose students for registration', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('checkbox', { name: /^Select S-1 / }).check();
    await studio.getByRole('button', { name: 'Next population page', exact: true }).click();
    await studio.getByRole('checkbox', { name: /^Select S-3 / }).check();
    await studio.getByRole('button', { name: 'Previous population page', exact: true }).click();
    await expect(studio.getByRole('checkbox', { name: /^Select S-1 / })).toBeChecked();
    await expect(studio.locator('b')).toHaveCount(0);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    await studio.getByRole('button', { name: 'Review selected students for registration', exact: true }).click();
    const drawer = page.locator('#record-drawer');
    await expect(drawer.getByRole('region', { name: 'Selected students', exact: true })).toContainText('2 selected students');
    await drawer.getByRole('button', { name: 'Back to report selection', exact: true }).click();
    await expect(studio.getByRole('checkbox', { name: /^Select S-1 / })).toBeChecked();
    await expect(studio.getByRole('region', { name: 'Selected report students' })).toContainText('2 of 25');
    await studio.getByRole('button', { name: 'Review selected students for registration', exact: true }).click();
    await drawer.getByRole('combobox', { name: /^Course section/ }).selectOption(f.section);
    await drawer.getByRole('button', { name: 'Review selected group', exact: true }).click();
    await expect(drawer).toContainText('No course prerequisites');
    await accessible(page);
    expect(f.calls.filter(c => c.path.endsWith('/registration-batches/commit/'))).toHaveLength(0);
    await drawer.getByRole('button', { name: 'Confirm group registration', exact: true }).click();
    await expect(drawer).toContainText('2 registrations created.');
    const selections = f.calls.filter(c => c.path.endsWith('/population/select/'));
    expect(selections.map(c => c.data)).toEqual([
        { student_ids: [f.students[0].id, f.students[2].id], report_version: 0, action: 'registration' },
        { student_ids: [f.students[0].id, f.students[2].id], report_version: 0, action: 'registration' },
    ]);
    expect(f.calls.find(c => c.path.endsWith('/registration-batches/review/')).data.student_ids).toEqual([
        f.students[0].id, f.students[2].id,
    ]);
    expect(f.calls.filter(c => c.data).every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.errors).toEqual([]);
});

for (const action of ['registration', 'billing']) {
    test(`${action}: a changed definition clears the old shortlist and denied handoff removes cached students`, async ({ page }) => {
        const f = await fixture(page);
        await page.getByRole('button', { name: `Choose students for ${action}`, exact: true }).click();
        const studio = page.locator('dialog.report-studio');
        await studio.getByRole('checkbox').first().check();
        f.version = 1;
        await studio.getByRole('button', { name: 'Next population page', exact: true }).click();
        await expect(studio.getByRole('status')).toContainText('previous selection was cleared');
        await expect(studio.getByRole('button', { name: `Review selected students for ${action}`, exact: true })).toBeDisabled();
        await studio.getByRole('checkbox').check();
        f.denied = true;
        await studio.getByRole('button', { name: `Review selected students for ${action}`, exact: true }).click();
        await expect(studio.getByRole('checkbox')).toHaveCount(0);
        await expect(studio.getByRole('button', { name: `Review selected students for ${action}`, exact: true })).toBeDisabled();
        await expect(studio.getByRole('region', { name: 'Selected report students' })).toContainText('0 of 25');
        await expect(studio).not.toContainText('Fictional <b>Student');
        expect(f.calls.some(c => c.path.includes('registration-batches'))).toBe(false);
        expect(f.calls.some(c => c.path.includes('billing-batches'))).toBe(false);
        expect(f.errors).toEqual([]);
    });
}

test('the population selector stops at 25 and a report-only recipient has no registration handoff', async ({ page }) => {
    const f = await fixture(page, { many: true });
    await page.getByRole('button', { name: 'Choose students for registration', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    const checks = studio.getByRole('checkbox');
    for (let i = 0; i < 25; i++) await checks.nth(i).check();
    await checks.nth(25).click();
    await expect(checks.nth(25)).not.toBeChecked();
    await expect(studio.getByRole('region', { name: 'Selected report students' })).toContainText('25 of 25');
    await expect(studio.getByRole('status')).toContainText('at most 25');
    await page.getByRole('button', { name: 'Back to reports', exact: true }).click();
    await fixture(page, { recipient: true });
    await expect(page.getByRole('button', { name: 'Choose students for registration', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Choose students for billing', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('a finance report recipient can select students and confirm through the existing billing review without registration duties', async ({ page }) => {
    const f = await fixture(page, { billingOnly: true });
    await expect(page.getByRole('button', { name: 'Choose students for registration', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Choose students for billing', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('checkbox', { name: /^Select S-1 / }).check();
    await studio.getByRole('button', { name: 'Next population page', exact: true }).click();
    await studio.getByRole('checkbox', { name: /^Select S-3 / }).check();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    await studio.getByRole('button', { name: 'Review selected students for billing', exact: true }).click();
    const drawer = page.locator('#record-drawer');
    await expect(drawer.getByRole('region', { name: 'Selected billing students' })).toContainText('2 selected students');
    await drawer.getByRole('button', { name: 'Back to report selection', exact: true }).click();
    await expect(studio.getByRole('checkbox', { name: /^Select S-3 / })).toBeChecked();
    await expect(studio.getByRole('region', { name: 'Selected report students' })).toContainText('2 of 25');
    await studio.getByRole('button', { name: 'Review selected students for billing', exact: true }).click();
    await drawer.getByLabel('Billing term').selectOption(f.term);
    await drawer.getByLabel('Reason for billing').fill('Fictional selected term fees.');
    await drawer.getByRole('button', { name: 'Review selected bills', exact: true }).click();
    await expect(drawer.getByRole('table', { name: 'Reviewed student charges' })).toContainText('S-1');
    await expect(drawer.getByRole('table', { name: 'Reviewed student charges' })).toContainText('S-3');
    expect(f.calls.filter(c => c.path === '/sis/billing-batches/')).toHaveLength(0);
    expect(f.calls.find(c => c.path === '/sis/billing-batches/review/').data).toEqual({
        term_id: f.term, students: [{ student_id: f.students[0].id }, { student_id: f.students[2].id }],
        reason: 'Fictional selected term fees.',
    });
    await accessible(page);
    await drawer.getByRole('button', { name: 'Confirm reviewed charges', exact: true }).click();
    await expect(drawer).toContainText('2 charges posted. 2 accounts opened.');
    expect(f.calls.find(c => c.path === '/sis/billing-batches/').data).toEqual({ review_token: 'fictional-billing-review' });
    expect(f.calls.filter(c => c.path.includes('/population/')).every(c => c.data.action === 'billing')).toBe(true);
    expect(f.calls.some(c => c.path.includes('registration-batches'))).toBe(false);
    expect(f.calls.filter(c => c.data).every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.errors).toEqual([]);
});

test('changed live billing membership clears the shortlist before any financial review', async ({ page }) => {
    const f = await fixture(page, { billingOnly: true });
    await page.getByRole('button', { name: 'Choose students for billing', exact: true }).click();
    const studio = page.locator('dialog.report-studio');
    await studio.getByRole('checkbox').first().check();
    f.conflict = true;
    await studio.getByRole('button', { name: 'Review selected students for billing', exact: true }).click();
    await expect(studio.getByRole('region', { name: 'Selected report students' })).toContainText('0 of 25');
    await expect(studio.getByRole('status')).toContainText('report or matching students changed');
    await expect(studio.getByRole('checkbox')).toHaveCount(0);
    await expect(studio.getByRole('button', { name: 'Review selected students for billing', exact: true })).toBeDisabled();
    expect(f.calls.some(c => c.path.includes('billing-batches'))).toBe(false);
    f.conflict = false;
    await studio.getByRole('button', { name: 'Refresh student population', exact: true }).click();
    await expect(studio.getByRole('checkbox').first()).not.toBeChecked();
    expect(f.errors).toEqual([]);
});
