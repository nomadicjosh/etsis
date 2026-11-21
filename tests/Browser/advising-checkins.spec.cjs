'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
const student = id(1), person = id(2), pursuit = id(3), program = id(4), term = id(5);
const original = () => ({ id: id(10), held_on: '2026-08-10', due_on: '2026-08-20', status: 'open', version: 0,
    next_step: 'Review <b>your course plan</b>.', private_note: 'Fictional private advising detail.', response: null, resolution: null,
    created_at: '2026-08-10T17:00:00Z', responded_at: null, resolved_at: null, owner_changed: false });
async function accessibility(page) {
    await page.setViewportSize({ width: 320, height: 812 });
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
}
async function fixture(page, channel = 'staff', { invalidCreate = false } = {}) {
    const calls = [], errors = [];
    let items = channel === 'staff' ? [] : [original()], stale = true, sequence = 20;
    page.on('pageerror', error => errors.push(error.message));
    await page.route(channel === 'staff' ? '**/sis/**' : '**/portal/api/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/students/') result = { items: [{ id: student, person_id: person, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true }], next_after: null };
        else if (path === `/sis/students/${student}/`) result = { id: student, person_id: person, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true, primary_program: { id: program, code: 'BS.CS', title: 'Computing' }, entry_term: { id: term, code: '26/FA' }, revision: 'student' };
        else if (path.endsWith('/programs/') || path.endsWith('/degree-programs/')) result = { items: [{ id: pursuit, program_id: program, program_code: 'BS.CS', program_title: 'Computing', term_code: '26/FA', status: 'active', is_primary: true }], next_after: null };
        else if (path === '/portal/api/me/') result = { students: channel === 'owner' ? [{ id: student, primary_program_id: program }] : [] };
        else if (path === '/portal/api/advising/') result = { items: [{ student_id: student, pursuit_id: pursuit, name: 'Alex Example', institutional_id: 'S-FICTIONAL', program: { code: 'BS.CS', title: 'Computing' }, entry_term: { code: '26/FA' } }], next_after: null };
        else if (path.endsWith('/standing/')) result = { items: [], as_of: '2026-08-10' };
        else if (path.endsWith('/progress/') || path.endsWith('/degree-progress/')) return route.fulfill({ status: 409, json: { error: 'Fictional degree review unavailable.' } });
        else if (path.endsWith('/checkins/list/')) result = { items: items.filter(i => data.finished ? ['closed', 'cancelled'].includes(i.status) : ['open', 'responded'].includes(i.status)).map(i => {
            if (channel !== 'owner') return i;
            const { private_note, owner_changed, ...publicItem } = i;
            return publicItem;
        }), next_after: null, new_id: id(sequence++), today: '2026-08-10', can_record: channel !== 'owner' };
        else if (path.endsWith('/checkins/record/')) {
            if (invalidCreate) { invalidCreate = false; return route.fulfill({ status: 422, json: { error: 'Please correct the highlighted fields.', errors: [{ field: 'next_step', message: 'Fictional instructions need more detail.' }] } }); }
            items.push({ ...original(), id: data.checkin_id, held_on: data.held_on, due_on: data.due_on, next_step: data.next_step, private_note: data.private_note });
            result = { id: data.checkin_id, status: 'open', version: 0 };
        } else if (path.endsWith('/checkins/act/')) {
            if (stale) { stale = false; return route.fulfill({ status: 409, json: { error: 'This check-in changed. Refresh check-ins and review its current status.' } }); }
            const item = items.find(i => i.id === data.checkin_id);
            item.version++;
            item.status = data.action === 'respond' ? 'responded' : data.action === 'close' ? 'closed' : 'cancelled';
            if (data.action === 'respond') { item.response = data.message; item.responded_at = '2026-08-10T18:00:00Z'; }
            else { item.resolution = data.message; item.resolved_at = '2026-08-10T19:00:00Z'; }
            result = { id: item.id, version: item.version, status: item.status };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    return { calls, errors };
}

test('staff records check-in, preserves a stale closure draft, and reads finished follow-up', async ({ page }) => {
    const { calls, errors } = await fixture(page, 'staff', { invalidCreate: true });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator('#directory-search').fill('Alex');
    await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    expect(calls.some(c => c.path.includes('/checkins/'))).toBe(false);
    await recordAction(page, 'Advising check-ins');
    await page.getByRole('button', { name: 'Record a check-in', exact: true }).click();
    await page.getByLabel('Next step visible to the student', { exact: true }).fill('Review <b>your course plan</b>.');
    await page.getByLabel('Private advising note (optional)', { exact: true }).fill('Fictional private advising detail.');
    await page.getByRole('button', { name: 'Save check-in', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional instructions need more detail.');
    await expect(page.getByLabel('Next step visible to the student', { exact: true })).toHaveValue('Review <b>your course plan</b>.');
    await expect(page.getByLabel('Next step visible to the student', { exact: true })).toHaveAttribute('aria-invalid', 'true');
    await page.getByLabel('Next step visible to the student', { exact: true }).fill('Review <b>your course plan</b>.');
    await page.getByRole('button', { name: 'Save check-in', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Check-in saved.');
    expect(calls.find(c => c.path.endsWith('/checkins/record/')).data.due_on).toBeNull();
    await page.getByLabel('Outcome visible to the student', { exact: true }).fill('Fictional completed planning.');
    await page.getByRole('button', { name: 'Save follow-up outcome', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('This check-in changed.');
    await expect(page.getByLabel('Outcome visible to the student', { exact: true })).toHaveValue('Fictional completed planning.');
    await page.getByRole('button', { name: 'Refresh check-ins', exact: true }).click();
    await expect(page.getByLabel('Outcome visible to the student', { exact: true })).toHaveValue('Fictional completed planning.');
    await page.getByRole('button', { name: 'Save follow-up outcome', exact: true }).click();
    await page.getByRole('button', { name: 'Finished check-ins', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional completed planning.');
    await expect(page.locator('#drawer-body b')).toHaveCount(0);
    await accessibility(page);
    expect(calls.filter(c => c.path.includes('/checkins/')).every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(errors).toEqual([]);
});

test('student follows up independently of degree review with private notes excluded', async ({ page }) => {
    const { calls, errors } = await fixture(page, 'owner');
    await page.goto('/portal/student/progress/');
    await page.getByRole('button', { name: /Review progress · BS.CS/ }).click();
    await expect(page.getByRole('button', { name: 'Advising check-ins', exact: true })).toBeVisible();
    expect(calls.some(c => c.path.includes('/checkins/'))).toBe(false);
    await page.getByRole('button', { name: 'Advising check-ins', exact: true }).click();
    await expect(page.locator('#campus-service-content')).not.toContainText('Fictional private advising detail.');
    await expect(page.getByRole('button', { name: 'Record a check-in', exact: true })).toHaveCount(0);
    await page.getByLabel('Your response', { exact: true }).fill('Fictional student plan reviewed.');
    await page.getByRole('button', { name: 'Send response', exact: true }).click();
    await expect(page.getByLabel('Your response', { exact: true })).toHaveValue('Fictional student plan reviewed.');
    await page.getByRole('button', { name: 'Refresh check-ins', exact: true }).click();
    await page.getByRole('button', { name: 'Send response', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('Your response was saved.');
    await expect(page.locator('#campus-service-content b')).toHaveCount(0);
    await accessibility(page);
    expect(calls.filter(c => c.path.includes('/checkins/')).every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(errors).toEqual([]);
});

test('assigned advisor opens check-ins on demand and can record a new follow-up', async ({ page }) => {
    const { calls, errors } = await fixture(page, 'advisor');
    await page.goto('/portal/student/advising/');
    await page.getByRole('button', { name: 'Program and degree progress', exact: true }).click();
    expect(calls.some(c => c.path.includes('/checkins/'))).toBe(false);
    await page.getByRole('button', { name: 'Advising check-ins', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('Fictional private advising detail.');
    await page.getByRole('button', { name: 'Record a check-in', exact: true }).click();
    await page.getByLabel('Next step visible to the student', { exact: true }).fill('Discuss next term.');
    await page.getByRole('button', { name: 'Save check-in', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('Discuss next term.');
    expect(calls.filter(c => c.path.includes('/checkins/')).every(c => c.path.startsWith('/portal/api/advising/') && c.csrf === 'fictional-test-token')).toBe(true);
    await accessibility(page);
    expect(errors).toEqual([]);
});

test('lost advising access clears earlier private notes and unavailable forms', async ({ page }) => {
    const { errors } = await fixture(page, 'advisor');
    await page.goto('/portal/student/advising/');
    await page.getByRole('button', { name: 'Program and degree progress', exact: true }).click();
    await page.getByRole('button', { name: 'Advising check-ins', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('Fictional private advising detail.');
    await page.route('**/checkins/list/', route => route.fulfill({ status: 403, json: { error: 'Current advisor access is required.' } }));
    await page.getByRole('button', { name: 'Refresh check-ins', exact: true }).click();
    await expect(page.locator('#campus-service-content')).not.toContainText('Fictional private advising detail.');
    await expect(page.getByRole('button', { name: 'Record a check-in', exact: true })).toHaveCount(0);
    await expect(page.getByLabel('Outcome visible to the student', { exact: true })).toHaveCount(0);
    expect(errors).toEqual([]);
});

test('a delayed save cannot replace a different drawer after leaving check-ins', async ({ page }) => {
    const { calls, errors } = await fixture(page);
    let release, requested;
    const hold = new Promise(resolve => { release = resolve; });
    const pending = new Promise(resolve => { requested = resolve; });
    await page.route('**/checkins/record/', async route => { requested(); await hold; await route.fallback(); });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator('#directory-search').fill('Alex');
    await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await recordAction(page, 'Advising check-ins');
    await page.getByRole('button', { name: 'Record a check-in', exact: true }).click();
    await page.getByLabel('Next step visible to the student', { exact: true }).fill('Delayed fictional follow-up.');
    await page.getByRole('button', { name: 'Save check-in', exact: true }).click();
    await pending;
    await page.getByRole('button', { name: 'Back to programs', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Student programs', exact: true })).toBeVisible();
    const accepted = page.waitForResponse(response => response.url().includes('/checkins/record/'));
    release();
    await accepted;
    await expect(page.getByRole('region', { name: 'Student programs', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save check-in', exact: true })).toHaveCount(0);
    expect(calls.filter(c => c.path.endsWith('/checkins/list/'))).toHaveLength(1);
    expect(errors).toEqual([]);
});
