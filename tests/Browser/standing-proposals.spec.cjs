const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');

async function fixture(page) {
    const definitions = [{ id: id(11), code: 'REVIEW', title: 'Fictional academic review', active: true },
        { id: id(12), code: 'GOOD', title: 'Fictional <Good>', active: true }];
    const state = { rows: [], requests: [], errors: [], failed: false, denied: false,
        rules: { enabled: false, minimum_gpa_credits_milli: 0, bands: [] }, version: 0, sequence: 30 };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : {};
        state.requests.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/students/') result = { items: [{ id: id(1), person_id: id(2), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true }], next_after: null };
        else if (path === `/sis/students/${id(1)}/`) result = { id: id(1), person_id: id(2), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true,
            primary_program: { id: id(4), code: 'ARTS.UG', title: 'Fictional Arts' }, entry_term: { id: id(5), code: '26/FA' }, revision: 'a'.repeat(64) };
        else if (path.endsWith('/programs/')) result = { items: [{ id: id(3), program_id: id(4), program_code: 'ARTS.UG', program_title: 'Fictional Arts', term_code: '26/FA', status: 'active', is_primary: true }], next_after: null };
        else if (path === '/sis/academic-standing/definitions/') result = { items: definitions, next_after: null };
        else if (path.startsWith('/sis/academic-standing/proposal-policy/') && state.denied) return route.fulfill({ status: 403, json: { error: 'Fictional access denied.' } });
        else if (path.endsWith('/proposal-policy/')) result = { rules: state.rules, version: state.version, publication_id: state.version ? id(20) : null };
        else if (path.endsWith('/proposal-policy/review/')) {
            if (state.failed) return route.fulfill({ status: 409, json: { error: 'Fictional policy changed; review again.' } });
            result = { ...data, publication_id: id(20), review_token: 'fictional-policy-proof' };
        } else if (path.endsWith('/proposal-policy/publish/')) {
            state.rules = data.rules; state.version++; result = { publication_id: id(20), version: state.version, status: 'published' };
        } else if (path.endsWith('/standing/')) result = { rows: state.rows, revision: 'a'.repeat(64), editable: true, historical_correction: false,
            program: { code: 'ARTS.UG', title: 'Fictional Arts' }, status: 'active' };
        else if (path.endsWith('/standing/propose/')) result = { standing: { ...definitions[1], definition_id: definitions[1].id },
            gpa_milli: 2000, gpa_credits_milli: 3000, as_of: '2026-11-25', policy_version: 1, policy_publication_id: id(20),
            proposal: { period_id: id(state.sequence++), revision: 'b'.repeat(64), token: 'fictional-proposal-proof' } };
        else if (path.endsWith('/standing/review/')) {
            if (state.failed) return route.fulfill({ status: 409, json: { error: 'Fictional academic work changed.' } });
            result = { ...data, before: state.rows, after: data.rows, operation_id: id(40), review_token: 'fictional-standing-proof' };
        } else if (path.endsWith('/standing/commit/')) { state.rows = data.rows; result = { status: 'updated' }; }
        else if (path.endsWith('/standing/history/')) result = { items: [{ version: 1, rows: state.rows, reason: 'Fictional accepted decision.', recorded_at: '2026-11-25T10:00:00Z',
            proposal: { gpa_milli: 2000, gpa_credits_milli: 3000, as_of: '2026-11-25', policy_version: 1 } }], next_cursor: null };
        else if (path.endsWith('/degree-requirements/progress/')) result = { status: 'needs_selection' };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    return state;
}

async function policy(page) {
    await page.goto('/admin/workspace/students/');
    await page.getByRole('button', { name: 'Configure academic standing definitions', exact: true }).click();
    await page.getByRole('button', { name: 'Standing proposal policy', exact: true }).click();
}
async function standing(page) {
    await page.goto('/admin/workspace/students/');
    await page.locator('#directory-search').fill('Alex');
    await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await recordAction(page, 'Academic standing');
}
async function band(page, index) {
    await page.getByRole('button', { name: 'Add GPA band', exact: true }).click();
    await page.getByRole('button', { name: 'Choose standing definition', exact: true }).nth(index).click();
    await page.getByRole('button', { name: 'Choose standing definition', exact: true }).nth(index).click();
}
async function reflow(page) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    expect(await page.locator('#drawer-body').evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    const scan = await new AxeBuilder({ page }).include('#record-drawer').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
}

