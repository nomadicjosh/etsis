const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
async function fixture(page, kind = 'course', { denied = false, outcome = 'verified', office = false, delay = null } = {}) {
    const calls = [], errors = [], receipts = [];
    const course = { id: id(1), code: 'MATH-101', title: 'Fictional Algebra', credits_milli: 3000, prerequisites: [], active: true };
    const section = { id: id(2), code: 'MATH-101-A', course_id: course.id, term_id: id(3), capacity: 20,
        active: true, web_registration: true, fee_minor: 0, currency: 'USD', delivery_mode: 'in_person',
        faculty_user_id: null, faculty_person_id: null };
    const definition = { ...section, course_version_id: id(4), term_code: '26/FA', term_starts_on: '2026-08-01',
        term_ends_on: '2026-12-20', instruction_starts_on: '2026-08-24', instruction_ends_on: '2026-12-18' };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let json = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') json = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) json = { items: path.includes('/courses/') ? [course] :
            path.includes('/sections/') ? [section] : [], next_after: null };
        else if (path.endsWith('/publication/setup/')) json = { section, term: { id: id(3), code: '26/FA',
            starts_on: '2026-08-01', ends_on: '2026-12-20' }, course_definition: { version_id: id(4), definition: course },
            latest: null, selected_offering: null, has_student_records: false, publication_status: 'unpublished',
            faculty_label: 'Not assigned', moodle: { available: false, linked: false, request_state: null } };
        else if (path.endsWith('/publication/page/')) json = {
            items: [{ version: data.cursor ? 21 : 1, version_id: id(4), definition: kind === 'course' ? course : definition,
                effective_at: '2026-11-24T12:00:00Z', recorded_at: '2026-11-24T12:00:01Z' }],
            next_cursor: data.cursor ? null : 'fictional-publication-page',
        };
        else if (path.includes('/history-checks/')) {
            if (denied) return route.fulfill({ status: 403, json: { error: 'Fictional current support duty required.' } });
            if (path.endsWith('/run/')) {
                if (delay) await delay();
                const receipt = { reference: id(20), status: outcome, events_checked: 4, checked_at: '2026-11-24T12:00:00Z' };
                receipts.unshift(receipt);
                json = { result: { status: outcome, scope: kind + '_publication', events_checked: 4,
                    records_checked: outcome === 'verified' ? 1 : null, issues: outcome === 'unverified' ? ['course_binding'] : [] },
                    receipt, event_limit: 200 };
            } else json = { items: receipts, next_after: null };
        }
        await route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(office ? '/admin/catalog-only-fixture/' : '/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    if (kind === 'section') {
        await page.getByRole('link', { name: 'Sections', exact: true }).click();
        await page.getByRole('button', { name: section.code, exact: true }).click();
        await recordAction(page, 'Published section details');
    } else {
        await page.getByRole('button', { name: course.title, exact: true }).click();
        await recordAction(page, 'Published definitions');
    }
    return { calls, errors };
}
for (const kind of ['course', 'section']) test(kind + ' publication check returns to the accepted page and preserves its unfinished reason', async ({ page }) => {
    const f = await fixture(page, kind);
    const next = kind === 'course' ? 'Next definitions page' : 'Next section versions page';
    await page.getByRole('button', { name: next, exact: true }).click();
    const reason = page.getByLabel(kind === 'course' ? 'Reason for publication' : 'Reason for section publication');
    await reason.fill('Fictional unfinished publication reason.');
    if (kind === 'section') await page.getByLabel('Instruction starts on').fill('2026-09-01');
    await page.getByRole('button', { name: 'Check ' + kind + ' publication history', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('200-event allowance');
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Current check result' }).getByRole('heading', { name: 'Verified within this check' })).toBeVisible();
    await expect(page.locator('#drawer-body')).toContainText('Retained publications checked: 1');
    expect(f.calls.find(call => call.path.endsWith('/run/')).csrf).toBe('fictional-test-token');
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(reason).toHaveValue('Fictional unfinished publication reason.');
    if (kind === 'section') await expect(page.getByLabel('Instruction starts on')).toHaveValue('2026-09-01');
    await expect(page.getByRole('button', { name: 'Check ' + kind + ' publication history', exact: true })).toBeFocused();
    expect(f.calls.filter(call => call.path.endsWith('/publication/page/')).at(-1).data.cursor).toBe('fictional-publication-page');
    expect(f.calls.some(call => /publication\/(commit|select|amend)\/$/.test(call.path))).toBe(false);
    expect(f.errors).toEqual([]);
});
test('catalog-only staff receive no support action', async ({ page }) => {
    const f = await fixture(page, 'course', { office: true });
    await expect(page.getByRole('button', { name: 'Check course publication history', exact: true })).toHaveCount(0);
    expect(f.calls.some(call => call.path.includes('/history-checks/'))).toBe(false);
    expect(f.errors).toEqual([]);
});
test('late observations cannot restore a closed private check', async ({ page }) => {
    let release; const pending = new Promise(resolve => { release = resolve; });
    const f = await fixture(page, 'course', { delay: () => pending });
    await page.getByRole('button', { name: 'Check course publication history', exact: true }).click();
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    release();
    await expect(page.getByRole('button', { name: 'Check course publication history', exact: true })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Current check result' }).getByRole('heading', { name: 'Verified within this check' })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});
test('access loss clears earlier results and receipts while the publication return remains available', async ({ page }) => {
    const f = await fixture(page, 'course');
    await page.getByRole('button', { name: 'Check course publication history', exact: true }).click();
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Current check result' })).toContainText('Verified within this check');
    await page.route('**/history-checks/**', route => route.fulfill({ status: 403,
        json: { error: 'Fictional access changed.' } }));
    await page.getByRole('button', { name: 'Refresh previous checks', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Current check result' })).toBeEmpty();
    await expect(page.getByRole('region', { name: 'Previous history checks' })).toBeEmpty();
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Check course publication history', exact: true })).toBeFocused();
    expect(f.errors).toEqual([]);
});
test('catalog observation has usable keyboard and enlarged-phone reflow without private storage', async ({ page }) => {
    const f = await fixture(page, 'section', { outcome: 'unverified' });
    await page.getByRole('button', { name: 'Check section publication history', exact: true }).click();
    await page.setViewportSize({ width: 320, height: 850 });
    await page.addStyleTag({ content: 'html { font-size: 32px; }' });
    await page.getByRole('button', { name: 'Run history check', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('region', { name: 'Current check result' }).getByRole('heading', { name: 'Unable to verify', exact: true })).toBeVisible();
    await expect(page.locator('#drawer-body')).toContainText('Section course binding');
    expect(await page.locator('#drawer-body').evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    expect((await new AxeBuilder({ page }).include('#record-drawer').analyze()).violations).toEqual([]);
    expect(await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }))).not.toContain('publication reason');
    await page.screenshot({ path: '/tmp/etsis-catalog-history-phone.png' });
    expect(f.errors).toEqual([]);
});
for (const [outcome, expected] of [
    ['observed_empty', 'No publication observed'],
    ['limited', 'raising the event allowance alone does not remove those bounds'],
]) test(outcome + ' catalog observations explain incomplete or unknown evidence without partial counts', async ({ page }) => {
    const f = await fixture(page, 'course', { outcome });
    await page.getByRole('button', { name: 'Check course publication history', exact: true }).click();
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Current check result' })).toContainText(expected);
    await expect(page.getByRole('region', { name: 'Current check result' })).not.toContainText('Retained publications checked');
    expect(f.errors).toEqual([]);
});
