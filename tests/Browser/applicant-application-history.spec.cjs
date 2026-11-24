const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
const original = { program: { code: 'SCI-UG', title: 'Fictional Science' }, entry_term: { code: '26/FA' },
    effective_at: '2026-07-01T10:00:00Z', recorded_at: '2026-07-01T10:00:01Z' };
const current = { status: 'submitted', program: { code: 'ARTS-UG', title: 'Fictional Arts' }, entry_term: { code: '27/FA' } };
const fact = (kind, extra = {}) => ({ kind, status: 'submitted', effective_at: '2026-07-02T10:00:00Z',
    recorded_at: '2026-07-02T10:00:01Z', ...extra });
const packet = extra => ({ current, original, earlier_history_unknown: false, checked_at: '2026-07-03T10:00:00Z',
    items: [fact('program_correction', { program: { before: original.program, after: current.program } })], next_cursor: null, ...extra });
async function fixture(page, { history, update = false, form = false } = {}) {
    const reads = [], writes = [], errors = [];
    page.on('pageerror', e => errors.push(e.message));
    const applications = [1, 2].map(n => ({ id: id(n), person_id: id(5), program_id: id(6), entry_term_id: id(7), status: 'submitted',
        program_code: n === 1 ? 'ARTS-UG' : 'SECOND-UG', program_title: n === 1 ? 'Fictional Arts' : 'Fictional Second', term_code: '27/FA',
        submission_id: form && n === 1 ? id(8) : null }));
    const answers = { application_id: id(1), form: { id: id(9), name: 'Fictional original form',
        fields: [{ key: 'statement', label: 'Statement', type: 'textarea', required: true }], layout: [] },
        answers: { statement: 'Fictional original answer.' }, submitted_term_code: '26/FA', can_revise: true, versions: [], application_status: 'submitted' };
    await page.route(/\/(?:portal\/api|sis\/form-submissions)\//, async route => {
        const req = route.request(), url = new URL(req.url()), path = url.pathname;
        reads.push(url.pathname + url.search); if (req.method() !== 'GET') writes.push(path);
        let json = {};
        if (path.endsWith('/history/')) return history(route, url, reads);
        if (path === '/portal/api/applications/') json = { person_id: id(5), items: applications };
        else if (path === '/portal/api/me/') json = { person: { id: id(5) }, applications, students: [], contact: {}, accounts: [], news: [] };
        else if (path === '/portal/api/admissions-updates/') json = { items: [{ id: id(10), application_id: id(1), revision: 2,
            unread: true, program_code: 'SCI-UG', program_title: 'Fictional Science', term_code: '26/FA', updated_at: '2026-07-03T10:00:00Z' }], unread_count: 1, next: null };
        else if (path.endsWith('/read/')) json = { unread: false };
        else if (path.endsWith('/next-steps/')) json = { office: 'Admissions', next_step: 'Arrange your fictional transcript.', items: [] };
        else if (path.startsWith('/portal/api/profile-update-requests/')) json = { items: [], next_after: null, areas: {} };
        else if (path === '/portal/api/education/') json = { entries: [], version: 0 };
        else if (path.endsWith('/answers/') || path.startsWith('/sis/form-submissions/')) json = answers;
        else throw new Error(`Unexpected history fixture request: ${path}`);
        await route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(update ? '/portal/admissions-updates/' : '/portal/apply/');
    await expect(page.getByRole('heading', { name: update ? 'Admissions updates' : 'Your applications', exact: true })).toBeVisible();
    return { reads, writes, errors };
}
const trigger = page => page.getByRole('button', { name: 'Application history for ARTS-UG · Fictional Arts · 27/FA', exact: true });
const detail = page => page.getByRole('region', { name: 'Application history', exact: true });
const reply = (route, json) => route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });

