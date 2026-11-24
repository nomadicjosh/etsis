const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
const term = (n, year) => ({ id: id(n), code: `${year}/FA`, name: `Fictional fall ${year}`, starts_on: `${year}-08-15`, ends_on: `${year}-12-15`, year_code: `${year}-${year + 1}` });
async function fixture(page, options = {}) {
    const original = term(3, 2026), target = term(4, 2027);
    let current = original, reviewedTarget = target;
    const posted = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) posted.push({ path, data });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = {
            items: path.includes('/applications/') ? [{ id: id(1), given_name: 'Fictional', family_name: 'Applicant', status: options.accepted ? 'accepted' : 'submitted' }] : [], next_after: null,
        };
        else if (path === `/sis/applications/${id(1)}/review/`) result = {
            application: { id: { value: id(1) }, status: options.accepted ? 'accepted' : 'submitted' },
            person: { id: { value: id(2) }, givenName: 'Fictional', familyName: 'Applicant', institutionalId: { value: 'F-123' } },
            labels: { program_code: 'ARTS-UG', program_title: 'Fictional Arts', term_code: current.code },
        };
        else if (path.endsWith('/entry/terms/')) result = { items: [target], next: null };
        else if (path.endsWith('/entry/review/')) {
            if (options.denied) return route.fulfill({ status: 403, json: { message: 'Fictional access loss.' } });
            reviewedTarget = data.target_term === current.id ? current : target;
            result = { person: { given_name: 'Fictional', family_name: 'Applicant', institutional_id: 'F-123' }, before: options.calendar ? { ...current, starts_on: '2026-08-14' } : current, after: reviewedTarget, review_token: 'fictional-private-intake' };
        } else if (path.endsWith('/entry/commit/')) { current = reviewedTarget; result = { status: options.accepted ? 'accepted' : 'submitted' }; }
        else if (path.endsWith('/entry/')) result = {
            person: { given_name: 'Fictional', family_name: 'Applicant', institutional_id: 'F-123' }, current,
            approved: options.calendar ? { ...original, starts_on: '2026-08-14' } : null,
            application_status: options.accepted ? 'accepted' : 'submitted', needs_review: !!options.calendar,
            answers_pending: !!options.pending, withdrawal_pending: false,
        };
        else if (path.endsWith('/decision/history/')) result = {
            history_recorded: true, earlier_history_unknown: false, current_status: 'submitted', source: null, submission: null,
            items: [{ kind: 'entry_correct', status: 'submitted', entry: { before: original, after: target }, effective_at: '2026-11-23T10:00:00Z', recorded_at: '2026-11-23T10:00:00Z', source: null }], next_cursor: null,
        };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(options.ordinary ? '/admin/admissions-only-fixture/' : '/admin/');
    await page.locator('.workspace-nav-link[data-workspace="applications"]').click();
    await page.getByRole('button', { name: 'Fictional Applicant', exact: true }).click();
    if (!options.ordinary) {
        await recordAction(page, 'Entry term');
        await expect(page.locator('#drawer-body')).toContainText(options.calendar ? 'Previously approved intake' : 'Current entry term');
    }
    return { posted, errors };
}
async function choose(page, accepted = false) {
    await page.getByRole('button', { name: accepted ? 'Choose later intake' : 'Correct entry term', exact: true }).press('Enter');
    await page.getByRole('radio').first().check();
    await page.getByLabel(accepted ? 'Reason for approval' : 'Reason for correction', { exact: true }).fill('Fictional private reason.');
    await page.getByRole('button', { name: 'Review intake', exact: true }).click();
}

