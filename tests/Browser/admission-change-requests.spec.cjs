const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { workspaceTool } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
const pending = (area = 'program') => ({ id: id(10), area, status: 'requested', version: 0,
    message: 'Fictional <img src=x onerror=alert(1)> request.', resolution: null,
    created_at: '2026-11-24T10:00:00Z', resolved_at: null, application_changed: false, owner_changed: false });
async function fixture(page, { staff = false, items = [], retry = false, denied = false, area = 'program', theme = '', updates = false } = {}) {
    const errors = [], writes = [], reads = []; let attempts = 0, revision = 0, saved = items, current = 'SCI-UG';
    page.on('pageerror', error => errors.push(error.message));
    await page.route(/\/(?:sis|portal\/api)\//, async route => {
        const req = route.request(), url = new URL(req.url()), path = url.pathname;
        const raw = req.postData(), body = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (body) writes.push({ path, body }); else reads.push(path + url.search);
        let json = {};
        if (path.includes('/change-requests/')) {
            if (req.method() === 'GET') json = { items: saved.filter(item => (item.status !== 'requested') === (url.searchParams.get('finished') === '1')),
                next_after: null, new_id: id(11), application_revision: revision, application_status: 'submitted' };
            else {
                if (denied) return route.fulfill({ status: 403, json: { message: 'Fictional access loss.' } });
                if (path.endsWith('/request/')) saved = [{ ...pending(body.area), id: body.request_id, message: body.message }];
                else saved = saved.map(item => item.id === body.request_id ? { ...item, status: path.endsWith('/cancel/') ? 'cancelled' : 'closed',
                    version: 1, resolution: body.message, resolved_at: '2026-11-24T10:01:00Z' } : item);
                if (retry && ++attempts === 1) return route.fulfill({ status: 503, json: { message: 'Fictional lost response.' } });
                json = { id: body.request_id, status: saved[0].status, version: saved[0].version };
            }
        } else if (path === '/sis/ui/metrics/') json = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) json = { items: path.includes('/applications/')
            ? [{ id: id(1), given_name: 'Fictional', family_name: 'Applicant', status: 'submitted' }] : [], next_after: null };
        else if (path === `/sis/applications/${id(1)}/review/`) json = {
            application: { id: { value: id(1) }, status: 'submitted' },
            person: { id: { value: id(2) }, givenName: 'Fictional', familyName: 'Applicant', institutionalId: { value: 'F-123' } },
            labels: { program_code: current, program_title: 'Fictional program', term_code: '2027/FA' },
        };
        else if (path === '/portal/api/me/' || path === '/portal/api/applications/') json = {
            person: { id: id(2) }, person_id: id(2), applications: [], students: [], contact: {}, accounts: [], news: [],
            items: [{ id: id(1), program_code: 'SCI-UG', program_title: 'Fictional program', term_code: '27/FA', status: 'submitted' }],
        };
        else if (path.startsWith('/portal/api/profile-update-requests/')) json = { items: [], next_after: null, areas: {} };
        else if (path === '/portal/api/education/') json = { entries: [], version: 0 };
        else if (path === '/portal/api/admissions-updates/') json = { items: [{ id: id(20), application_id: id(1),
            revision: 1, unread: true, program_code: 'SCI-UG', program_title: 'Fictional program', term_code: '27/FA',
            updated_at: '2026-11-24T10:01:00.000000Z' }], unread_count: 1, next: null };
        else if (path.endsWith('/read/')) json = { unread: false };
        else if (path.endsWith('/next-steps/')) json = { office: 'Admissions', next_step: 'Review the correction reply.', items: [] };
        else if (path.endsWith('/program/choices/')) json = { items: [{ id: id(4), code: 'ARTS-UG', title: 'Fictional Arts' }], next: null };
        else if (path.endsWith('/program/review/')) json = { person: { given_name: 'Fictional', family_name: 'Applicant', institutional_id: 'F-123' },
            before: { code: current, title: 'Fictional program' }, after: { code: 'ARTS-UG', title: 'Fictional Arts' }, review_token: 'fictional-program-review' };
        else if (path.endsWith('/program/commit/')) { revision++; current = 'ARTS-UG'; saved = saved.map(item => ({ ...item, application_changed: true })); json = { status: 'submitted' }; }
        else if (path.endsWith('/program/')) json = { current: { code: current, title: 'Fictional program' }, status: 'submitted',
            program_form: false, answers_pending: false, withdrawal_pending: false };
        else if (path.endsWith('/entry/')) json = { application_status: 'submitted', status: 'submitted',
            person: { given_name: 'Fictional', family_name: 'Applicant', institutional_id: 'F-123' },
            current: { id: id(8), code: '27/FA', name: 'Fictional fall', starts_on: '2027-08-15', ends_on: '2027-12-15' },
            choices: [], pending_request: false };
        await route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(staff ? '/admin/' : `/portal/apply/${theme ? '?theme=' + theme : ''}`);
    if (staff) {
        await page.locator('.workspace-nav-link[data-workspace="applications"]').click();
        await page.getByRole('button', { name: 'Fictional Applicant', exact: true }).click();
    }
    if (updates) {
        await page.goto('/portal/admissions-updates/');
        await page.getByRole('button', { name: 'Read update', exact: true }).click();
    }
    const toggle = page.getByRole('button', { name: /^Application correction requests/ });
    await expect(toggle).toBeVisible();
    expect(reads.some(path => path.includes('/change-requests/'))).toBe(false);
    await toggle.click();
    const panel = page.getByRole('region', { name: 'Application correction requests', exact: true });
    await expect(panel.getByRole('button', { name: 'Refresh requests', exact: true })).toBeVisible();
    return { errors, writes, reads, panel, area };
}
test('owner sends, retries exactly, cancels and reads a safely rendered reply without changing application facts', async ({ page }) => {
    const f = await fixture(page, { retry: true });
    await f.panel.getByLabel('Describe the correction you would like').fill('Fictional <script>private request</script>.');
    await f.panel.getByRole('button', { name: 'Send request', exact: true }).click();
    await expect(f.panel).toContainText('captured action and draft are still here');
    await f.panel.getByRole('button', { name: 'Retry captured action', exact: true }).click();
    await expect(f.panel).toContainText('Fictional <script>private request</script>.');
    expect(f.writes[0]).toEqual(f.writes[1]);
    await expect(f.panel.locator('script')).toHaveCount(0);
    await f.panel.getByRole('button', { name: 'Cancel request', exact: true }).click();
    await f.panel.getByRole('button', { name: 'Closed requests', exact: true }).click();
    await expect(f.panel).toContainText('Cancelled by applicant');
    expect(f.writes.every(write => write.path.includes('/change-requests/'))).toBe(true);
    expect(await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }))).not.toContain('private request');
    expect(f.errors).toEqual([]);
});
test('staff preserves a reply through the real program correction editor and closes using the fresh application revision', async ({ page }) => {
    const f = await fixture(page, { staff: true, items: [pending()] });
    await f.panel.getByLabel('Reply visible to the applicant').fill('Fictional reply draft retained.');
    await f.panel.getByRole('button', { name: 'Review program correction', exact: true }).click();
    await page.getByRole('button', { name: 'Find programs', exact: true }).click();
    await page.getByLabel('ARTS-UG · Fictional Arts', { exact: true }).check();
    await page.getByLabel('Reason for program correction', { exact: true }).fill('Reviewed fictional request.');
    await page.getByRole('button', { name: 'Review program correction', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm program correction', exact: true }).click();
    await expect(f.panel.getByLabel('Reply visible to the applicant')).toHaveValue('Fictional reply draft retained.');
    await expect(f.panel).toContainText('application has changed');
    await f.panel.getByRole('button', { name: 'Reply and close follow-up', exact: true }).click();
    const closed = f.writes.find(write => write.path.endsWith('/close/'));
    expect(closed.body.application_revision).toBe(1);
    await f.panel.getByRole('button', { name: 'Closed requests', exact: true }).click();
    await expect(f.panel).toContainText('Fictional reply draft retained.');
    expect(f.errors).toEqual([]);
});
test('denied write clears private captured action and drafts', async ({ page }) => {
    const f = await fixture(page, { denied: true });
    await f.panel.getByLabel('Describe the correction you would like').fill('Fictional sensitive draft.');
    await f.panel.getByRole('button', { name: 'Send request', exact: true }).click();
    await expect(f.panel).toContainText('no longer available');
    await expect(f.panel.locator('textarea')).toHaveCount(0);
    await expect(f.panel).not.toContainText('Fictional sensitive draft.');
    expect(f.errors).toEqual([]);
});
test('an accepted action with a failed refresh waits for fresh state before another write', async ({ page }) => {
    const f = await fixture(page); let fail = true;
    await page.route('**/change-requests/?*', route => fail
        ? route.fulfill({ status: 503, json: { message: 'Fictional read outage.' } }) : route.fallback());
    await f.panel.getByLabel('Describe the correction you would like').fill('Fictional accepted request.');
    await f.panel.getByRole('button', { name: 'Send request', exact: true }).click();
    await expect(f.panel).toContainText('Follow-up saved.');
    await expect(f.panel).toContainText('could not be refreshed');
    await expect(f.panel.getByRole('button', { name: 'Send request', exact: true })).toBeDisabled();
    fail = false; await f.panel.getByRole('button', { name: 'Refresh requests', exact: true }).click();
    await expect(f.panel).toContainText('Awaiting admissions review');
    expect(f.writes.filter(write => write.path.includes('/change-requests/'))).toHaveLength(1);
    expect(f.errors).toEqual([]);
});
test('entry-term editor returns to the same request with the staff reply intact', async ({ page }) => {
    const f = await fixture(page, { staff: true, items: [pending('entry_term')] });
    await f.panel.getByLabel('Reply visible to the applicant').fill('Fictional intake reply draft.');
    await f.panel.getByRole('button', { name: 'Review entry-term correction', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Current entry term');
    await page.getByRole('button', { name: 'Back to application', exact: true }).click();
    await expect(f.panel.getByLabel('Reply visible to the applicant')).toHaveValue('Fictional intake reply draft.');
    expect(f.errors).toEqual([]);
});
test('follow-up queue opens applicant requests without staff checklist work and restores the accepted filter', async ({ page }) => {
    const f = await fixture(page, { staff: true, items: [pending()] });
    await page.route('**/sis/admissions/follow-ups/?*', route => route.fulfill({ json: {
        items: [{ id: id(1), application_id: id(1), given_name: 'Fictional', family_name: 'Applicant',
            institutional_id: 'F-123', office: null, assignee_name: 'Unassigned', work_status: null,
            next_due_on: null, requests_pending: 1, casework_missing: true }], next_after: null, today: '2026-11-24',
    } }));
    await page.locator('#record-drawer .btn-close').click();
    await workspaceTool(page, 'Open admissions follow-up queue');
    await page.getByLabel('Assignment', { exact: true }).selectOption('unassigned');
    await page.getByRole('button', { name: 'Refresh queue', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Staff checklist not started');
    await expect(page.locator('#drawer-body')).toContainText('Applicant correction requests: 1');
    await page.getByRole('button', { name: 'Open application', exact: true }).click();
    await expect(f.panel).toBeVisible();
    await expect(f.panel).toContainText('Fictional <img src=x onerror=alert(1)> request.');
    await page.getByRole('button', { name: 'Back to follow-up queue', exact: true }).click();
    await expect(page.getByLabel('Assignment', { exact: true })).toHaveValue('unassigned');
    expect(f.errors).toEqual([]);
});
test('owner reads the staff reply from the existing admissions update without loading student context', async ({ page }) => {
    const f = await fixture(page, { updates: true, items: [{ ...pending(), status: 'closed', version: 1,
        resolution: 'Fictional owner-visible resolution.', resolved_at: '2026-11-24T10:01:00Z' }] });
    await f.panel.getByRole('button', { name: 'Closed requests', exact: true }).click();
    await expect(f.panel).toContainText('Fictional owner-visible resolution.');
    expect(f.reads.some(path => path === '/portal/api/me/')).toBe(false);
    expect(f.writes.filter(write => write.path.endsWith('/read/'))).toHaveLength(1);
    expect(f.errors).toEqual([]);
});
for (const theme of ['campus-quad', 'campus-horizon']) test(theme + ' exposes the same private owner correction workflow', async ({ page }) => {
    const f = await fixture(page, { theme });
    await f.panel.getByLabel('Describe the correction you would like').fill('Fictional inherited-theme request.');
    await f.panel.getByRole('button', { name: 'Send request', exact: true }).click();
    await expect(f.panel).toContainText('Awaiting admissions review');
    expect(f.errors).toEqual([]);
});
test('unsent staff reply survives failed refresh and closing the drawer requires a deliberate discard', async ({ page }) => {
    const f = await fixture(page, { staff: true, items: [pending('entry_term')] });
    await f.panel.getByLabel('Reply visible to the applicant').fill('Fictional unsent reply.');
    await page.route('**/change-requests/?*', route => route.fulfill({ status: 503, json: { message: 'Fictional temporary outage.' } }));
    await f.panel.getByRole('button', { name: 'Refresh requests', exact: true }).click();
    await expect(f.panel).toContainText('could not be refreshed');
    await expect(f.panel.getByLabel('Reply visible to the applicant')).toHaveValue('Fictional unsent reply.');
    page.once('dialog', dialog => dialog.dismiss());
    await page.locator('#record-drawer .btn-close').click();
    await expect(f.panel).toBeVisible();
    expect(f.writes.filter(write => write.path.includes('/change-requests/'))).toEqual([]); expect(f.errors).toEqual([]);
});
for (const staff of [false, true]) test(`${staff ? 'staff' : 'owner'} correction controls support narrow enlarged text and keyboard/WCAG`, async ({ page }) => {
    const f = await fixture(page, { staff, items: staff ? [pending('entry_term')] : [] });
    await page.setViewportSize({ width: 320, height: 850 });
    await page.addStyleTag({ content: 'html { font-size: 32px; }' });
    await expect(f.panel.locator('textarea').first()).toBeVisible();
    expect((await f.panel.locator('textarea').first().boundingBox()).width).toBeGreaterThan(175);
    await f.panel.locator('textarea').first().focus(); await page.keyboard.type('Fictional keyboard draft.');
    await page.screenshot({ path: `/tmp/etsis-admission-changes-${staff ? 'staff' : 'owner'}-phone.png` });
    expect(await f.panel.evaluate(el => [...el.querySelectorAll('*')].filter(child => child.getBoundingClientRect().right > el.getBoundingClientRect().right + 1)
        .map(child => ({ tag: child.tagName, class: child.className, width: child.getBoundingClientRect().width })))).toEqual([]);
    expect((await new AxeBuilder({ page }).include(staff ? '#record-drawer' : 'main').analyze()).violations).toEqual([]);
    expect(f.errors).toEqual([]);
});