test('history is on demand, retains failed pages, caches previous pages and reuses next steps', async ({ page }) => {
    let next = 0;
    const f = await fixture(page, { history: async (route, url) => {
        if (url.search) {
            if (++next === 1) return route.fulfill({ status: 503, json: { message: 'Fictional temporary outage.' } });
            return reply(route, packet({ current: { ...current, status: 'rejected' }, items: [fact('entry_correct', { entry: {
                before: { code: '26/FA', name: 'Fictional fall', starts_on: '2026-08-15', ends_on: '2026-12-15' },
                after: { code: '27/FA', name: '<img src=x> Fictional fall', starts_on: '2027-08-15', ends_on: '2027-12-15', academic_year: '2027-2028' },
            } })] }));
        }
        return reply(route, packet({ next_cursor: 'fictional-fixed-head' }));
    } });
    expect(f.reads.some(p => p.includes('/history/'))).toBe(false);
    await trigger(page).click();
    await expect(detail(page)).toContainText('Original submission');
    await expect(detail(page)).toContainText('Fictional Science');
    await expect(detail(page)).toContainText('Fictional Arts');
    await page.getByRole('button', { name: 'Next history', exact: true }).click();
    await expect(detail(page)).toContainText('Previously loaded details are still shown');
    await expect(detail(page)).toContainText('Planned program corrected');
    await page.getByRole('button', { name: 'Next history', exact: true }).click();
    await expect(detail(page)).toContainText('Planned intake corrected');
    await expect(detail(page)).toContainText('Academic year: 2027-2028');
    await expect(detail(page)).toContainText('Current status: Submitted');
    await expect(detail(page).locator('img')).toHaveCount(0);
    const requests = f.reads.filter(p => p.includes('/history/')).length;
    await page.getByRole('button', { name: 'Previous history', exact: true }).click();
    await expect(detail(page)).toContainText('Planned program corrected');
    await page.getByRole('button', { name: 'Next history', exact: true }).click();
    expect(f.reads.filter(p => p.includes('/history/'))).toHaveLength(requests);
    await page.getByRole('button', { name: 'Open next steps', exact: true }).click();
    await expect(detail(page)).toBeHidden();
    await expect(page.getByRole('region', { name: 'Application next steps', exact: true })).toContainText('Arrange your fictional transcript.');
    expect(f.writes).toEqual([]); expect(f.errors).toEqual([]);
});

test('legacy unknowns, empty private pages, keyboard focus and enlarged narrow layouts remain clear', async ({ page }) => {
    const f = await fixture(page, { history: (route, url) => reply(route, url.search ? packet({ original: null,
        earlier_history_unknown: true, items: [fact('observation', { effective_at: null })] })
        : packet({ original: null, earlier_history_unknown: true, items: [], next_cursor: 'fictional-hidden-page' })) });
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await trigger(page).press('Enter');
    await expect(detail(page).getByRole('heading', { name: 'Application history', exact: true })).toBeFocused();
    await expect(detail(page)).toContainText('Original submission details were not recorded');
    await expect(detail(page)).toContainText('This page has no applicant-visible changes');
    await page.getByRole('button', { name: 'Next history', exact: true }).press('Enter');
    await expect(detail(page)).toContainText('Earlier decision timing is unknown');
    const overflow = await detail(page).evaluate(el => {
        if (el.scrollWidth <= el.clientWidth + 1) return [];
        const right = el.getBoundingClientRect().right;
        return [...el.querySelectorAll('*')].filter(child => child.getBoundingClientRect().right > right + 1)
            .map(child => ({ tag: child.tagName, class: child.className,
                right: child.getBoundingClientRect().right, width: child.getBoundingClientRect().width }));
    });
    expect(overflow).toEqual([]);
    const scan = await new AxeBuilder({ page }).include('section.campus-application-history:not([hidden])')
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, nodes: v.nodes.map(n => n.target) }))).toEqual([]);
    await detail(page).screenshot({ path: '/tmp/etsis-applicant-history-phone.png' });
    await page.getByRole('button', { name: 'Close application history', exact: true }).click();
    await expect(trigger(page)).toBeFocused();
    expect(f.errors).toEqual([]);
});