test('institutional policy keeps its draft while choosing definitions, then reviews and publishes exact GPA floors', async ({ page }) => {
    const state = await fixture(page);
    await policy(page);
    await expect(page.getByLabel('Enable institutional GPA standing proposals', { exact: true })).not.toBeChecked();
    await page.getByLabel('Minimum institutional GPA credits', { exact: true }).fill('6.000');
    await band(page, 0);
    await page.getByLabel('Minimum institutional GPA credits', { exact: true }).fill('');
    await band(page, 1);
    await expect(page.getByLabel('Minimum institutional GPA credits', { exact: true })).toHaveValue('');
    await page.getByLabel('Minimum institutional GPA credits', { exact: true }).fill('6.000');
    await page.getByLabel('GPA floor', { exact: true }).nth(1).fill('2.000');
    await page.getByLabel('Enable institutional GPA standing proposals', { exact: true }).check();
    await page.getByLabel('Reason for policy publication', { exact: true }).fill('Fictional institutional approval.');
    await expect(page.getByLabel('Minimum institutional GPA credits', { exact: true })).toHaveValue('6.000');
    await page.getByRole('button', { name: 'Review proposal policy', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Review standing proposal policy', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Keep editing', exact: true }).click();
    await expect(page.getByLabel('GPA floor', { exact: true }).nth(1)).toHaveValue('2.000');
    await page.getByRole('button', { name: 'Review proposal policy', exact: true }).click();
    const publish = page.getByRole('button', { name: 'Publish proposal policy', exact: true });
    await expect(publish.locator('.bi')).toHaveCount(1);
    await publish.click();
    await expect(page.getByText('Standing proposal policy published.', { exact: true })).toBeVisible();
    expect(state.rules.bands.map(b => b.minimum_gpa_milli)).toEqual([0, 2000]);
    expect(state.rules.minimum_gpa_credits_milli).toBe(6000);
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Add definition', exact: true })).toBeVisible();
    expect(state.requests.filter(r => r.path.includes('/proposal-policy/')).every(r => r.csrf === 'fictional-test-token')).toBe(true);
    expect(state.errors).toEqual([]);
});

test('policy conflicts preserve the draft and denied reviews clear private controls', async ({ page }) => {
    const state = await fixture(page);
    await policy(page);
    await page.getByLabel('Reason for policy publication', { exact: true }).fill('Fictional unsaved reason.');
    state.failed = true;
    await page.getByRole('button', { name: 'Review proposal policy', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Fictional policy changed');
    await expect(page.getByLabel('Reason for policy publication', { exact: true })).toHaveValue('Fictional unsaved reason.');
    await page.getByLabel('Minimum institutional GPA credits', { exact: true }).fill('3.500');
    state.failed = false; state.version = 2;
    await page.getByRole('button', { name: 'Refresh policy version', exact: true }).click();
    await expect(page.getByLabel('Minimum institutional GPA credits', { exact: true })).toHaveValue('3.500');
    await expect(page.getByLabel('Reason for policy publication', { exact: true })).toHaveValue('Fictional unsaved reason.');
    state.denied = true;
    await page.getByRole('button', { name: 'Review proposal policy', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Standing policy access is no longer available');
    await expect(page.getByLabel('Reason for policy publication', { exact: true })).toHaveCount(0);
    expect(state.errors).toEqual([]);
});

test('GPA proposal survives degree navigation, confirms a new period and exposes only curated history', async ({ page }) => {
    const state = await fixture(page);
    await standing(page);
    await page.getByRole('button', { name: 'Propose from current GPA', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Current institutional GPA proposal', exact: true })).toBeVisible();
    await page.getByLabel('Reason for change', { exact: true }).fill('Fictional reviewed GPA standing.');
    await page.getByRole('button', { name: 'Degree progress', exact: true }).click();
    await page.getByRole('button', { name: 'Back to academic standing', exact: true }).click();
    await expect(page.getByLabel('Reason for change', { exact: true })).toHaveValue('Fictional reviewed GPA standing.');
    await expect(page.getByLabel('Effective from', { exact: true })).toHaveValue('2026-11-25');
    await page.getByRole('button', { name: 'Review changes', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm changes', exact: true }).click();
    expect(state.requests.find(r => r.path.endsWith('/standing/commit/')).data.proposal.token).toBe('fictional-proposal-proof');
    await page.getByRole('button', { name: 'Standing history', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Accepted GPA proposal evidence', exact: true })).toBeVisible();
    await expect(page.locator('#drawer-body Good')).toHaveCount(0);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(state.errors).toEqual([]);
});

test('a rejected standing review retains its proposal and can explicitly switch to manual review', async ({ page }) => {
    const state = await fixture(page);
    await standing(page);
    await page.getByRole('button', { name: 'Propose from current GPA', exact: true }).click();
    await page.getByLabel('Reason for change', { exact: true }).fill('Fictional source review.');
    state.failed = true;
    await page.getByRole('button', { name: 'Review changes', exact: true }).click();
    await expect(page.getByLabel('Reason for change', { exact: true })).toHaveValue('Fictional source review.');
    await expect(page.getByRole('heading', { name: 'Current institutional GPA proposal', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Use manual review instead', exact: true }).click();
    await expect(page.locator('#drawer-body').getByRole('status')).toContainText('manual standing review');
    state.failed = false;
    await page.getByRole('button', { name: 'Review changes', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm changes', exact: true }).click();
    expect(state.requests.find(r => r.path.endsWith('/standing/commit/')).data.proposal).toBeNull();
    expect(state.errors).toEqual([]);
});

test('policy and proposal controls reflow with keyboard access at 320px and 200% text size', async ({ page }) => {
    const state = await fixture(page);
    await page.setViewportSize({ width: 320, height: 900 });
    await policy(page);
    const enabled = page.getByLabel('Enable institutional GPA standing proposals', { exact: true });
    await enabled.focus(); await page.keyboard.press('Space');
    await expect(enabled).toBeChecked();
    await reflow(page);
    await page.screenshot({ path: '/tmp/etsis-standing-policy-phone.png' });
    await standing(page);
    await page.getByRole('button', { name: 'Propose from current GPA', exact: true }).click();
    await reflow(page);
    await page.screenshot({ path: '/tmp/etsis-standing-proposal-phone.png' });
    await page.setViewportSize({ width: 640, height: 900 });
    await page.evaluate(() => { document.body.style.zoom = '2'; });
    await reflow(page);
    expect(state.errors).toEqual([]);
});
