const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
async function fixture(page, { readOnly = false, reject = false } = {}) {
    const calls = [], errors = [];
    const student = id(1), section = id(2), pursuit = id(3), program = id(4), term = id(5), enrollment = id(6);
    const base = `/sis/students/${student}/sections/${section}/enrollments/${enrollment}/registered-credits/`;
    let current = 3000, version = 0, prepared = null, rejected = !reject;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/students/') result = { items: [{ id: student, person_id: id(10), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true }], next_after: null };
        else if (path === `/sis/students/${student}/`) result = { id: student, person_id: id(10), given_name: 'Alex', family_name: 'Example', active: true, institutional_id: 'S-FICTIONAL', primary_program: { id: program, code: 'BS.CS', title: 'Fictional computing' }, entry_term: { id: term, code: '26/FA' }, revision: 'student' };
        else if (path.endsWith('/programs/')) result = { items: [{ id: pursuit, program_id: program, program_code: 'BS.CS', program_title: 'Fictional computing', term_code: '26/FA', status: 'active', is_primary: true }], next_after: null };
        else if (path.endsWith('/load/terms/')) result = { items: [{ id: term, code: '26/FA', starts_on: '2026-08-01', ends_on: '2026-12-31' }], next_after: null };
        else if (path.endsWith('/load/review/')) result = { status: 'ready', term: { code: '26/FA' }, term_timing: 'current', program: { code: 'BS.CS', title: 'Fictional computing' }, credits_milli: current, classification: { label: current >= 4000 ? 'Full time' : 'Part time' }, policy: { source: 'global' }, items: [{ enrollment_id: enrollment, section_id: section, course_code: 'MATH.101', course_title: 'Fictional <b>Algebra</b>', credits_milli: current }] };
        else if (path === base + 'view/') result = { course_code: 'MATH.101', course_title: 'Fictional <b>Algebra</b>', term_code: '26/FA', original_registered_milli: 3000, current_registered_milli: current, awarded_attempted_milli: 3000, awarded_completed_milli: 0, expected_version: version, expected_position: version + 1, can_correct: true };
        else if (path === base + 'review/') { prepared = { ...data, operation_id: id(20 + version), previous_registered_milli: current, review_token: 'fictional-registered-credit-proof' }; result = prepared; }
        else if (path === base + 'confirm/') {
            if (!rejected) { rejected = true; return route.fulfill({ status: 409, json: { error: 'Fictional registered-credit source conflict. Reload and review again.' } }); }
            current = prepared.registered_milli; version++;
            result = { operation_id: prepared.operation_id };
        } else if (path === base + 'history/') result = { items: version ? [{ previous_registered_milli: 3000, registered_milli: current, recorded_at: '2026-11-21T18:00:00Z' }] : [], next_cursor: null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(readOnly ? '/admin/student-read-fixture/' : '/admin/');
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator('#directory-search').fill('Alex'); await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await recordAction(page, 'Enrollment load');
    await page.getByRole('button', { name: 'Review enrollment load', exact: true }).click();
    await page.getByRole('button', { name: /26\/FA · 2026-08-01/ }).click();
    return { calls, errors, base };
}
async function enter(page) {
    await page.getByRole('button', { name: 'Registered credits · MATH.101', exact: true }).click();
    await page.getByLabel('Corrected registered credits', { exact: true }).fill('4.5');
    await page.getByLabel('Reason and institutional evidence', { exact: true }).fill('Fictional documented registration evidence.');
    await page.getByRole('checkbox', { name: /I verified the institutional registration evidence/ }).check();
}

test('registered-credit review preserves drafts through history, revision and rejected confirmation before refreshing load', async ({ page }) => {
    const f = await fixture(page, { reject: true }); await enter(page);
    await page.getByRole('button', { name: 'Registered-credit history', exact: true }).click();
    await expect(page.getByText('No registered-credit corrections on this history page.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Back to correction', exact: true }).click();
    await expect(page.getByLabel('Corrected registered credits', { exact: true })).toHaveValue('4.5');
    await expect(page.getByRole('checkbox', { name: /I verified the institutional registration evidence/ })).toBeChecked();
    await page.getByRole('button', { name: 'Review registered-credit correction', exact: true }).click();
    await page.getByRole('button', { name: 'Revise correction', exact: true }).click();
    await expect(page.getByLabel('Reason and institutional evidence', { exact: true })).toHaveValue('Fictional documented registration evidence.');
    await page.setViewportSize({ width: 375, height: 812 });
    expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze()).violations.map(v => v.id)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Review registered-credit correction', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm registered-credit correction', exact: true }).click();
    await expect(page.getByText('Fictional registered-credit source conflict. Reload and review again.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Reload registered-credit evidence', exact: true }).click();
    await expect(page.getByLabel('Corrected registered credits', { exact: true })).toHaveValue('4.5');
    await page.getByRole('button', { name: 'Review registered-credit correction', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm registered-credit correction', exact: true }).click();
    await page.getByRole('button', { name: 'Refresh enrollment load', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('4.5 registered credits · Full time');
    await page.getByRole('button', { name: 'Registered credits · MATH.101', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Original registered credits: 3. Current accepted registered credits: 4.5.');
    await expect(page.locator('#drawer-body')).toContainText('Awarded attempted credits: 3');
    await page.getByRole('button', { name: 'Registered-credit history', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('3 → 4.5 registered credits');
    expect(await page.locator('#drawer-body b').count()).toBe(0);
    expect(f.calls.filter(c => c.path === f.base + 'confirm/').every(c => c.data.registered_milli === 4500 && c.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('student-read permission does not expose registered-credit correction controls', async ({ page }) => {
    const f = await fixture(page, { readOnly: true });
    await expect(page.locator('#drawer-body')).toContainText('3 registered credits');
    await expect(page.getByRole('button', { name: 'Registered credits · MATH.101', exact: true })).toHaveCount(0);
    expect(f.calls.some(c => c.path.includes('/registered-credits/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('editing while registered credits are being reviewed keeps the later draft and requires a fresh review', async ({ page }) => {
    const f = await fixture(page); await enter(page);
    let release, started;
    const waiting = new Promise(resolve => { release = resolve; }), pending = new Promise(resolve => { started = resolve; });
    await page.route(`**${f.base}review/`, async route => { started(); await waiting; await route.fallback(); });
    await page.getByRole('button', { name: 'Review registered-credit correction', exact: true }).click(); await pending;
    await page.getByLabel('Reason and institutional evidence', { exact: true }).fill('My later fictional evidence.');
    const received = page.waitForResponse(response => new URL(response.url()).pathname === f.base + 'review/');
    release(); await received;
    await expect(page.getByText('Your draft changed while reviewing. Review it again.', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Reason and institutional evidence', { exact: true })).toHaveValue('My later fictional evidence.');
    await expect(page.getByRole('button', { name: 'Confirm registered-credit correction', exact: true })).toHaveCount(0);
    expect(f.calls.some(c => c.path === f.base + 'confirm/')).toBe(false);
    expect(f.errors).toEqual([]);
});

test('a late registered-credit confirmation cannot overwrite a revised draft', async ({ page }) => {
    const f = await fixture(page); await enter(page);
    await page.getByRole('button', { name: 'Review registered-credit correction', exact: true }).click();
    let release, started;
    const waiting = new Promise(resolve => { release = resolve; }), pending = new Promise(resolve => { started = resolve; });
    await page.route(`**${f.base}confirm/`, async route => { started(); await waiting; await route.fallback(); });
    await page.getByRole('button', { name: 'Confirm registered-credit correction', exact: true }).click(); await pending;
    await page.getByRole('button', { name: 'Revise correction', exact: true }).click();
    await page.getByLabel('Corrected registered credits', { exact: true }).fill('4.75');
    const received = page.waitForResponse(response => new URL(response.url()).pathname === f.base + 'confirm/');
    release(); await received;
    await expect(page.getByLabel('Corrected registered credits', { exact: true })).toHaveValue('4.75');
    await expect(page.getByRole('button', { name: 'Refresh enrollment load', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Reload registered-credit evidence', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Current accepted registered credits: 4.5');
    await expect(page.getByLabel('Corrected registered credits', { exact: true })).toHaveValue('4.75');
    expect(f.errors).toEqual([]);
});
