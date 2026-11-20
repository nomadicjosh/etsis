const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
const student = id(1), section = id(2), enrollment = id(3), operation = id(4), term = id(5);
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}
async function fixture(page, late = false) {
    const sent = [], errors = []; let committed = false;
    page.on('pageerror', error => errors.push(error.message));
    const plan = { student_id: student, section_id: section, enrollment_id: enrollment, version: 0,
        operation_id: operation, revision: 'a'.repeat(64), effective_on: '2026-08-10',
        section_code: 'MATH.101.A', given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL',
        outcome: { delete_records: !late, grade_code: late ? 'D' : null, counts_gpa: late, term_code: '26/FA', grace_ends_on: '2026-08-15' } };
    const receipt = { ...plan, academic_records_removed: !late, grade_code: late ? 'D' : null, course_charge: late ? 'kept' : 'reversed', cash_refund_issued: false };
    await page.route(/\/(?:sis|portal\/api)\//, async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData(); const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) sent.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {}, status = 200;
        if (path.includes('/drop-plans/') || path.includes('/drop-plan/')) {
            const second = id(6);
            if (path.endsWith('/choices/')) result = { items: [{ id: data.after ? second : section,
                code: data.after ? 'CHEM.101L.A' : 'CHEM.101.A', course_code: data.after ? 'CHEM.101L' : 'CHEM.101',
                course_title: 'Fictional <b>course</b>', term_code: '26/FA' }], next_after: data.after ? null : id(7) };
            else if (path.endsWith('/review/')) result = { operation_id: operation, revision: 'a'.repeat(64),
                review_token: 'fictional-drop-plan-proof', student: { given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }, courses: [
                    { ...plan, section_code: 'CHEM.101.A', outcome: { ...plan.outcome, delete_records: true } },
                    { ...plan, section_id: second, section_code: 'CHEM.101L.A', outcome: { ...plan.outcome, delete_records: false, grade_code: 'W' } },
                ] };
            else if (path.endsWith('/commit/')) { status = 500; result = { error: 'Fictional interrupted confirmation. Check saved drops.' }; }
            else result = { drop_plan_id: operation, removed: 1, withdrawn: 1, drops: [] };
        }
        else if (path.endsWith('/drop-review/')) result = plan;
        else if (path === '/sis/registrations/drop/' || path === '/portal/api/drop/') {
            committed = true; status = 500; result = { error: 'Fictional interrupted connection. Check saved drop.' };
        } else if (path.includes('/course-drops/') || path.includes('/drop-receipts/')) result = receipt;
        else if (path.endsWith('/drop-policy/review/')) result = { definition: { grace_ends_on: '2026-08-15', timezone: 'America/Los_Angeles', windows: data.windows }, review_token: 'fictional-review' };
        else if (path.endsWith('/drop-policy/commit/')) result = { publication_id: operation, version: 0 };
        else if (path.endsWith('/drop-policy/')) result = data ? { status: 'saved' } : { term: { code: '26/FA', add_drop_ends_on: '2026-08-15' }, version: -1, default_policy: true, windows: [{ through: null, grade_code: 'W' }] };
        else if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/students/') result = { items: [{ id: student, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: 1 }], next_after: null };
        else if (path === '/sis/ui/data/terms/') result = { items: [{ id: term, code: '26/FA', starts_on: '2026-08-01', ends_on: '2026-12-20', add_drop_ends_on: '2026-08-15' }], next_after: null };
        else if (path === `/sis/students/${student}/record/`) result = { id: student, given_name: 'Alex', family_name: 'Example', active: true, institutional_id: 'S-FICTIONAL', primary_program: { code: 'BS.CS', title: 'Computing' }, entry_term: { code: '26/FA' } };
        else if (path === `/sis/ui/context/enrollments/${student}/`) result = { items: committed && !late ? [] : [{ id: enrollment, course_code: 'MATH.101', section_code: 'MATH.101.A', section_id: section, status: committed ? 'W' : 'N' }], next_after: null };
        else if (path.startsWith('/sis/')) result = { items: [], next_after: null };
        else if (path === '/portal/api/me/') result = { person: { given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }, students: [{ id: student, enrollments: committed && !late ? [] : [{ section_id: section, course_code: 'MATH.101', section_code: 'MATH.101.A', status: committed ? 'W' : 'N' }] }], applications: [], restrictions: [], accounts: [], news: [], transcript_requests: [] };
        else if (path === '/portal/api/courses/') result = { items: [], next_after: null };
        await route.fulfill({ status, json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    return { sent, errors, plan };
}

test('registrar reviews early removal and recovers a committed drop without another write', async ({ page }) => {
    const f = await fixture(page);
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator('#directory-search').fill('a');
    await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await page.getByRole('button', { name: 'Drop / withdraw', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('original course charge');
    await expect(page.locator('#drawer-body')).toContainText('Alex Example');
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.getByRole('button', { name: 'Confirm course drop' }).click();
    await expect(page.locator('#drawer-error')).toContainText('interrupted');
    await page.getByRole('button', { name: 'Check saved drop' }).click();
    await expect(page.locator('#workspace-status')).toContainText('Course removed');
    expect(f.sent.filter(r => r.path === '/sis/registrations/drop/')).toHaveLength(1);
    expect(f.sent.find(r => r.path === '/sis/registrations/drop/')).toMatchObject({ csrf: 'fictional-test-token', data: { enrollment_id: enrollment, operation_id: operation, revision: 'a'.repeat(64), effective_on: '2026-08-10', version: 0 } });
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('catalog staff build dated grade periods in a responsive term policy editor', async ({ page }) => {
    const f = await fixture(page);
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole('link', { name: 'Terms', exact: true }).click();
    await page.getByRole('button', { name: '26/FA', exact: true }).click();
    await page.getByRole('button', { name: 'Drop / withdrawal policy' }).click();
    await page.getByRole('button', { name: 'Add grade period' }).click();
    await page.getByLabel('Through date (inclusive)').fill('2026-09-01');
    await page.getByLabel('Catalog grade code').first().fill('D');
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.getByLabel('Reason for policy change').fill('Fictional institutional approval');
    await page.getByRole('button', { name: 'Review policy', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Completed drops');
    await page.getByRole('button', { name: 'Publish policy', exact: true }).click();
    await expect(page.locator('#workspace-status')).toContainText('Term drop policy published');
    expect(f.sent.find(r => r.path.endsWith('/drop-policy/review/'))?.data).toEqual({ reason: 'Fictional institutional approval', version: -1, windows: [{ through: '2026-09-01', grade_code: 'D' }, { through: null, grade_code: 'W' }] });
    expect(f.errors).toEqual([]);
});

for (const late of [false, true]) test(`student reviews ${late ? 'late D with retained charge' : 'early reversal'} and recovers an interrupted drop`, async ({ page }) => {
    const f = await fixture(page, late);
    await page.goto('/portal/student/registration/');
    await page.getByRole('button', { name: 'Drop / withdraw', exact: true }).click();
    await expect(page.locator('#campus-dialog')).toContainText(late ? 'grade D' : 'cash refunds require a separate review');
    if (late) await expect(page.locator('#campus-dialog')).toContainText('course charge stays payable');
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.getByRole('button', { name: 'Confirm drop / withdrawal', exact: true }).click();
    await expect(page.locator('#campus-dialog-error')).toContainText('interrupted');
    await page.getByRole('button', { name: 'Check saved drop' }).click();
    await expect(page.locator('#campus-notice')).toContainText(late ? 'grade D' : 'course was removed');
    expect(f.sent.filter(r => r.path === '/portal/api/drop/')).toHaveLength(1);
    expect(f.sent.every(r => r.csrf === 'fictional-test-token')).toBe(true);
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});


for (const owner of [false, true]) test(`${owner ? 'student' : 'staff'} selects courses across pages and confirms one accessible drop plan`, async ({ page }) => {
    const f = await fixture(page);
    if (owner) await page.goto('/portal/student/registration/');
    else {
        await page.goto('/admin/');
        await page.locator('.workspace-nav-link[data-workspace="students"]').click();
        await page.locator('#directory-search').fill('a'); await page.locator('#directory-search').press('Enter');
        await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    }
    await page.getByRole('button', { name: 'Drop selected courses', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review selected drops', exact: true })).toBeDisabled();
    const first = page.getByRole('checkbox', { name: /CHEM.101 ·/ });
    await first.check(); await expect(first).toBeFocused();
    await page.getByRole('button', { name: 'More courses', exact: true }).click();
    await page.getByRole('checkbox', { name: /CHEM.101L ·/ }).check();
    await page.getByRole('button', { name: 'Review selected drops', exact: true }).click();
    const body = page.locator(owner ? '#campus-dialog-body' : '#drawer-body');
    await expect(body).toContainText('Reverse the original course charge');
    await expect(body).toContainText('grade W');
    await expect(body.locator('b')).toHaveCount(0);
    await page.getByRole('button', { name: 'Change selection', exact: true }).click();
    await expect(page.getByRole('checkbox', { name: /CHEM.101 ·/ })).toBeChecked();
    await expect(body).toContainText('2 of 10 courses selected');
    await page.getByRole('button', { name: 'Review selected drops', exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.getByRole('button', { name: 'Confirm selected drops', exact: true }).click();
    await expect(page.locator(owner ? '#campus-dialog-error' : '#drawer-error')).toContainText('interrupted');
    await page.getByRole('button', { name: 'Check saved drops', exact: true }).click();
    await expect(body).toContainText('1 removed during drop/add; 1 withdrawn');
    const commit = f.sent.filter(call => call.path.endsWith('/commit/'));
    expect(commit).toHaveLength(1);
    expect(commit[0].data).toMatchObject({ student_id: student, section_ids: [section, id(6)], review_token: 'fictional-drop-plan-proof' });
    expect(commit[0].csrf).toBe('fictional-test-token');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});


test('a pending drop review cannot replace later choices or enable an empty selection', async ({ page }) => {
    await fixture(page);
    let release, seen;
    const blocked = new Promise(resolve => { release = resolve; });
    const requested = new Promise(resolve => { seen = resolve; });
    await page.route('**/sis/drop-plans/review/', async route => { seen(); await blocked; await route.fallback(); });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator('#directory-search').fill('a'); await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await page.getByRole('button', { name: 'Drop selected courses', exact: true }).click();
    await page.getByRole('checkbox', { name: /CHEM.101 ·/ }).check();
    await page.getByRole('button', { name: 'Review selected drops', exact: true }).click();
    await requested;
    await page.getByRole('checkbox', { name: /CHEM.101 ·/ }).uncheck();
    release();
    await expect(page.getByRole('button', { name: 'Review selected drops', exact: true })).toBeDisabled();
    await expect(page.locator('#drawer-body')).toContainText('Your selection changed');
    await expect(page.getByRole('button', { name: 'Confirm selected drops', exact: true })).toHaveCount(0);
});
