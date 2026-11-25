const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function fixture(page) {
    const section = { id: id(1), code: 'SCI.501.A', course_id: id(2), term_id: id(3),
        course_code: 'SCI.501', course_title: 'Fictional research', term_code: '26/FA', capacity: 20,
        active: 1, web_registration: 1, fee_minor: 0, currency: 'USD', delivery_mode: 'online' };
    const programs = [{ id: id(11), code: 'ARTS.UG', title: 'Fictional <Arts>' },
        { id: id(12), code: 'SCI.UG', title: 'Fictional sciences' }];
    const levels = [{ id: id(21), code: 'UG', title: 'Undergraduate' }, { id: id(22), code: 'GR', title: 'Graduate' }];
    const state = { saved: [], revision: 'a'.repeat(64), failure: 0, deny: false, requests: [], errors: [] };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : {};
        state.requests.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path.startsWith('/sis/history-checks/')) {
            const kind = path.split('/')[3];
            const reference = path.split('/')[4];
            const receipt = { reference: id(90), status: kind === 'gradpolicy' ? 'observed_empty' : 'verified',
                events_checked: kind === 'gradpolicy' ? 0 : 12, checked_at: '2026-11-25T12:00:00Z' };
            result = path.endsWith('/history/') ? { items: [], next_after: null } : {
                result: { status: receipt.status, scope: kind === 'gradpolicy' ? 'graduate_registration_policy' : 'graduate_course_approvals',
                    [kind === 'gradpolicy' ? 'policy_reference' : 'pursuit_reference']: reference,
                    events_checked: receipt.events_checked, records_checked: kind === 'gradpolicy' ? 0 : 2, issues: [], records_changed: false },
                receipt, event_limit: 200,
            };
        } else if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = { items: path.includes('/sections/') ? [section] : path.includes('/students/') ? [{ id: id(30), given_name: 'Fictional', family_name: 'Student', institutional_id: 'S-FICTIONAL', active: 1 }] : [], next_after: null };
        else if (path.endsWith('/eligible-programs/current/')) {
            if (state.deny) return route.fulfill({ status: 403, json: { error: 'Access denied.' } });
            result = { section_code: section.code, program_ids: state.saved, programs: programs.filter(p => state.saved.includes(p.id)), revision: state.revision };
        } else if (path.endsWith('/eligible-programs/choices/')) result = { items: programs, next_after: null };
        else if (path.endsWith('/eligible-programs/save/')) {
            if (state.failure) return route.fulfill({ status: state.failure, json: { error: 'The policy changed.' } });
            state.saved = data.program_ids;
            state.revision = 'b'.repeat(64);
            result = { program_ids: state.saved, revision: state.revision };
        } else if (path === '/sis/graduate-registration-policy/' && state.policyDenied) return route.fulfill({ status: 403, json: { error: 'Access denied.' } });
        else if (path === '/sis/graduate-registration-policy/') result = { policy_reference: '01K00000000000000000000G31', publication_id: null, version: 0,
            rules: { enabled: false, undergraduate_level_id: null, graduate_level_id: null,
                minimum_gpa_milli: 2000, minimum_graduate_grade_milli: null }, levels: [] };
        else if (path.endsWith('/graduate-registration-policy/levels/')) result = { items: levels, next_after: null };
        else if (path.endsWith('/graduate-registration-policy/review/')) result = { ...data,
            publication_id: id(25), review_token: 'fictional-policy-proof' };
        else if (path.endsWith('/graduate-registration-policy/publish/')) result = { status: 'published', version: 1, publication_id: id(25) };
        else if (path.includes('/graduate-courses/') && path.endsWith('/current/') && state.approvalDenied) return route.fulfill({ status: 403, json: { error: 'Access denied.' } });
        else if (path.includes('/graduate-courses/') && path.endsWith('/current/')) result = {
            pursuit_id: id(31), version: 0, required: !state.noApprovalActions, can_advisor: !state.noApprovalActions, can_registrar: false,
            advisor_recorded: false, registrar_recorded: false,
            checks: [{ code: 'graduate_academic', label: 'Graduate-course academic eligibility', status: 'met', message: 'The current pursuit meets the academic policy.' },
                { code: 'graduate_advisor', label: 'Advisor approval', status: 'blocked', message: 'Assigned-advisor approval is required.' }],
        };
        else if (path.includes('/graduate-courses/') && path.endsWith('/review/')) result = { ...data, operation_id: id(40),
            revision: 'c'.repeat(64), effective_at: '2026-11-25T12:00:00.000000Z', review_token: 'fictional-approval-proof' };
        else if (path.includes('/graduate-courses/') && path.endsWith('/confirm/')) result = { status: 'recorded', operation_id: id(40), version: 1 };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.route('**/portal/api/courses/**', route => route.fulfill({ json: { items: [{ id: section.id,
        section_code: section.code, course_code: section.course_code, title: section.course_title, term_code: section.term_code }], next_after: null } }));
    return state;
}
async function sectionPolicy(page) {
    await page.goto('/admin/workspace/sections/');
    await page.getByRole('button', { name: 'SCI.501.A', exact: true }).click();
    await page.getByRole('button', { name: 'Eligible programs', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Selected programs' })).toBeVisible();
}
async function noOverflow(page) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    expect(await page.locator('#drawer-body').evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
}

test('section policy retains selections, requires explicit clearing and preserves record navigation', async ({ page }) => {
    const state = await fixture(page);
    await sectionPolicy(page);
    await page.getByLabel('ARTS.UG — Fictional <Arts>', { exact: true }).check();
    await page.getByRole('button', { name: 'Save eligible programs', exact: true }).click();
    await expect(page.getByText('Program eligibility draft saved.', { exact: false })).toBeVisible();
    expect(state.saved).toEqual([id(11)]);
    await expect(page.locator('#drawer-body script')).toHaveCount(0);
    await page.getByRole('button', { name: 'Remove program: ARTS.UG', exact: true }).click();
    await page.getByRole('button', { name: 'Save eligible programs', exact: true }).click();
    await expect.poll(() => state.saved.length).toBe(0);
    expect(state.requests.filter(r => r.path.endsWith('/eligible-programs/save/')).every(r => r.csrf === 'fictional-test-token')).toBe(true);
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Published section details', exact: true })).toBeVisible();
    expect(state.errors).toEqual([]);
});

test('section policy preserves stale choices and clears private controls after denied reload', async ({ page }) => {
    const state = await fixture(page);
    await sectionPolicy(page);
    await page.getByLabel('ARTS.UG — Fictional <Arts>', { exact: true }).check();
    state.failure = 409;
    await page.getByRole('button', { name: 'Save eligible programs', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Your choices remain' })).toBeVisible();
    await expect(page.getByLabel('ARTS.UG — Fictional <Arts>', { exact: true })).toBeChecked();
    state.deny = true;
    await page.getByRole('button', { name: 'Reload saved policy', exact: true }).click();
    await expect(page.getByText('Program eligibility is no longer available to this account.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save eligible programs', exact: true })).toHaveCount(0);
    expect(state.errors).toEqual([]);
});

test('institutional graduate policy has explicit levels, registration-only GPA and all-course grade rules', async ({ page }) => {
    const state = await fixture(page);
    await page.goto('/admin/workspace/registration/');
    await page.getByRole('button', { name: 'Undergraduate access to graduate courses', exact: true }).click();
    await page.getByLabel('Require graduate-course eligibility and approvals', { exact: true }).check();
    await page.getByLabel('Undergraduate academic level', { exact: true }).selectOption(id(21));
    await page.getByLabel('Graduate academic level', { exact: true }).selectOption(id(22));
    await expect(page.getByLabel('Minimum current undergraduate pursuit GPA', { exact: true })).toHaveValue('2.000');
    await page.getByLabel('Minimum grade points in every completed graduate course', { exact: true }).fill('3.000');
    await page.getByLabel('Reason for this decision', { exact: true }).fill('Fictional institutional approval.');
    await page.setViewportSize({ width: 320, height: 900 });
    await noOverflow(page);
    await page.screenshot({ path: '/tmp/etsis-graduate-policy-phone.png' });
    const scan = await new AxeBuilder({ page }).include('#record-drawer')
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => v.id)).toEqual([]);
    await page.getByRole('button', { name: 'Review policy', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Review graduate-course policy', exact: true })).toBeVisible();
    await expect(page.getByText('3.000', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Publish policy', exact: true }).click();
    await expect(page.getByText('Graduate-course registration policy published.', { exact: true })).toBeVisible();
    const posted = state.requests.find(r => r.path.endsWith('/graduate-registration-policy/publish/'));
    expect(posted.data.rules.minimum_graduate_grade_milli).toBe(3000);
    expect(posted.csrf).toBe('fictional-test-token');
    expect(state.errors).toEqual([]);
});

test('section policy reflows with keyboard access at 320px and 200% zoom', async ({ page }) => {
    await fixture(page);
    await page.setViewportSize({ width: 320, height: 900 });
    await sectionPolicy(page);
    await page.getByLabel('ARTS.UG — Fictional <Arts>', { exact: true }).focus();
    await page.keyboard.press('Space');
    await expect(page.getByLabel('ARTS.UG — Fictional <Arts>', { exact: true })).toBeChecked();
    await noOverflow(page);
    await page.screenshot({ path: '/tmp/etsis-section-program-phone.png' });
    const scan = await new AxeBuilder({ page }).include('#record-drawer').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => v.id)).toEqual([]);
    await page.setViewportSize({ width: 640, height: 900 });
    await page.evaluate(() => { document.body.style.zoom = '2'; });
    await noOverflow(page);
});

test('graduate approvals use a reviewed advisor decision and retain back navigation', async ({ page }) => {
    const state = await fixture(page);
    await page.goto('/admin/workspace/students/');
    await page.getByRole('searchbox', { name: 'Search this workspace', exact: true }).fill('Fictional');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await page.getByRole('button', { name: 'Fictional Student', exact: true }).click();
    await page.getByRole('button', { name: 'Review graduate-course approvals', exact: true }).click();
    await page.getByRole('button', { name: 'SCI.501 · SCI.501.A · 26/FA — Fictional research', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Advisor approval', exact: true })).toBeVisible();
    await page.getByLabel('Reason for this decision', { exact: true }).fill('Fictional advisor review.');
    await page.getByRole('button', { name: 'Review decision', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Approve as assigned advisor', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Confirm decision', exact: true }).click();
    await expect(page.getByText('Graduate-course decision recorded.', { exact: false })).toBeVisible();
    expect(state.requests.find(r => r.path.endsWith('/graduate-courses/00000000000000000000000001/confirm/')).data.action).toBe('advisor_approve');
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByLabel('Choose a graduate course section', { exact: true })).toBeVisible();
    expect(state.errors).toEqual([]);
});

test('policy history returns to the original unsaved draft and restores focus', async ({ page }) => {
    const state = await fixture(page);
    await page.goto('/admin/workspace/registration/');
    await page.getByRole('button', { name: 'Undergraduate access to graduate courses', exact: true }).click();
    await page.getByLabel('Minimum current undergraduate pursuit GPA', { exact: true }).fill('3.125');
    await page.getByLabel('Reason for this decision', { exact: true }).fill('Fictional unfinished policy reason.');
    await page.getByRole('button', { name: 'Check policy history', exact: true }).click();
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'No graduate decision observed', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByLabel('Minimum current undergraduate pursuit GPA', { exact: true })).toHaveValue('3.125');
    await expect(page.getByLabel('Reason for this decision', { exact: true })).toHaveValue('Fictional unfinished policy reason.');
    await expect(page.getByRole('button', { name: 'Check policy history', exact: true })).toBeFocused();
    expect(state.requests.some(r => r.path.endsWith('/graduate-registration-policy/publish/'))).toBe(false);
    expect(state.errors).toEqual([]);
});

test('approval history returns to the same pursuit draft at phone size', async ({ page }) => {
    const state = await fixture(page);
    await page.goto('/admin/workspace/students/');
    await page.getByRole('searchbox', { name: 'Search this workspace', exact: true }).fill('Fictional');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await page.getByRole('button', { name: 'Fictional Student', exact: true }).click();
    await page.getByRole('button', { name: 'Review graduate-course approvals', exact: true }).click();
    await page.getByRole('button', { name: 'SCI.501 · SCI.501.A · 26/FA — Fictional research', exact: true }).click();
    await page.getByLabel('Reason for this decision', { exact: true }).fill('Fictional unfinished approval reason.');
    await page.setViewportSize({ width: 320, height: 900 });
    await page.getByRole('button', { name: 'Check pursuit approval history', exact: true }).click();
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Verified within this check', exact: true })).toBeVisible();
    await noOverflow(page);
    const scan = await new AxeBuilder({ page }).include('#record-drawer')
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => v.id)).toEqual([]);
    await page.screenshot({ path: '/tmp/etsis-graduate-history-phone.png' });
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByLabel('Reason for this decision', { exact: true })).toHaveValue('Fictional unfinished approval reason.');
    await expect(page.getByRole('button', { name: 'Check pursuit approval history', exact: true })).toBeFocused();
    expect(state.requests.some(r => r.path.endsWith('/graduate-courses/00000000000000000000000001/confirm/'))).toBe(false);
    expect(state.errors).toEqual([]);
});

