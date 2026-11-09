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
        if (path.endsWith('/drop-review/')) result = plan;
        else if (path === '/sis/registrations/drop/' || path === '/portal/api/drop/') {
            committed = true; status = 500; result = { error: 'Fictional interrupted connection. Check saved drop.' };
        } else if (path.includes('/course-drops/') || path.includes('/drop-receipts/')) result = receipt;
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
    await page.getByRole('button', { name: 'Save drop policy' }).click();
    await expect(page.locator('#workspace-status')).toContainText('Term drop policy saved');
    expect(f.sent.find(r => r.path.endsWith('/drop-policy/'))?.data).toEqual({ version: -1, windows: [{ through: '2026-09-01', grade_code: 'D' }, { through: null, grade_code: 'W' }] });
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