test('submitted correction reviews, revises, confirms and returns to the same application and history', async ({ page }) => {
    const f = await fixture(page);
    await choose(page);
    await expect(page.getByRole('button', { name: 'Confirm entry-term correction', exact: true })).toBeFocused();
    expect(f.posted.some(item => item.path.endsWith('/commit/'))).toBe(false);
    await page.getByRole('button', { name: 'Revise', exact: true }).click();
    await expect(page.getByLabel('Reason for correction', { exact: true })).toHaveValue('Fictional private reason.');
    await expect(page.getByRole('radio').first()).toBeChecked();
    await page.getByRole('button', { name: 'Review intake', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('application stays awaiting a decision');
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const scan = await new AxeBuilder({ page }).include('#record-drawer').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    await page.locator('#record-drawer').screenshot({ path: '/tmp/etsis-intake-correction-phone.png' });
    await page.getByRole('button', { name: 'Confirm entry-term correction', exact: true }).press('Enter');
    await expect(page.locator('#drawer-body')).toContainText('2027/FA');
    expect(f.posted.find(item => item.path.endsWith('/entry/review/')).data.kind).toBe('correct');
    expect(f.posted.find(item => item.path.endsWith('/entry/commit/')).data).toEqual({ review_token: 'fictional-private-intake' });
    await recordAction(page, 'Application history');
    await expect(page.locator('#drawer-body')).toContainText('Entry term corrected');
    expect(f.errors).toEqual([]);
});

test('accepted intake keeps the separate deferment and fresh-offer guidance', async ({ page }) => {
    const f = await fixture(page, { accepted: true });
    await expect(page.getByRole('button', { name: 'Correct entry term', exact: true })).toHaveCount(0);
    await choose(page, true);
    await expect(page.locator('#drawer-body')).toContainText('Issue a fresh offer');
    await page.getByRole('button', { name: 'Approve intake', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('2027/FA');
    expect(f.posted.find(item => item.path.endsWith('/entry/review/')).data.kind).toBe('defer');
    expect(f.errors).toEqual([]);
});

test('pending answer corrections explain the pause and retain a direct return to application', async ({ page }) => {
    const f = await fixture(page, { pending: true });
    await expect(page.locator('#drawer-body')).toContainText('Resolve the answer correction request');
    await expect(page.getByRole('button', { name: 'Correct entry term', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Back to application', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Accept application', exact: true })).toBeVisible();
    expect(f.posted.filter(item => item.path.includes('/entry/'))).toEqual([]); expect(f.errors).toEqual([]);
});

test('denial clears the private correction and leaves Back to application usable', async ({ page }) => {
    const f = await fixture(page, { denied: true });
    await choose(page);
    await expect(page.locator('#drawer-body')).toContainText('Access changed');
    await expect(page.getByRole('textbox', { name: 'Reason for correction', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Confirm entry-term correction', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Back to application', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Accept application', exact: true })).toBeVisible();
    expect(f.posted.some(item => item.path.endsWith('/commit/'))).toBe(false); expect(f.errors).toEqual([]);
});

test('submitted calendar recheck reviews retained and current dates without a term search', async ({ page }) => {
    const f = await fixture(page, { calendar: true });
    await page.getByRole('button', { name: 'Review current intake', exact: true }).click();
    await expect(page.locator('#drawer-body').getByRole('searchbox')).toHaveCount(0);
    await page.getByLabel('Reason for correction', { exact: true }).fill('Fictional calendar recheck.');
    await page.getByRole('button', { name: 'Review intake', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('2026-08-14');
    await expect(page.locator('#drawer-body')).toContainText('2026-08-15');
    await page.getByRole('button', { name: 'Confirm entry-term correction', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Accept application', exact: true })).toBeVisible();
    expect(f.posted.find(item => item.path.endsWith('/entry/review/')).data.kind).toBe('recheck');
    expect(f.errors).toEqual([]);
});

test('ordinary admissions management offers no intake-correction control', async ({ page }) => {
    const f = await fixture(page, { ordinary: true });
    await expect(page.getByRole('button', { name: 'Accept application', exact: true })).toBeVisible();
    await expect(page.locator('#drawer-body').getByRole('button', { name: 'Entry term', exact: true, includeHidden: true })).toHaveCount(0);
    expect(f.posted.filter(item => item.path.includes('/entry/'))).toEqual([]);
    expect(f.errors).toEqual([]);
});
