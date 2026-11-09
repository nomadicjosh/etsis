const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

async function fixture(page, interrupted = false) {
    const id = suffix => `01K000000000000000000000${suffix}`;
    const student = id('91'), hiatus = id('92'), section = id('93'), graded = id('94');
    const sent = [], errors = [], amendments = new Map();
    let returned = null, financial = null;
    let saved = false, commits = 0, operation = 10, code = 'W', note = '', ends = null, voided = false, revision = 'a'.repeat(64), impact = null;
    const labels = { W: 'Withdrawal', SA: 'Study abroad', LOA: 'Leave of absence' };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) sent.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {}, status = 200;
        const value = { id: hiatus, student_id: student, code, label: labels[code], starts_on: '2026-11-01', ends_on: ends,
            state: voided ? 'voided' : 'active', voided, revision, comment: note, impact, return_operation_id: returned?.operation_id || null, created_at: '2026-11-07T12:00:00Z' };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/operation-id/') result = { id: id(String(operation++).padStart(2, '0')) };
        else if (path.startsWith('/sis/ui/data/')) result = { items: path.includes('/registrationstudents/') ? [{ id: student, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: 1 }] : [], next_after: null };
        else if (path.endsWith('/finance-review/')) { if (data) financial = { id: id('96'), status: 'open', office: 'Finance', assigned: false }; result = data ? financial : { review: financial }; }
        else if (path.endsWith('/return/review/')) result = { operation_id: id('95'), revision: 'e'.repeat(64),
            student: { id: student, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: false },
            primary_program: { code: 'BSCS', title: 'Computer Science' }, entry_term: { code: '26/FA' },
            hiatus_label: labels[code], hiatus_starts_on: '2026-11-01', hiatus_ends_on: '2026-11-06', return_on: data.return_on,
            staff_registration_blocked: true, self_registration_blocked: true };
        else if (path.endsWith('/return/commit/')) {
            returned = { operation_id: data.operation_id, hiatus_id: hiatus, result: { return_on: data.return_on, hiatus_ends_on: '2026-11-06', student_active: data.reactivate } };
            ends = '2026-11-06'; result = returned;
            amendments.set(data.operation_id, { id: data.operation_id, action: 'returned', actor_name: 'Taylor Example', created_at: '2026-11-07T13:00:00Z', note: data.note, previous_ends_on: null, new_ends_on: ends });
        } else if (path.includes('/returns/')) result = returned;
        else if (path.endsWith('/hiatus-workflow/review/')) result = {
            student: { id: student, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true },
            code: data.code, label: labels[data.code], starts_on: data.starts_on, ends_on: data.ends_on || null,
            effective_now: true, revision: 'f'.repeat(64), operation_id: hiatus, today: '2026-11-07', timezone: 'America/Los_Angeles',
            defaults: { deactivate: data.code === 'W', section_ids: data.code === 'W' ? [section] : [] },
            courses: [
                { section_id: section, section_code: 'MATH.101.A', course_title: 'Algebra', term_code: '26/FA', eligible: true, reason: '', proposed_status: 'W', drop_policy: { delete_records: false, grade_code: 'W' } },
                { section_id: graded, section_code: 'ENG.101.A', course_title: 'Writing', term_code: '26/FA', eligible: false, reason: 'Final grade posted — keep; use the grade-change review process.', proposed_status: 'W' },
            ],
        };
        else if (path.endsWith('/hiatus-workflow/commit/')) {
            ++commits; saved = true; code = data.code; note = data.note; ends = data.ends_on || null;
            impact = { course_changes: data.section_ids.map(section_id => ({ section_id, course_code: 'MATH.101', term_code: '26/FA', status: 'W', effective_date: data.starts_on, grade_code: 'W', academic_records_removed: false, course_charge: 'kept' })), student_active: !data.deactivate, student_activity_changed: data.deactivate, automatic_refund: false };
            if (interrupted && commits === 1) { status = 500; result = { error: 'Fictional interrupted response. Check the saved case.' }; }
            else result = { id: hiatus, impact };
        } else if (path === `/sis/students/${student}/hiatuses/`) {
            const { comment, impact: omitted, ...metadata } = value;
            result = { student: { id: student, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }, items: saved ? [metadata] : [], next_after: null, today: '2026-11-07', timezone: 'America/Los_Angeles' };
        } else if (path === `/sis/students/${student}/hiatuses/${hiatus}/`) {
            if (!saved) { status = 404; result = { error: 'Record not found.' }; } else result = value;
        } else if (path.endsWith('/history/')) result = { items: [...amendments.values(), { id: hiatus, action: 'created', actor_name: 'Taylor Example', created_at: '2026-11-07T12:00:00Z', note, previous_ends_on: null, new_ends_on: null }], next_after: null };
        else if (path.endsWith('/end/') || path.endsWith('/void/')) {
            if (!amendments.has(data.operation_id)) {
                if (data.revision !== revision) { status = 409; result = { error: 'This record changed. Reload it before trying again.' }; }
                else {
                    const prior = ends; if (path.endsWith('/end/')) ends = data.ends_on; else voided = true;
                    revision = path.endsWith('/end/') ? 'b'.repeat(64) : 'c'.repeat(64);
                    amendments.set(data.operation_id, { id: data.operation_id, action: path.endsWith('/end/') ? 'end_changed' : 'voided', actor_name: 'Taylor Example', created_at: '2026-11-07T13:00:00Z', note: data.note, previous_ends_on: prior, new_ends_on: ends });
                }
            }
        }
        await route.fulfill({ status, json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    return { student, hiatus, section, graded, sent, errors };
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
}
async function details(page, f, code = 'W — Withdrawal') {
    await page.goto('/admin/registration-fixture/');
    await expect(page.locator('.workspace-nav-link[data-workspace="students"]')).toHaveCount(0);
    await page.locator('.workspace-nav-link[data-workspace="registration"]').click();
    await page.getByRole('button', { name: 'Student hiatus history', exact: true }).click();
    await page.locator('#drawer-body').getByRole('combobox', { name: /^Student/ }).selectOption(f.student);
    await page.getByRole('button', { name: 'Open hiatus history', exact: true }).click();
    await page.getByRole('button', { name: 'Record a hiatus', exact: true }).click();
    await page.getByLabel('Hiatus type', { exact: false }).selectOption(code);
    await page.getByLabel('Effective start date').fill('2026-11-01');
    await page.getByLabel('Private staff comment').fill('Fictional sensitive comment <script>example</script>.');
    await page.getByRole('button', { name: 'Review hiatus plan', exact: true }).click();
}

test('registrar reviews proposed withdrawal effects and preserves encrypted amendment history', async ({ page }) => {
    const f = await fixture(page); await details(page, f);
    await expect(page.getByLabel('Make student record inactive now')).toBeChecked();
    await expect(page.getByLabel('Apply to MATH.101.A', { exact: true })).toBeChecked();
    await expect(page.getByLabel('Apply to ENG.101.A', { exact: true })).toBeDisabled();
    await expect(page.locator('#drawer-body')).toContainText('Early drops reverse their original course charges; late drops keep them');
    expect(f.sent.some(item => item.path.endsWith('/commit/'))).toBe(false);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Confirm hiatus plan', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('1 course changes recorded. Student record: Inactive.');
    expect(f.sent.find(item => item.path.endsWith('/hiatus-workflow/commit/'))).toMatchObject({ data: {
        section_ids: [f.section], deactivate: true, operation_id: f.hiatus, revision: 'f'.repeat(64), starts_on: '2026-11-01',
    }, csrf: 'fictional-test-token' });
    await page.getByRole('button', { name: 'Review saved hiatus', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Recorded hiatus effects', exact: true })).toContainText('Retained · grade W · course charge kept');
    await expect(page.locator('#drawer-body')).toContainText('<script>example</script>');
    await expect(page.locator('#drawer-body script')).toHaveCount(0);
    await page.getByRole('button', { name: 'Set or correct end date', exact: true }).click();
    await page.getByRole('textbox', { name: 'End date', exact: true }).fill('2026-11-30');
    await page.getByLabel('Reason for change').fill('Fictional return date correction');
    await accessible(page);
    await page.getByRole('button', { name: 'Save hiatus end date', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional return date correction');
    await page.getByRole('button', { name: 'Void this record', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('does not restore course enrollments or reactivate');
    await page.getByLabel('Reason for voiding').fill('Fictional duplicate correction');
    await page.getByRole('button', { name: 'Confirm hiatus void', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('voided');
    await expect(page.getByRole('button', { name: 'Void this record', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('changing the hiatus type preserves the draft and uses type-specific proposals', async ({ page }) => {
    const f = await fixture(page); await details(page, f);
    await page.getByRole('button', { name: 'Change details', exact: true }).click();
    await expect(page.getByLabel('Private staff comment')).toHaveValue(/Fictional sensitive comment/);
    await expect(page.getByLabel('Effective start date')).toHaveValue('2026-11-01');
    await page.getByLabel('Hiatus type', { exact: false }).selectOption('SA — Study abroad');
    await page.getByRole('button', { name: 'Review hiatus plan', exact: true }).click();
    await expect(page.getByLabel('Make student record inactive now')).not.toBeChecked();
    await expect(page.getByLabel('Apply to MATH.101.A', { exact: true })).not.toBeChecked();
    await page.getByRole('button', { name: 'Confirm hiatus plan', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('0 course changes recorded. Student record: Active.');
    expect(f.sent.find(item => item.path.endsWith('/hiatus-workflow/commit/'))?.data).toMatchObject({ code: 'SA', section_ids: [], deactivate: false });
    expect(f.errors).toEqual([]);
});

test('a saved case can be recovered after an interrupted confirmation without repeating changes', async ({ page }) => {
    const f = await fixture(page, true); await details(page, f);
    await page.getByRole('button', { name: 'Confirm hiatus plan', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('Fictional interrupted response');
    await page.getByRole('button', { name: 'Check saved case', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Recorded hiatus effects', exact: true })).toContainText('Retained · grade W · course charge kept');
    expect(f.sent.filter(item => item.path.endsWith('/hiatus-workflow/commit/'))).toHaveLength(1);
    expect(f.errors).toEqual([]);
});


test('return review shows hold effects and requires explicit reactivation without restoring old enrollments', async ({ page }) => {
    const f = await fixture(page); await details(page, f);
    await page.getByRole('button', { name: 'Confirm hiatus plan', exact: true }).click();
    await page.getByRole('button', { name: 'Review saved hiatus', exact: true }).click();
    await page.getByRole('button', { name: 'Review return / readmission', exact: true }).click();
    await page.getByLabel('Return effective date').fill('2026-11-07');
    await page.getByLabel('Reason for return decision').fill('Fictional registrar return decision');
    await page.getByRole('button', { name: 'Review return plan', exact: true }).click();
    await expect(page.getByLabel('Reactivate student record now')).not.toBeChecked();
    await expect(page.locator('#drawer-body')).toContainText('staff registration blocked');
    await expect(page.locator('#drawer-body')).toContainText('Primary program: BSCS');
    expect(f.sent.some(item => item.path.endsWith('/return/commit/'))).toBe(false);
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.getByLabel('Reactivate student record now').check();
    await page.getByRole('button', { name: 'Confirm return decision', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Student record: Active.');
    await expect(page.locator('#drawer-body')).toContainText('Earlier enrollments, holds and charges remain unchanged');
    expect(f.sent.find(item => item.path.endsWith('/return/commit/'))).toMatchObject({ data: { reactivate: true, return_on: '2026-11-07', revision: 'e'.repeat(64) }, csrf: 'fictional-test-token' });
    await page.getByRole('button', { name: 'Review saved hiatus', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review return / readmission', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'View recorded return', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Return effective date: 2026-11-07');
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});


test('registrar requests a finance hand-off and sees progress without account data or finance notes', async ({ page }) => {
    const f = await fixture(page); await details(page, f);
    await page.getByRole('button', { name: 'Confirm hiatus plan', exact: true }).click();
    await page.getByRole('button', { name: 'Review saved hiatus', exact: true }).click();
    await page.getByRole('button', { name: 'Request finance review', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Finance hand-off', exact: true })).toContainText('Status: open');
    await expect(page.getByRole('button', { name: 'Request finance review', exact: true })).toHaveCount(0);
    expect(f.sent.filter(item => item.path.endsWith('/finance-review/'))).toHaveLength(1);
    expect(f.sent.find(item => item.path.endsWith('/finance-review/')).data).toEqual({});
    expect(f.sent.some(item => item.path.startsWith('/sis/finance-reviews/'))).toBe(false);
    await accessible(page);
});