test('lost policy authority prevents restoration of its draft after a history check', async ({ page }) => {
    const state = await fixture(page);
    await page.goto('/admin/workspace/registration/');
    await page.getByRole('button', { name: 'Undergraduate access to graduate courses', exact: true }).click();
    await page.getByLabel('Reason for this decision', { exact: true }).fill('Fictional private policy draft.');
    await page.getByRole('button', { name: 'Check policy history', exact: true }).click();
    state.policyDenied = true;
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByText('This information is no longer available to this account.', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Reason for this decision', { exact: true })).toHaveCount(0);
    await expect(page.getByText('Fictional private policy draft.', { exact: true })).toHaveCount(0);
    expect(state.errors).toEqual([]);
});

async function openApprovals(page) {
    await page.goto('/admin/workspace/students/');
    await page.getByRole('searchbox', { name: 'Search this workspace', exact: true }).fill('Fictional');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await page.getByRole('button', { name: 'Fictional Student', exact: true }).click();
    await page.getByRole('button', { name: 'Review graduate-course approvals', exact: true }).click();
    await page.getByRole('button', { name: 'SCI.501 · SCI.501.A · 26/FA — Fictional research', exact: true }).click();
}

test('support can inspect pursuit history when this section offers no approval action', async ({ page }) => {
    const state = await fixture(page);
    state.noApprovalActions = true;
    await openApprovals(page);
    await expect(page.getByRole('button', { name: 'Review decision', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Check pursuit approval history', exact: true }).click();
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Verified within this check', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Check pursuit approval history', exact: true })).toBeFocused();
    expect(state.errors).toEqual([]);
});

test('lost approval access clears the private draft on return from support', async ({ page }) => {
    const state = await fixture(page);
    await openApprovals(page);
    await page.getByLabel('Reason for this decision', { exact: true }).fill('Fictional private approval draft.');
    await page.getByRole('button', { name: 'Check pursuit approval history', exact: true }).click();
    state.approvalDenied = true;
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByText('This information is no longer available to this account.', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Reason for this decision', { exact: true })).toHaveCount(0);
    expect(state.errors).toEqual([]);
});
