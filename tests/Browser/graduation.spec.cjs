'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
const student = id(1), pursuit = id(2), term = id(3), program = id(4);
const proposal = { title: 'Bachelor of Arts <b>fictional</b>', awarded_on: '2026-08-10', term_id: term, honors: '' };
const credential = { id: id(8), proposal, program: { id: program, code: 'ARTS.UG', title: 'Fictional arts' },
    term: { id: term, code: '26/FA' }, components: [{ kind: 'specialization', code: 'ENG', title: 'Fictional English' }] };

async function fixture(page, { eligible = true, awarded = false, stale = false } = {}) {
    const state = { eligible, awarded, calls: [], errors: [], stale, credential: structuredClone(credential) };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route(/\/(sis|portal\/api)\//, async route => {
        const r = route.request(), path = new URL(r.url()).pathname;
        const raw = r.postData(), data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json') || '{}') : {};
        state.calls.push({ path, data, csrf: r.headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        const review = (s = student, p = pursuit) => ({ student_id: s, pursuit_id: p, action: data.action || 'confer',
            operation_id: id(9), revision: 'a'.repeat(64), review_token: 'fictional-graduation-proof',
            reason: data.reason, request: data.request || [], summary: { identity: { name: data.candidates
                ? `Fictional Graduate ${Number(s)}` : 'Fictional Learner', institutional_id: `S-FIX-${Number(s)}` }, eligible: data.action === 'eligibility' ? true : null,
                proposal: data.action === 'correct' ? { ...proposal, ...data.request }
                    : data.action === 'revoke' ? state.credential.proposal : data.request?.proposal || proposal, term: credential.term, closes_pursuit: data.action !== 'eligibility',
                progress: data.action === 'eligibility' ? { components: [{ context: { name: 'Fictional English requirements' } }] } : null } });
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/students/') result = { items: [{ id: student, person_id: id(5), given_name: 'Fictional', family_name: 'Learner', institutional_id: 'S-FICTIONAL', active: true }], next_after: null };
        else if (path === '/sis/ui/data/terms/') result = { items: [credential.term], next_after: null };
        else if (path.endsWith('/record/')) result = { id: student, person_id: id(5), given_name: 'Fictional', family_name: 'Learner',
            institutional_id: 'S-FICTIONAL', active: true, primary_program: credential.program, entry_term: credential.term,
            primary_program_status: state.awarded ? 'closed' : 'active', revision: 'fictional-student' };
        else if (path === `/sis/students/${student}/programs/`) result = { items: [{ id: pursuit, program_id: program,
            program_code: 'ARTS.UG', program_title: 'Fictional arts', term_code: '26/FA', status: state.awarded ? 'closed' : 'active', is_primary: true }], next_after: null };
        else if (path.endsWith('/graduation/')) result = { identity: { name: 'Fictional Learner', institutional_id: 'S-FICTIONAL' },
            credential: state.awarded ? state.credential : null,
            eligibility: state.eligible && !state.awarded ? { eligible: true, proposal } : null, can_review: true, can_confer: true };
        else if (path.endsWith('/graduation/review/')) result = review();
        else if (path.endsWith('/graduation/confirm/')) {
            if (state.stale) return route.fulfill({ status: 409, json: { error: 'Fictional graduation inputs changed.' } });
            if (data.action === 'eligibility') state.eligible = true;
            else if (data.action === 'revoke') { state.awarded = false; state.eligible = false; }
            else { state.awarded = true; if (data.action === 'correct') Object.assign(state.credential.proposal, data.request); }
            result = { status: 'recorded' };
        } else if (path.endsWith('/graduation/history/')) result = { items: state.awarded
            ? [{ action: 'confer', recorded_at: '2026-08-10T12:00:00Z', credential, eligible: null }] : [], next_cursor: null };
        else if (path === '/sis/graduation-conferral/page/') result = { items: state.awarded ? [] : [1, 2].map(n => ({
            student_id: id(n), pursuit_id: id(n + 10), name: `Fictional Graduate ${n}`, student_number: `S-FIX-${n}`, proposal })), next_after: null };
        else if (path === '/sis/graduation-conferral/review/') {
            result = { reviews: data.candidates.map(c => review(c.student_id, c.pursuit_id)) };
            if (state.reviewGate) await state.reviewGate;
        }
        else if (path === '/sis/graduation-conferral/confirm/') {
            if (state.stale) return route.fulfill({ status: 409, json: { error: 'Fictional candidate changed. Review again.' } });
            state.awarded = true; result = { items: data.reviews.map(r => ({ operation_id: r.operation_id })) };
        } else if (path === '/portal/api/me/') result = { person: { id: id(5), given_name: 'Fictional', family_name: 'Learner' },
            students: [{ id: student, primary_program_id: program }], applications: [], accounts: [], news: [], restrictions: [], transcript_requests: [] };
        else if (path.endsWith('/degree-programs/')) result = { items: [{ id: pursuit, program_code: 'ARTS.UG', program_title: 'Fictional arts', term_code: '26/FA', status: 'closed' }], next_after: null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    return state;
}
async function open(page) {
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator('#directory-search').fill('Fictional');
    await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Fictional Learner', exact: true }).click();
    await recordAction(page, 'Graduation & credentials');
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).include('#record-drawer')
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.locator('#drawer-body').evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
}

test('eligibility form retains explicit institutional checks and returns to the program record', async ({ page }) => {
    const state = await fixture(page, { eligible: false });
    await open(page);
    await page.getByRole('button', { name: 'Review eligibility', exact: true }).click();
    await page.getByLabel('Credential title', { exact: false }).fill(proposal.title);
    await page.getByLabel('Graduation date', { exact: false }).fill('2026-08-10');
    await page.getByLabel('Graduation term', { exact: false }).selectOption(term);
    await page.getByLabel('I verified the institution’s other graduation requirements and release clearances.', { exact: true }).check();
    await page.getByLabel('Reason and supporting evidence', { exact: false }).fill('Fictional completed academic and office review.');
    await accessible(page);
    await page.getByRole('button', { name: 'Review decision', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional English requirements');
    expect(state.calls.find(c => c.path.endsWith('/graduation/review/')).data.request.attested).toBe(true);
    await expect(page.locator('#drawer-body b')).toHaveCount(0);
    await page.getByRole('button', { name: 'Confirm decision', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Eligible for conferral');
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Student programs', exact: true })).toBeVisible();
    expect(state.errors).toEqual([]);
});

test('a selected group is reviewed and conferred together with CSRF and narrow-screen reflow', async ({ page }) => {
    const state = await fixture(page);
    await open(page);
    await page.getByRole('button', { name: 'Group conferral queue', exact: true }).click();
    await page.getByLabel('Fictional Graduate 1 · S-FIX-1', { exact: true }).check();
    await page.getByLabel('Fictional Graduate 2 · S-FIX-2', { exact: true }).check();
    await page.getByLabel('Reason and supporting evidence', { exact: true }).fill('Fictional reviewed August ceremony.');
    await page.getByRole('button', { name: 'Credential details', exact: true }).first().click();
    await page.getByRole('button', { name: 'Back to conferral queue', exact: true }).click();
    await expect(page.getByLabel('Fictional Graduate 2 · S-FIX-2', { exact: true })).toBeChecked();
    await expect(page.getByLabel('Reason and supporting evidence', { exact: true })).toHaveValue('Fictional reviewed August ceremony.');
    await page.getByRole('button', { name: 'Review selected conferrals', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional Graduate 2');
    await page.setViewportSize({ width: 320, height: 900 });
    await accessible(page);
    await page.getByRole('button', { name: 'Confer selected group', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('No recorded eligible candidates');
    const call = state.calls.find(c => c.path === '/sis/graduation-conferral/confirm/');
    expect(call.data.reviews).toHaveLength(2);
    expect(call.csrf).toBe('fictional-test-token');
    expect(await page.evaluate(() => Object.keys(localStorage).filter(k => /graduation|credential/i.test(k)))).toEqual([]);
    expect(state.errors).toEqual([]);
});

test('stale group confirmation leaves the review available for correction', async ({ page }) => {
    const state = await fixture(page, { stale: true });
    await open(page);
    await page.getByRole('button', { name: 'Group conferral queue', exact: true }).click();
    await page.getByLabel('Fictional Graduate 1 · S-FIX-1', { exact: true }).check();
    await page.getByLabel('Reason and supporting evidence', { exact: true }).fill('Fictional ceremony.');
    await page.getByRole('button', { name: 'Review selected conferrals', exact: true }).click();
    await page.getByRole('button', { name: 'Confer selected group', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Fictional candidate changed');
    await expect(page.getByRole('button', { name: 'Back to conferral queue', exact: true })).toBeVisible();
    expect(state.awarded).toBe(false);
    await page.getByRole('button', { name: 'Back to conferral queue', exact: true }).click();
    await expect(page.getByLabel('Reason and supporting evidence', { exact: true })).toHaveValue('Fictional ceremony.');
    await expect(page.getByLabel('Fictional Graduate 1 · S-FIX-1', { exact: true })).toBeChecked();
    expect(state.errors).toEqual([]);
});

test('portal owners read credentials and curated decisions without staff actions', async ({ page }) => {
    const state = await fixture(page, { awarded: true });
    await page.goto('/portal/student/progress/');
    await page.getByRole('button', { name: 'Graduation & credentials', exact: true }).click();
    await expect(page.locator('.campus-student-main')).toContainText('Bachelor of Arts <b>fictional</b>');
    await expect(page.getByRole('button', { name: 'Review eligibility', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Credential decision history', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Credential conferred', exact: true })).toBeVisible();
    expect(state.errors).toEqual([]);
});


test('credential correction and revocation show the selected award and preserve separate confirmations', async ({ page }) => {
    const state = await fixture(page, { awarded: true });
    await open(page);
    await page.getByRole('button', { name: 'Correct title or honors', exact: true }).click();
    await page.getByLabel('Credential title', { exact: false }).fill('Bachelor of Arts in English');
    await page.getByLabel('Honors (optional)', { exact: true }).fill('With distinction');
    await page.getByLabel('Reason and supporting evidence', { exact: false }).fill('Fictional verified wording correction.');
    await page.getByRole('button', { name: 'Review decision', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('With distinction');
    await page.getByRole('button', { name: 'Confirm decision', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Bachelor of Arts in English');
    await page.getByRole('button', { name: 'Revoke credential', exact: true }).click();
    await page.getByLabel('Reason and supporting evidence', { exact: false }).fill('Fictional authorized revocation.');
    await page.getByRole('button', { name: 'Review decision', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Bachelor of Arts in English');
    await expect(page.locator('#drawer-body')).toContainText('This does not reopen the pursuit');
    await page.getByRole('button', { name: 'Confirm decision', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('No credential has been conferred');
    expect(state.calls.filter(c => c.path.endsWith('/graduation/confirm/')).map(c => c.data.action)).toEqual(['correct', 'revoke']);
    expect(state.errors).toEqual([]);
});

test('revising eligibility preserves the draft and explicit confirmations', async ({ page }) => {
    const state = await fixture(page, { eligible: false });
    await open(page);
    await page.getByRole('button', { name: 'Review eligibility', exact: true }).click();
    await page.getByLabel('Credential title', { exact: false }).fill('Bachelor of Arts');
    await page.getByLabel('Graduation date', { exact: false }).fill('2026-08-10');
    await page.getByLabel('Graduation term', { exact: false }).selectOption(term);
    await page.getByLabel('I verified the institution’s other graduation requirements and release clearances.', { exact: true }).check();
    await page.getByLabel('Reason and supporting evidence', { exact: false }).fill('Fictional retained review draft.');
    await page.getByRole('button', { name: 'Review decision', exact: true }).click();
    await page.getByRole('button', { name: 'Revise review', exact: true }).click();
    await expect(page.getByLabel('Credential title', { exact: false })).toHaveValue('Bachelor of Arts');
    await expect(page.getByLabel('Graduation term', { exact: false })).toHaveValue(term);
    await expect(page.getByLabel('I verified the institution’s other graduation requirements and release clearances.', { exact: true })).toBeChecked();
    await expect(page.getByLabel('Reason and supporting evidence', { exact: false })).toHaveValue('Fictional retained review draft.');
    expect(state.calls.filter(c => c.path.endsWith('/graduation/confirm/'))).toHaveLength(0);
    expect(state.errors).toEqual([]);
});


test('group confirmation shows the evidence retained by the review when the working reason changes during loading', async ({ page }) => {
    const state = await fixture(page);
    let release;
    state.reviewGate = new Promise(resolve => { release = resolve; });
    await open(page);
    await page.getByRole('button', { name: 'Group conferral queue', exact: true }).click();
    await page.getByLabel('Fictional Graduate 1 · S-FIX-1', { exact: true }).check();
    const reason = page.getByLabel('Reason and supporting evidence', { exact: true });
    await reason.fill('Fictional reviewed evidence.');
    await page.getByRole('button', { name: 'Review selected conferrals', exact: true }).click();
    await expect.poll(() => state.calls.filter(c => c.path === '/sis/graduation-conferral/review/').length).toBe(1);
    await reason.fill('Fictional working revision while loading.');
    release();
    await expect(page.locator('#drawer-body')).toContainText('Fictional reviewed evidence.');
    await expect(page.locator('#drawer-body')).not.toContainText('Fictional working revision while loading.');
    expect(state.errors).toEqual([]);
});
