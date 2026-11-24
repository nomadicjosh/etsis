const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
const program = (n, code) => ({ id: id(n), code, title: `Fictional ${code}` });
async function fixture(page, options = {}) {
    const original = program(3, 'SCI-UG'), target = program(4, 'ARTS-UG');
    let current = original, commits = 0;
    const posted = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) posted.push({ path, data });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = { items: path.includes('/applications/')
            ? [{ id: id(1), given_name: 'Fictional', family_name: 'Applicant', status: 'submitted' }] : [], next_after: null };
        else if (path === `/sis/applications/${id(1)}/review/`) result = {
            application: { id: { value: id(1) }, status: 'submitted' },
            person: { id: { value: id(2) }, givenName: 'Fictional', familyName: 'Applicant', institutionalId: { value: 'F-123' } },
            labels: { program_code: current.code, program_title: current.title, term_code: '2027/FA' },
        };
        else if (path.endsWith('/program/choices/')) result = { items: [target], next: 'fictional-next-page' };
        else if (path.endsWith('/program/review/')) {
            if (options.denied) return route.fulfill({ status: 403, json: { message: 'Fictional access loss.' } });
            result = { person: { given_name: 'Fictional', family_name: '<Applicant>', institutional_id: 'F-123' },
                before: original, after: target, review_token: 'fictional-private-program' };
        } else if (path.endsWith('/program/commit/')) {
            if (options.retry && ++commits === 1) return route.fulfill({ status: 503, json: { message: 'Fictional outage.' } });
            current = target; result = { status: 'submitted' };
        } else if (path.endsWith('/program/')) result = {
            current, status: 'submitted', program_form: !!options.specific, answers_pending: false, withdrawal_pending: false,
        };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="applications"]').click();
    await page.getByRole('button', { name: 'Fictional Applicant', exact: true }).click();
    await recordAction(page, 'Correct application program');
    await expect(page.locator('#drawer-body')).toContainText('Current planned program');
    return { posted, errors };
}
async function choose(page) {
    await page.getByRole('button', { name: 'Find programs', exact: true }).click();
    await page.getByLabel('ARTS-UG · Fictional ARTS-UG', { exact: true }).check();
    await page.getByLabel('Reason for program correction', { exact: true }).fill('Fictional correction reason.');
}
test('program correction preserves revised drafts, returns to application and retries the original token', async ({ page }) => {
    const { posted, errors } = await fixture(page, { retry: true });
    await choose(page);
    await page.getByRole('button', { name: 'Review program correction', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm program correction', exact: true })).toBeFocused();
    await page.getByRole('button', { name: 'Revise', exact: true }).click();
    await expect(page.getByLabel('Reason for program correction', { exact: true })).toHaveValue('Fictional correction reason.');
    await expect(page.locator('#drawer-body')).toContainText('ARTS-UG · Fictional ARTS-UG');
    await page.getByRole('button', { name: 'Review program correction', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm program correction', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Retry this confirmation');
    await page.getByRole('button', { name: 'Confirm program correction', exact: true }).click();
    await expect(page.getByRole('heading', { name: /Fictional Applicant/ })).toBeVisible();
    const writes = posted.filter(v => v.path.endsWith('/program/commit/'));
    expect(writes).toHaveLength(2); expect(writes[0].data).toEqual(writes[1].data);
    expect(errors).toEqual([]);
});
test('narrow enlarged-text form and review have labels, keyboard access and no overflow', async ({ page }) => {
    await fixture(page);
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 32px; }' });
    await choose(page);
    await expect(page.getByRole('button', { name: 'More programs', exact: true })).toBeVisible();
    let violations = (await new AxeBuilder({ page }).include('#record-drawer').analyze()).violations;
    expect(violations).toEqual([]);
    await page.getByRole('button', { name: 'Review program correction', exact: true }).click();
    const confirm = page.getByRole('button', { name: 'Confirm program correction', exact: true });
    await expect(confirm).toBeFocused();
    expect(await page.locator('#drawer-body').evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    await page.locator('#record-drawer').screenshot({ path: '/tmp/etsis-program-correction-phone.png' });
    violations = (await new AxeBuilder({ page }).include('#record-drawer').analyze()).violations;
    expect(violations).toEqual([]);
    await page.getByRole('button', { name: 'Back to application', exact: true }).click();
    // A cancelled leave keeps the in-memory review visible.
    await expect(confirm).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(confirm).toBeVisible();
    await page.locator('#record-drawer .btn-close').click();
    await expect(confirm).toBeVisible();
    page.once('dialog', dialog => dialog.accept());
    await page.locator('#record-drawer .btn-close').click();
    await expect(page.locator('#record-drawer')).toBeHidden();
});
test('program-specific forms explain the boundary and access loss clears private drafts', async ({ page }) => {
    await fixture(page, { specific: true });
    await expect(page.locator('#drawer-body')).toContainText('program-specific form');
    await expect(page.getByRole('button', { name: 'Find programs', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Back to application', exact: true }).click();
    await expect(page.getByRole('heading', { name: /Fictional Applicant/ })).toBeVisible();
    await page.unroute('**/sis/**');
    await fixture(page, { denied: true });
    await choose(page);
    await page.getByRole('button', { name: 'Review program correction', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Access changed');
    await expect(page.getByLabel('Reason for program correction', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Back to application', exact: true })).toBeVisible();
});
