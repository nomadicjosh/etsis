'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction, workspaceTool } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
const person = id(3), student = id(4), feeId = id(5);
const program = '01K00000000000000000000001', term = '01K00000000000000000000002';
async function fixture(page, purpose = 'application') {
    const state = { calls: [], errors: [], cleared: false, waived: false, used: false };
    const policy = { revision: 1, minor: 2500, currency: 'USD', waivers: true };
    const fee = () => ({ required: true, fee_id: feeId, purpose, labels: { title: 'Fictional <b>program</b>', term: purpose === 'application' ? '26/FA' : '' },
        target: purpose === 'application' ? { program_id: program, term_id: term } : { student_id: student }, policy,
        cleared: state.cleared, waived: state.waived, net_minor: state.cleared && !state.waived ? 2500 : 0,
        applied_to: state.used ? id(6) : null, version: state.cleared ? 2 : 1, payments: [], refunds: [] });
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route(/\/(sis|portal\/api)\//, async route => {
        const r = route.request(), path = new URL(r.url()).pathname;
        const data = r.postData() ? JSON.parse(new URLSearchParams(r.postData()).get('_workspace_json') || '{}') : {};
        state.calls.push({ path, data, csrf: r.headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) {
            const source = path.split('/')[4];
            result.items = source === 'people' ? [{ id: person, given_name: 'Fictional', family_name: 'Applicant', institutional_id: 'S-FICTIONAL' }]
                : source === 'programs' ? [{ id: program, code: 'UG', title: 'Fictional program' }] : source === 'terms' ? [{ id: term, code: '26/FA' }] : [];
        } else if (path === `/sis/ui/people/${person}/`) result = { person: { id: { value: person }, givenName: 'Fictional', familyName: 'Applicant', institutionalId: { value: 'S-FICTIONAL' }, ownerUserId: { value: id(9) } } };
        else if (path === '/sis/service-fees/policies/') result = { application: policy, transcript: policy };
        else if (/^\/sis\/service-fees\/policies\//.test(path)) result = { ...policy, ...data, revision: 2 };
        else if (path.endsWith('/service-fees/page/')) result = { items: [fee()], next_after: null };
        else if (path.endsWith('/service-fees/prepare/')) result = fee();
        else if (path.endsWith(`/service-fees/${feeId}/`)) result = fee();
        else if (path.endsWith(`/service-fees/${feeId}/history/`)) result = { items: [{ action: 'assess', recorded_at: '2026-08-10T10:00:00Z', reason: path.startsWith('/sis/') ? 'Fictional assessment.' : null }], next_before: null };
        else if (path.endsWith(`/service-fees/${feeId}/review/`)) result = { fee_id: feeId, operation_id: id(7), action: data.action, details: data.details,
            reason: data.reason, revision: 'a'.repeat(64), review_token: 'fictional-fee-review', summary: fee() };
        else if (path.endsWith(`/service-fees/${feeId}/confirm/`)) { state.cleared = true; state.waived = true; result = { status: 'recorded' }; }
        else if (path.endsWith(`/service-fees/${feeId}/checkout/`)) result = { url: 'https://checkout.stripe.com/c/pay/fictional', cleared: false };
        else if (path === '/portal/api/me/') result = { person: { id: person, given_name: 'Fictional', family_name: 'Applicant' }, students: purpose === 'transcript' ? [{ id: student, primary_program_id: program }] : [],
            applications: [], accounts: [], contact: {}, news: [], transcript_requests: [], request_id: id(10) };
        else if (path === '/portal/api/applications/') result = { person_id: person, items: [] };
        else if (path.includes('/forms/programs/')) result = { id: id(8), submission_id: id(11), application_program_id: program, name: 'Fictional application', fields: [{ key: 'statement', label: 'Fictional plans', type: 'textarea', required: true }], layout: [] };
        else if (path === `/sis/forms/${id(8)}/submissions/`) { state.used = true; result = { status: 'submitted' }; }
        else if (path === '/portal/api/transcripts/') { state.used = true; result = { status: 'submitted' }; }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    return state;
}
async function accessible(page, drawer = false) {
    const scan = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']);
    if (drawer) scan.include('#record-drawer');
    expect((await scan.analyze()).violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.locator(drawer ? '#drawer-body' : '#campus-service-content').evaluate(e => e.scrollWidth <= e.clientWidth + 1)).toBe(true);
}
test('staff configures optional prices in major currency units', async ({ page }) => {
    const state = await fixture(page);
    await page.goto('/admin/workspace/applications/');
    await workspaceTool(page, 'Service fee policies');
    const form = page.locator('#drawer-body form').first();
    await expect(form.getByLabel('Amount', { exact: false })).toHaveValue('25.00');
    await form.getByLabel('Amount', { exact: false }).fill('30');
    await form.getByRole('button', { name: 'Save policy', exact: true }).click();
    await expect.poll(() => state.calls.filter(c => c.path === '/sis/service-fees/policies/application/').length).toBe(1);
    expect(state.calls.find(c => c.path === '/sis/service-fees/policies/application/').data.minor).toBe(3000);
    await page.setViewportSize({ width: 320, height: 900 });
    await accessible(page, true); expect(state.errors).toEqual([]);
});
test('person fee drawer reviews a waiver and returns to the person record', async ({ page }) => {
    const state = await fixture(page);
    await page.goto('/admin/workspace/people/');
    await page.getByLabel('Search this workspace', { exact: true }).fill('Fictional');
    await page.getByLabel('Search this workspace', { exact: true }).press('Enter');
    await page.getByRole('button', { name: 'Fictional Applicant', exact: true }).click();
    await recordAction(page, 'Application and transcript fees');
    await page.getByRole('button', { name: 'View fee', exact: true }).click();
    await page.getByRole('button', { name: 'Review fee waiver', exact: true }).click();
    await page.locator('#drawer-body').getByLabel('Reason', { exact: false }).fill('Fictional approved hardship waiver.');
    await page.getByRole('button', { name: 'Review decision', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional approved hardship waiver.');
    await page.getByRole('button', { name: 'Back to fee', exact: true }).click();
    await expect(page.locator('#drawer-body').getByLabel('Reason', { exact: false })).toHaveValue('Fictional approved hardship waiver.');
    await page.getByRole('button', { name: 'Review decision', exact: true }).click();
    await expect(page.locator('#drawer-body b')).toHaveCount(0);
    await page.setViewportSize({ width: 320, height: 900 }); await accessible(page, true);
    await page.getByRole('button', { name: 'Confirm decision', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Authorized waiver');
    await expect(page.locator('#drawer-body dt').filter({ hasText: 'Remaining payment' }).locator('xpath=following-sibling::dd[1]')).toHaveText('$0.00');
    const call = state.calls.find(c => c.path.endsWith('/confirm/'));
    expect(call.csrf).toBe('fictional-test-token');
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('tablist', { name: 'Person record sections' })).toBeVisible(); expect(state.errors).toEqual([]);
});
test('application fee clears before questions and uses the retained fee as submission receipt', async ({ page }) => {
    const state = await fixture(page);
    await page.goto(`/portal/apply/?program=${program}&term=${term}`);
    await page.getByRole('button', { name: 'Continue to your application', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Pay securely', exact: true })).toBeVisible();
    await expect(page.getByLabel('Fictional plans', { exact: false })).toHaveCount(0);
    await page.setViewportSize({ width: 320, height: 900 }); await accessible(page);
    state.cleared = true;
    await page.getByRole('button', { name: 'Refresh payment status', exact: true }).click();
    await page.getByLabel('Fictional plans', { exact: false }).fill('Fictional plans for the coming year.');
    await page.getByRole('button', { name: 'Review answers', exact: true }).click();
    await page.getByRole('button', { name: 'Submit application', exact: true }).click();
    await expect.poll(() => state.used).toBe(true);
    const call = state.calls.find(c => c.path.endsWith('/submissions/'));
    expect(call.data.submission_id).toBe(feeId); expect(call.csrf).toBe('fictional-test-token'); expect(state.errors).toEqual([]);
});
test('official request waits for confirmed payment while unofficial transcripts stay available', async ({ page }) => {
    const state = await fixture(page, 'transcript');
    await page.goto('/portal/student/transcripts/');
    await page.getByRole('button', { name: 'Request official transcript', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Pay securely', exact: true })).toBeVisible();
    expect(state.calls.filter(c => c.path === '/portal/api/transcripts/')).toHaveLength(0);
    await expect(page.getByRole('button', { name: /View unofficial record/ })).toBeVisible();
    state.cleared = true;
    await page.getByRole('button', { name: 'Refresh payment status', exact: true }).click();
    await expect.poll(() => state.used).toBe(true);
    expect(state.calls.find(c => c.path === '/portal/api/transcripts/').data.request_id).toBe(feeId);
    expect(state.errors).toEqual([]);
});

test('a paid quote resumes the selected application after returning to the page', async ({ page }) => {
    const state = await fixture(page); state.cleared = true;
    await page.goto('/portal/apply/');
    await page.getByRole('button', { name: 'Resume application', exact: true }).click();
    await expect(page.getByLabel('Fictional plans', { exact: false })).toBeVisible();
    await expect(page.getByLabel('Choose your academic program', { exact: true })).toHaveValue(program);
    await expect(page.getByRole('button', { name: 'Pay securely', exact: true })).toHaveCount(0);
    expect(state.calls.filter(c => c.path.endsWith('/checkout/'))).toHaveLength(0);
    expect(state.errors).toEqual([]);
});