test('access loss removes cached private details and refresh never silently changes an earlier page', async ({ page }) => {
    let reads = 0;
    await fixture(page, { history: route => ++reads === 1 ? reply(route, packet())
        : route.fulfill({ status: 403, json: { message: 'Fictional access change.' } }) });
    await trigger(page).click();
    await expect(detail(page)).toContainText('Fictional Science');
    await page.getByRole('button', { name: 'Refresh history', exact: true }).click();
    await expect(detail(page)).toContainText('no longer available to your account');
    await expect(detail(page)).not.toContainText('Fictional Science');
    await expect(detail(page)).not.toContainText('Fictional Arts');
    await expect(detail(page).getByRole('button', { name: 'Previous history', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Close application history', exact: true })).toBeVisible();
});

test('a late response cannot reopen or replace a different application history', async ({ page }) => {
    let release, waiting;
    const held = new Promise(resolve => waiting = resolve);
    const f = await fixture(page, { history: async (route, url) => {
        if (url.pathname.includes(id(1))) {
            await new Promise(resolve => { release = resolve; waiting(); });
            return reply(route, packet());
        }
        return reply(route, packet({ current: { ...current, program: { code: 'SECOND-UG', title: 'Second private application' } },
            original: null, earlier_history_unknown: true, items: [] }));
    } });
    const pending = trigger(page).click(); await held; await pending;
    await page.getByRole('button', { name: 'Close application history', exact: true }).click();
    await page.getByRole('button', { name: 'Application history for SECOND-UG · Fictional Second · 27/FA', exact: true }).click();
    await expect(detail(page)).toContainText('Second private application');
    const firstResponse = page.waitForResponse(response => response.url().includes(`/applications/${id(1)}/history/`));
    release(); await firstResponse;
    await page.evaluate(() => new Promise(requestAnimationFrame));
    await expect(detail(page)).toContainText('Second private application');
    await expect(detail(page)).not.toContainText('Fictional Science');
    expect(f.errors).toEqual([]);
});

test('admissions updates open history without loading the student overview or changing the read receipt', async ({ page }) => {
    const f = await fixture(page, { update: true, history: route => reply(route, packet()) });
    await page.getByRole('button', { name: 'Read update', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Admissions update details' })).toContainText('Update marked read');
    expect(f.reads.some(p => p.includes('/history/'))).toBe(false);
    await page.getByRole('button', { name: 'Application history for SCI-UG · 26/FA', exact: true }).click();
    await expect(detail(page)).toContainText('Current planned program and intake');
    await expect(detail(page)).toContainText('Fictional Arts');
    expect(f.writes).toEqual([`/portal/api/admissions-updates/${id(10)}/read/`]);
    expect(f.reads).not.toContain('/portal/api/me/'); expect(f.errors).toEqual([]);
});

test('opening history respects an unfinished application-answer revision', async ({ page }) => {
    const f = await fixture(page, { form: true, history: route => reply(route, packet()) });
    await page.getByRole('button', { name: 'View answers for ARTS-UG · Fictional Arts · 27/FA', exact: true }).click();
    await page.getByRole('button', { name: 'Revise answers', exact: true }).click();
    await page.getByRole('textbox', { name: 'Statement', exact: true }).fill('Unsaved fictional answer revision.');
    await trigger(page).click();
    await expect(page.getByRole('textbox', { name: 'Statement', exact: true })).toHaveValue('Unsaved fictional answer revision.');
    expect(f.reads.some(p => p.includes('/history/'))).toBe(false);
    page.once('dialog', dialog => dialog.accept());
    await trigger(page).click();
    await expect(detail(page)).toContainText('Planned program corrected');
    expect(f.writes).toEqual([]); expect(f.errors).toEqual([]);
});
