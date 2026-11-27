const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
const section = { id: id(1), code: 'SCI.101.A', section_code: 'SCI.101.A', course_id: id(2), term_id: id(3),
    course_code: 'SCI.101', course_title: 'Fictional science', title: 'Fictional science', term_code: '26/FA',
    credits_milli: 3000, capacity: 20, active: 1, enrolled: 0, web_registration: 1, fee_minor: 10000,
    currency: 'USD', auditing: { fee_minor: 2500, self_service: true } };
const student = { id: id(4), person_id: id(5), given_name: 'Fictional', family_name: 'Student',
    institutional_id: 'S-FICTIONAL', primary_program_id: id(6), active: true, enrollments: [] };

async function fixture(page) {
    const state = { policy: null, revision: 'a'.repeat(64), failed: false, calls: [], errors: [] };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route(/\/(sis|portal\/api)\//, async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        const raw = request.postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json') || '{}') : {};
        state.calls.push({ path, data, csrf: request.headers()['x-csrf-token'] });
        let result = { items: [], next_after: null }, status = 200;
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = {
            items: path.includes('/students/') ? [student] : path.includes('/sections/') ? [section] : [], next_after: null,
        };
        else if (path.endsWith('/record/')) result = { ...student,
            primary_program: { code: 'SCI.UG', title: 'Fictional science' }, entry_term: { code: '26/FA' } };
        else if (path.endsWith('/auditing/current/')) result = {
            policy: state.policy, revision: state.revision, section_code: section.code, currency: 'USD',
        };
        else if (path.endsWith('/auditing/save/')) {
            if (state.failed) return route.fulfill({ status: 409, json: { error: 'Fictional stale policy.' } });
            state.policy = data.allowed ? { fee_minor: data.fee_minor, self_service: data.self_service } : null;
            state.revision = 'b'.repeat(64);
            result = { policy: state.policy, revision: state.revision };
        } else if (path === '/portal/api/me/') result = { person: student, students: [student],
            applications: [], accounts: [], news: [], restrictions: [], transcript_requests: [] };
        else if (path === '/portal/api/courses/') result = { items: [section], next_after: null };
        else if (path === '/sis/registrations/review/' || path === '/portal/api/registration/review/') result = {
            basis: data.basis, by_staff: data.by_staff === true,
            operation_id: id(9), revision: 'c'.repeat(64), review_token: 'fictional-audit-proof',
            section: { ...section, seats_remaining: 20, prerequisite_courses: [],
                term_starts_on: '2026-08-01', term_ends_on: '2026-12-20' },
            students: [{ ...student, enrollment_id: null, issues: [], readiness: [],
                prerequisites: { status: 'not_required', missing_count: 0, course_statuses: [] },
                schedule: { status: 'clear', blocked: false, conflict_count: 0, unpublished_count: 0, conflicts: [] },
                charges: { currency: 'USD', total_minor: 2500, problems: [],
                    items: [{ name: 'Section fee', basis: 'section', minor: 2500, currency: 'USD', status: 'new' }] } }],
        };
        else if (path === '/sis/registrations/' || path === '/portal/api/register/') {
            status = 201; result = { id: id(10), status: 'N', registrations: [{ basis: 'audit' }] };
        }
        await route.fulfill({ status, json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    return state;
}

test('audit policy uses an explicit zero, saves before publication and returns to its section', async ({ page }) => {
    const state = await fixture(page);
    await page.goto('/admin/workspace/sections/');
    await page.getByRole('button', { name: section.code, exact: true }).click();
    await page.getByRole('button', { name: 'Course auditing', exact: true }).click();
    await page.getByLabel('Allow auditing for this section', { exact: true }).check();
    await page.getByLabel('Allow students to request an audit seat', { exact: true }).check();
    await page.getByLabel('Audit section fee (USD)', { exact: true }).fill('0.00');
    await expect(page.getByRole('button', { name: 'Review offering publication', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Save audit rules', exact: true }).click();
    await expect(page.getByText('Audit rules saved.', { exact: false })).toBeVisible();
    expect(state.policy).toEqual({ fee_minor: 0, self_service: true });
    await expect(page.getByRole('button', { name: 'Review offering publication', exact: true })).toBeEnabled();
    await page.setViewportSize({ width: 320, height: 900 });
    expect(await page.locator('#drawer-body').evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    const scan = await new AxeBuilder({ page }).include('#record-drawer')
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations).toEqual([]);
    await page.screenshot({ path: '/tmp/etsis-auditing-policy-phone.png' });
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Published section details', exact: true })).toBeVisible();
    expect(state.calls.find(call => call.path.endsWith('/auditing/save/')).csrf).toBe('fictional-test-token');
    expect(state.errors).toEqual([]);
});

test('stale policy save keeps choices and does not enable publication of unsaved changes', async ({ page }) => {
    const state = await fixture(page);
    await page.goto('/admin/workspace/sections/');
    await page.getByRole('button', { name: section.code, exact: true }).click();
    await page.getByRole('button', { name: 'Course auditing', exact: true }).click();
    await page.getByLabel('Allow auditing for this section', { exact: true }).check();
    await page.getByLabel('Audit section fee (USD)', { exact: true }).fill('12.34');
    state.failed = true;
    await page.getByRole('button', { name: 'Save audit rules', exact: true }).click();
    await expect(page.getByText('Fictional stale policy.', { exact: false }).first()).toBeVisible();
    await expect(page.getByLabel('Allow auditing for this section', { exact: true })).toBeChecked();
    await expect(page.getByLabel('Audit section fee (USD)', { exact: true })).toHaveValue('12.34');
    await expect(page.getByRole('button', { name: 'Review offering publication', exact: true })).toBeDisabled();
    expect(state.policy).toBeNull();
    expect(state.errors).toEqual([]);
});

test('staff reviews audit basis and charges before confirming the same request', async ({ page }) => {
    const state = await fixture(page);
    await page.goto('/admin/registration-plan-fixture/');
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator('#directory-search').fill('Fictional');
    await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Fictional Student', exact: true }).click();
    await page.getByRole('button', { name: 'Register for a section', exact: true }).click();
    await page.getByRole('combobox', { name: 'Course section', exact: true }).selectOption(section.id);
    await page.getByLabel('Enrollment basis', { exact: true }).selectOption('audit');
    await page.getByRole('button', { name: 'Review registration', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('AU is an audit designation');
    await expect(page.locator('#drawer-body')).toContainText('0 credits');
    await page.getByRole('button', { name: 'Confirm registration', exact: true }).click();
    await expect.poll(() => state.calls.filter(call => call.path === '/sis/registrations/').length).toBe(1);
    expect(state.calls.find(call => call.path === '/sis/registrations/').data).toMatchObject({
        basis: 'audit', review_token: 'fictional-audit-proof', credit_choices: {},
    });
    expect(state.errors).toEqual([]);
});

test('student chooses auditing only when offered and confirms zero credits with the audit fee', async ({ page }) => {
    const state = await fixture(page);
    await page.goto('/portal/student/registration/');
    await page.getByRole('button', { name: 'Review & register', exact: true }).click();
    await page.getByLabel('Enrollment basis', { exact: true }).selectOption('audit');
    await expect(page.locator('#campus-dialog-body')).toContainText('AU is an audit designation');
    await expect(page.locator('#campus-dialog-body')).toContainText('0 credits');
    await page.getByRole('button', { name: 'Confirm registration', exact: true }).click();
    await expect.poll(() => state.calls.filter(call => call.path === '/portal/api/register/').length).toBe(1);
    expect(state.calls.find(call => call.path === '/portal/api/register/').data).toMatchObject({
        basis: 'audit', credits_milli: null, review_token: 'fictional-audit-proof',
    });
    expect(state.errors).toEqual([]);
});
