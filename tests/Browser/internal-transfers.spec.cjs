'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
const student = id(1), assignment = id(2), course = id(4), publication = id(5), sourceId = id(6), award = id(7), term = id(10);
const root = `/sis/students/${student}/programs/${assignment}/internal-transfers/`;
const source = { source_credit_id: sourceId, source_pursuit_id: id(3), source_credential_id: id(9),
    course_id: course, course_code: 'HIST.201', course_title: 'Original <b>history</b>', credits_milli: 3000,
    completed_on: '2026-08-01', source_program_code: 'ARTS.UG', source_program_title: 'Fictional original arts',
    credential_title: 'Bachelor of Arts', credential_awarded_on: '2026-08-10', grade: { code: 'B' } };
const receiving = { student_id: student, assignment_id: assignment, program_id: id(12), program_code: 'NEXT.UG',
    program_title: 'Fictional receiving degree', status: 'active', identity: { name: 'Fictional Learner', institutional_id: 'S-FICTIONAL' } };
async function fixture(page, { stale = false } = {}) {
    const state = { calls: [], errors: [], granted: false, voided: false, units: 3000, grade: 'TR', stale };
    const grades = [{ code: 'TR', counts_gpa: false }, { code: 'A', counts_gpa: true }];
    const record = () => ({ id: award, source, completed_on: source.completed_on, term_code: 'REC.26', course_version_id: publication,
        effective_on_default: '2026-08-10', academic_on: null, retained_credits_milli: state.units, retained_grade_code: state.grade, grades, credit: { id: id(8), course_id: course, course_code: 'REC.301',
            course_title: 'Receiving seminar', completed_milli: state.voided ? 0 : state.units, attempted_milli: state.voided ? 0 : state.units,
            grade_code: state.voided ? null : state.grade, term_id: term, status: state.voided ? 'V' : 'A', version: 0 } });
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const r = route.request(), path = new URL(r.url()).pathname;
        const data = r.postData() ? JSON.parse(new URLSearchParams(r.postData()).get('_workspace_json') || '{}') : {};
        state.calls.push({ path, data, csrf: r.headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/students/') result.items = [{ id: student, person_id: id(13), given_name: 'Fictional', family_name: 'Learner', institutional_id: 'S-FICTIONAL', active: true }];
        else if (path === '/sis/ui/data/terms/') result.items = [{ id: term, code: 'REC.26' }, { id: id(11), code: 'REC.27' }];
        else if (path.endsWith('/record/')) result = { id: student, person_id: id(13), given_name: 'Fictional', family_name: 'Learner', institutional_id: 'S-FICTIONAL', active: true,
            primary_program: { id: id(12), code: 'NEXT.UG', title: 'Fictional receiving degree' }, entry_term: { code: 'REC.26' }, primary_program_status: 'active', revision: 'fictional' };
        else if (path === `/sis/students/${student}/programs/`) result.items = [{ id: assignment, program_code: 'NEXT.UG', program_title: 'Fictional receiving degree', term_code: 'REC.26', status: 'active', is_primary: true }];
        else if (path === `/sis/students/${student}/transcript/`) result = { credits: [{ id: id(8), course_code: 'REC.301', course_title: 'Receiving seminar', term_code: 'REC.26', grade_code: 'TR', credit_type: 'transfer', source_id: award }] };
        else if (path === `/sis/students/${student}/transfer-awards/${award}/`) result = { ...record(), source_kind: 'same_institution', receiving_assignment_id: assignment };
        else if (path === root + 'page/') result = { items: state.granted ? [record()] : [], receiving, next_after: null };
        else if (path === root + 'candidates/') result = { items: state.granted ? [] : [source], receiving, grades, next_after: null };
        else if (path === root + `courses/${course}/publications/`) result = { items: [{ version_id: publication, version: 3, code: 'REC.301', title: 'Receiving seminar', credits_milli: 3000 }, { version_id: id(21), version: 4, code: 'REC.399', title: 'Corrected receiving seminar', credits_milli: 3000 }], next_cursor: null };
        else if (path === root + 'review/') result = { operation_id: id(20), action: data.action, request: data.request, reason: data.reason, revision: 'a'.repeat(64), review_token: 'fictional-receiving-proof',
            summary: { receiving, source, course: { course_id: course, version_id: publication, definition: { code: 'REC.301', title: 'Receiving seminar' } },
                credits_milli: data.request.credits_milli ?? null, grade_code: data.request.grade_code ?? null,
                counts_gpa: data.request.grade_code === 'A', term: { id: term, code: 'REC.26' } } };
        else if (path === root + 'confirm/') {
            if (state.stale) return route.fulfill({ status: 409, json: { error: 'The original evidence changed. Review the current records again.' } });
            state.granted = true; state.voided = data.action === 'void';
            if (data.request.credits_milli) state.units = data.request.credits_milli;
            if (data.request.grade_code) state.grade = data.request.grade_code;
            result = { award_id: award, operation_id: id(20), status: 'recorded' };
        } else if (path === root + award + '/') result = record();
        else if (path === root + award + '/history/') result = { items: [{ action: 'grant', reason: 'Fictional original receiving decision.', recorded_at: '2026-08-10T10:00:00Z', credits_milli: state.units, grade_code: state.grade }], next_cursor: null };
        else if (path.endsWith('/accepted-course/review/')) result = { before: { course_code: 'REC.301' }, after: { course_code: 'REC.399' }, effective_on: data.effective_on, reason: data.reason, review_token: 'fictional-course-proof' };
        else if (path.endsWith('/accepted-course/commit/')) result = { status: 'recorded' };
        else if (path.endsWith('/source-period/review/')) result = { review_token: 'fictional-posting-proof' };
        else if (path.endsWith('/source-period/commit/')) result = { status: 'recorded' };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    return state;
}
async function open(page) {
    await page.goto('/admin/workspace/students/');
    await page.getByLabel('Search this workspace', { exact: true }).fill('Fictional');
    await page.getByLabel('Search this workspace', { exact: true }).press('Enter');
    await page.getByRole('button', { name: 'Fictional Learner', exact: true }).click();
    await recordAction(page, 'Same-institution transfer evaluation');
}
async function evaluate(page) {
    await page.getByRole('button', { name: 'Evaluate completed-degree work', exact: true }).click();
    await page.getByRole('button', { name: 'Evaluate completed-degree work', exact: true }).click();
    await page.getByLabel('Published receiving definition', { exact: false }).selectOption(publication);
    await page.getByRole('combobox', { name: 'Posting term', exact: true }).selectOption(term);
    await page.getByLabel('Receiving grade', { exact: false }).selectOption('TR');
    await page.getByLabel('Evaluation reason', { exact: false }).fill('Fictional registrar review of original degree work.');
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).include('#record-drawer').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.locator('#drawer-body').evaluate(e => e.scrollWidth <= e.clientWidth + 1)).toBe(true);
}
test('registrar reviews original evidence and a separate receiving award with preserved drafts', async ({ page }) => {
    const state = await fixture(page); await open(page); await evaluate(page);
    await page.getByRole('button', { name: 'Review evaluation', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Original <b>history</b>');
    await expect(page.locator('#drawer-body b')).toHaveCount(0);
    await expect(page.locator('#drawer-body')).toContainText('Grade excludes GPA');
    await page.getByRole('button', { name: 'Revise evaluation', exact: true }).click();
    await expect(page.getByLabel('Evaluation reason', { exact: false })).toHaveValue('Fictional registrar review of original degree work.');
    await expect(page.getByLabel('Published receiving definition', { exact: false })).toHaveValue(publication);
    await page.getByRole('button', { name: 'Review evaluation', exact: true }).click();
    await page.setViewportSize({ width: 320, height: 900 }); await accessible(page);
    await page.getByRole('button', { name: 'Confirm evaluation', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('REC.301');
    const call = state.calls.find(c => c.path === root + 'confirm/');
    expect(call.csrf).toBe('fictional-test-token'); expect(call.data.request.credits_milli).toBe(3000);
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Student programs', exact: true })).toBeVisible();
    expect(state.errors).toEqual([]);
});
test('a stale source leaves the review editable and cannot appear accepted', async ({ page }) => {
    const state = await fixture(page, { stale: true }); await open(page); await evaluate(page);
    await page.getByRole('button', { name: 'Review evaluation', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm evaluation', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('The original evidence changed.');
    expect(state.granted).toBe(false);
    await page.getByRole('button', { name: 'Revise evaluation', exact: true }).click();
    await expect(page.getByLabel('Receiving grade', { exact: false })).toHaveValue('TR');
    expect(state.errors).toEqual([]);
});
test('void and reinstatement keep source evidence and allow a corrected receiving amount', async ({ page }) => {
    const state = await fixture(page); state.granted = true; await open(page);
    await page.getByRole('button', { name: 'View evaluation', exact: true }).click();
    await page.getByRole('button', { name: 'Void receiving award', exact: true }).click();
    await page.getByLabel('Evaluation reason', { exact: false }).fill('Fictional reason for void.');
    await page.getByRole('button', { name: 'Review evaluation', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm evaluation', exact: true }).click();
    await page.getByRole('button', { name: 'Reinstate receiving award', exact: true }).click();
    await page.getByLabel('Academic credits to accept', { exact: false }).fill('2');
    await page.getByLabel('Receiving grade', { exact: false }).selectOption('TR');
    await page.getByLabel('Evaluation reason', { exact: false }).fill('Fictional receiving reinstatement.');
    await page.getByRole('button', { name: 'Review evaluation', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm evaluation', exact: true }).click();
    expect(state.units).toBe(2000); expect(state.voided).toBe(false);
    await expect(page.locator('#drawer-body')).toContainText('Bachelor of Arts');
    expect(await page.evaluate(() => Object.keys(localStorage).filter(k => /internal-transfer/i.test(k)))).toEqual([]);
    expect(state.errors).toEqual([]);
});
test('posting-term correction keeps the original completion read-only', async ({ page }) => {
    const state = await fixture(page); state.granted = true; await open(page);
    await page.getByRole('button', { name: 'View evaluation', exact: true }).click();
    await page.getByRole('button', { name: 'Correct posting term', exact: true }).click();
    await page.getByRole('combobox', { name: 'Posting term', exact: true }).selectOption(id(11));
    await page.getByLabel('Evaluation reason', { exact: false }).fill('Fictional posting attribution correction.');
    await expect(page.getByLabel('External completion date')).toHaveCount(0);
    await page.setViewportSize({ width: 320, height: 900 }); await accessible(page);
    await page.getByRole('button', { name: 'Review posting term', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm posting term', exact: true }).click();
    expect(state.calls.find(c => c.path.endsWith('/source-period/review/')).data.completed_on).toBe('2026-08-01');
    expect(state.errors).toEqual([]);
});

test('receiving-course correction uses a published definition and preserves the original evidence', async ({ page }) => {
    const state = await fixture(page); state.granted = true; await open(page);
    await page.getByRole('button', { name: 'View evaluation', exact: true }).click();
    await page.getByRole('button', { name: 'Correct receiving course', exact: true }).click();
    await page.getByRole('combobox', { name: 'Published receiving definition', exact: true }).selectOption(id(21));
    await page.getByLabel('Evaluation reason', { exact: false }).fill('Fictional receiving publication correction.');
    await page.getByRole('button', { name: 'Review receiving course', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('REC.301 → REC.399');
    await page.getByRole('button', { name: 'Confirm receiving course', exact: true }).click();
    const call = state.calls.find(c => c.path.endsWith('/accepted-course/review/'));
    expect(call.data.course_version_id).toBe(id(21)); expect(call.csrf).toBe('fictional-test-token');
    await expect(page.locator('#drawer-body')).toContainText('Original <b>history</b>');
    expect(state.errors).toEqual([]);
});

test('the transcript drilldown opens the receiving case rather than an external equivalency drawer', async ({ page }) => {
    const state = await fixture(page); state.granted = true;
    await page.goto('/admin/workspace/students/');
    await page.getByLabel('Search this workspace', { exact: true }).fill('Fictional');
    await page.getByLabel('Search this workspace', { exact: true }).press('Enter');
    await page.getByRole('button', { name: 'Fictional Learner', exact: true }).click();
    await page.getByRole('button', { name: 'View transcript', exact: true }).click();
    await page.getByRole('button', { name: 'Review transfer award', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Same-institution transfer evaluation', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Back to equivalency', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Correct receiving units or grade', exact: true })).toBeVisible();
    expect(state.errors).toEqual([]);
});
