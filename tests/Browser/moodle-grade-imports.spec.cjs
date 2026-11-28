'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const notificationPerson = require('./helpers/notification-person.cjs');
const id = n => String(n).padStart(26, '0');
const section = id(1), candidate = id(2), a = id(3), b = id(4), notice = id(5), operation = id(6);
const root = `/sis/moodle/grade-imports/${section}/`;
async function fixture(page, options = {}) {
    await notificationPerson(page);
    const state = { calls: [], errors: [], collected: options.collected !== false, accepted: false,
        dismissed: false, requested: false, job: null, notice: options.notice ? { id: notice, section_id: section,
            state: 'complete', processed: 1, ready: 1, revision: 1, unread: true, recorded_at: '2026-08-10T10:00:00Z' } : null };
    page.on('pageerror', e => state.errors.push(e.message));
    const record = () => ({ id: candidate, student_id: id(7), revision: 1,
        identity: { name: 'Fictional <b>Learner</b>', institutional_id: 'S-FICTIONAL' },
        state: state.dismissed ? 'dismissed' : state.requested ? 'requested' : state.accepted ? 'accepted' : options.hidden ? 'unavailable' : 'pending',
        observed_at: '2026-08-10T10:00:00Z', grade: { state: options.hidden ? 'hidden' : 'available',
            grade: options.hidden ? null : '91.25000', minimum: '0.00000', maximum: '100.00000' },
        grade_change_request_id: state.requested ? operation : null,
        disposition: state.accepted || state.dismissed || state.requested ? { reason: 'Fictional retained evaluation reason.', decided_at: '2026-08-10T10:00:00Z' } : null,
        choices: options.hidden || state.accepted || state.requested || state.dismissed ? null : {
            items: [{ id: a, code: 'A' }, { id: b, code: 'B' }], pursuits: [], effective_on_default: '2026-08-10', current_grade_code: null,
        } });
    await page.route('**/sis/**', async route => {
        const r = route.request(), path = new URL(r.url()).pathname;
        const data = r.postData() ? JSON.parse(new URLSearchParams(r.postData()).get('_workspace_json') || '{}') : {};
        state.calls.push({ path, method: r.method(), data, csrf: r.headers()['x-csrf-token'] });
        let result;
        if (path === '/sis/moodle/status/') result = { enabled: true };
        else if (path === '/sis/moodle/installation/') result = { binding: { id: id(9), approved_at: '2026-08-10T10:00:00Z', site_fingerprint: 'a'.repeat(64), instance_fingerprint: 'b'.repeat(64) } };
        else if (path === '/sis/moodle/category-settings/') result = { source: null, version: 0 };
        else if (path === '/sis/moodle/sections/') result = { sync_enabled: true, items: [{ id: section, code: 'BIO.101.01', title: 'Fictional Biology', term_code: '26/FA', moodle_course_id: '42', issue: 'none', members_needing_setup: 0 }] };
        else if (path.endsWith('/record/')) result = { id: section, code: 'BIO.101.01', course_id: id(10), term_id: id(11), active: true, capacity: 20, revision: 'fictional' };
        else if (path === root + 'page/' && options.denied) return route.fulfill({ status: 403, json: { error: 'Fictional grade authority was withdrawn.' } });
        else if (path === root + 'page/') result = { section: { code: 'BIO.101.01', title: 'Fictional Biology' }, enabled: true, revision: 1, request_id: operation, job: state.job, items: state.collected ? [record()] : [], next_after: null };
        else if (path === root + 'request/') { state.job = { state: 'queued', processed: 0, ready: 0, issue: 'none' }; result = { request_id: operation, state: 'queued' }; }
        else if (path === root + 'cancel/') { state.job.state = 'cancelled'; result = { state: 'cancelled' }; }
        else if (path === root + candidate + '/') result = record();
        else if (path === root + candidate + '/review/') result = { record: record(), action: data.action, reason: data.reason,
            grade_review: data.action === 'dismiss' ? null : { grade: { code: data.grade_id === a ? 'A' : 'B' }, effective_at: '2026-08-10', requires_approval: !!options.approval }, review_token: 'fictional-private-grade-proof' };
        else if (path === root + candidate + '/confirm/') {
            if (options.stale) return route.fulfill({ status: 409, json: { error: 'The imported result changed. Review it again.' } });
            const review = state.calls.findLast(c => c.path.endsWith('/review/'));
            state.dismissed = review.data.action === 'dismiss'; state.requested = !state.dismissed && !!options.approval; state.accepted = !state.dismissed && !state.requested;
            result = { candidate_id: candidate, operation_id: operation, state: state.dismissed ? 'dismissed' : state.requested ? 'requested' : 'accepted' };
        } else if (path === '/sis/moodle/grade-imports/notices/') result = { items: state.notice ? [state.notice] : [], next_after: null };
        else if (path === `/sis/moodle/grade-imports/notices/${notice}/read/`) { state.notice.unread = false; state.notice.revision++; result = { state: 'read' }; }
        else if (path === `/sis/moodle/grade-imports/notices/${notice}/delete/`) { state.notice = null; result = { state: 'deleted' }; }
        else return route.fallback();
        return route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    return state;
}
async function open(page) {
    await page.goto('/admin/');
    await page.getByRole('link', { name: 'Moodle integration', exact: true }).click();
    await page.getByLabel('Search by section code', { exact: true }).fill('BIO');
    await page.locator('#moodle-section-search-form button[type=submit]').click();
    await page.getByRole('button', { name: 'Review imported grades', exact: true }).click();
}
async function review(page) {
    await page.getByRole('button', { name: 'View imported result', exact: true }).click();
    await page.getByLabel('Published SIS grade', { exact: false }).selectOption(a);
    await page.getByLabel('Evaluation reason', { exact: false }).fill('Fictional reviewed academic conversion.');
    await page.getByRole('button', { name: 'Review SIS grade', exact: true }).click();
}
async function accessible(page) {
    await page.setViewportSize({ width: 320, height: 900 });
    const scan = await new AxeBuilder({ page }).include('#record-drawer').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.locator('#drawer-body').evaluate(e => e.scrollWidth <= e.clientWidth + 1)).toBe(true);
}
test('queue submission returns immediately and status can refresh after server completion', async ({ page }) => {
    const state = await fixture(page, { collected: false }); await open(page);
    await page.getByLabel('Evaluation reason', { exact: false }).fill('Fictional background request.');
    await page.getByRole('button', { name: 'Start background intake', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Queued');
    expect(state.calls.some(c => c.path.includes('/run/'))).toBe(false);
    state.collected = true; state.job = { state: 'complete', processed: 1, ready: 1, issue: 'none' };
    await page.getByRole('button', { name: 'Refresh intake status', exact: true }).click();
    await expect(page.getByRole('button', { name: 'View imported result', exact: true })).toBeVisible();
    expect(state.calls.find(c => c.path === root + 'request/').csrf).toBe('fictional-test-token');
    expect(state.errors).toEqual([]);
});
test('review preserves explicit grade and reason and renders source text safely at narrow width', async ({ page }) => {
    const state = await fixture(page); await open(page); await review(page);
    await expect(page.locator('#drawer-body')).toContainText('Fictional <b>Learner</b>');
    await expect(page.locator('#drawer-body b')).toHaveCount(0);
    await page.getByRole('button', { name: 'Revise grade decision', exact: true }).click();
    await expect(page.getByLabel('Published SIS grade', { exact: false })).toHaveValue(a);
    await expect(page.getByLabel('Evaluation reason', { exact: false })).toHaveValue('Fictional reviewed academic conversion.');
    await page.getByRole('button', { name: 'Review SIS grade', exact: true }).click();
    await accessible(page);
    await page.getByRole('button', { name: 'Confirm grade decision', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('SIS grade recorded');
    await expect(page.locator('#drawer-body')).toContainText('Fictional retained evaluation reason.');
    await expect(page.getByRole('button', { name: 'Back to record', exact: true })).toBeVisible();
    expect(state.accepted).toBe(true);
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Section roster', exact: true })).toBeVisible();
    expect(state.errors).toEqual([]);
});
test('stale confirmation remains editable without claiming a grade was published', async ({ page }) => {
    const state = await fixture(page, { stale: true }); await open(page); await review(page);
    await page.getByRole('button', { name: 'Confirm grade decision', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('The imported result changed.');
    expect(state.accepted).toBe(false);
    await page.getByRole('button', { name: 'Revise grade decision', exact: true }).click();
    await expect(page.getByLabel('Published SIS grade', { exact: false })).toHaveValue(a);
    expect(state.errors).toEqual([]);
});
test('hidden total can be dismissed with a reason without choosing an SIS grade', async ({ page }) => {
    const state = await fixture(page, { hidden: true }); await open(page);
    await page.getByRole('button', { name: 'View imported result', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Moodle course total is hidden');
    await expect(page.getByLabel('Published SIS grade', { exact: false })).toHaveCount(0);
    await page.getByLabel('Evaluation reason', { exact: false }).fill('Fictional dismissal of unreleased total.');
    await page.getByRole('button', { name: 'Review dismissal', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm grade decision', exact: true }).click();
    expect(state.dismissed).toBe(true); expect(state.accepted).toBe(false); expect(state.errors).toEqual([]);
});
test('approval policy produces a correction-request handoff', async ({ page }) => {
    const state = await fixture(page, { approval: true }); await open(page); await review(page);
    await expect(page.locator('#drawer-body')).toContainText('independent approval');
    await page.getByRole('button', { name: 'Confirm grade decision', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review grade-correction request', exact: true })).toBeVisible();
    expect(state.requested).toBe(true); expect(state.accepted).toBe(false); expect(state.errors).toEqual([]);
});
test('completion notification is private and deletion uses the owned notification endpoint', async ({ page }) => {
    const state = await fixture(page, { notice: true }); await page.goto('/admin/');
    await page.locator('#workspace-notifications-open').click();
    await expect(page.getByRole('heading', { name: 'Moodle grade intake finished', exact: true })).toBeVisible();
    const card = page.locator('[data-update-id="' + notice + '"]');
    await card.getByRole('button', { name: 'Delete notification', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Moodle grade intake finished', exact: true })).toHaveCount(0);
    expect(state.notice).toBeNull(); expect(state.errors).toEqual([]);
});

test('completion notification marks itself read and opens the section intake', async ({ page }) => {
    const state = await fixture(page, { notice: true }); await page.goto('/admin/');
    await page.locator('#workspace-notifications-open').click();
    await page.getByRole('button', { name: 'Review imported grades', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Moodle grade intake', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'View imported result', exact: true })).toBeVisible();
    expect(state.notice.unread).toBe(false); expect(state.notice.revision).toBe(2);
    expect(state.errors).toEqual([]);
});
test('authority loss after opening a notification produces an accessible drawer error', async ({ page }) => {
    const state = await fixture(page, { notice: true, denied: true }); await page.goto('/admin/');
    await page.locator('#workspace-notifications-open').click();
    await page.getByRole('button', { name: 'Review imported grades', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('This task needs additional access.');
    await expect(page.getByRole('button', { name: 'View imported result', exact: true })).toHaveCount(0);
    expect(state.errors).toEqual([]);
});
