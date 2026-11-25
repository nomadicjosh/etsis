const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');

async function fixture(page) {
    const definitions = [{ id: id(11), code: 'REVIEW', title: 'Fictional academic review', active: true },
        { id: id(12), code: 'GOOD', title: 'Fictional <Good>', active: true }];
    const targets = { program: [{ id: id(4), code: 'ARTS.UG', title: 'Fictional <Arts>', active: true }],
        academic_level: [{ id: id(70), code: 'UG', title: 'Fictional undergraduate', active: true }] };
    const terms = Array.from({ length: 23 }, (_, n) => ({ id: id(200 + n), code: `TERM.${n}`,
        starts_on: '2026-01-01', ends_on: '2026-05-31' }));
    const state = { targets, terms, rows: [], requests: [], errors: [], failed: false, denied: false,
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
        else if (path.endsWith('/proposal-policy/targets/')) {
            const items = (state.targets[data.kind] || []).filter(item => `${item.code} ${item.title}`.toLowerCase().includes((data.search || '').toLowerCase()) && (!data.after || item.id > data.after));
            result = { items: items.slice(0, 20), next_after: items.length > 20 ? items[19].id : null };
        } else if (path.endsWith('/proposal-policy/')) result = { rules: state.rules, version: state.version, publication_id: state.version ? id(20) : null };
        else if (path.endsWith('/proposal-policy/review/')) {
            if (state.failed) return route.fulfill({ status: 409, json: { error: 'Fictional policy changed; review again.' } });
            result = { ...data, publication_id: id(20), review_token: 'fictional-policy-proof' };
        } else if (path.endsWith('/proposal-policy/publish/')) {
            state.rules = data.rules; state.version++; result = { publication_id: id(20), version: state.version, status: 'published' };
        } else if (path.endsWith('/standing/')) result = { rows: state.rows, revision: 'a'.repeat(64), editable: true, historical_correction: false,
            program: { code: 'ARTS.UG', title: 'Fictional Arts' }, status: 'active' };
        else if (path.endsWith('/standing/proposal-terms/')) {
            if (state.termsDenied) return route.fulfill({ status: 403, json: { error: 'Fictional standing access lost.' } });
            const items = state.terms.filter(t => t.code.toLowerCase().includes((data.search || '').toLowerCase()) && (!data.after || t.id > data.after));
            result = { items: items.slice(0, 20), next_after: items.length > 20 ? items[19].id : null };
        } else if (path.endsWith('/standing/propose/')) {
            if (state.termRequired && !data.term_id) return route.fulfill({ status: 422, json: { error: 'Select a posting term for this term-GPA policy.' } });
            state.lastAssessment = data.term_id ? { basis: 'term', term_code: state.terms.find(t => t.id === data.term_id).code } : { basis: 'cumulative' };
            result = { standing: { ...definitions[1], definition_id: definitions[1].id },
            scope: state.proposalScope || { kind: 'institution' }, assessment: state.lastAssessment, gpa_milli: 2000, gpa_credits_milli: 3000, as_of: '2026-11-25', policy_version: 1, policy_publication_id: id(20),
            proposal: { ...(data.term_id ? { term_id: data.term_id } : {}), period_id: id(state.sequence++), revision: 'b'.repeat(64), token: 'fictional-proposal-proof' } };
        }
        else if (path.endsWith('/standing/review/')) {
            if (state.failed) return route.fulfill({ status: 409, json: { error: 'Fictional academic work changed.' } });
            result = { ...data, before: state.rows, after: data.rows, operation_id: id(40), review_token: 'fictional-standing-proof' };
        } else if (path.endsWith('/standing/commit/')) { state.rows = data.rows; state.historyAssessment = state.lastAssessment; result = { status: 'updated' }; }
        else if (path.endsWith('/standing/history/')) result = { items: [{ version: 1, rows: state.rows, reason: 'Fictional accepted decision.', recorded_at: '2026-11-25T10:00:00Z',
            proposal: { scope: state.historyScope, assessment: state.historyAssessment, gpa_milli: 2000, gpa_credits_milli: 3000, as_of: '2026-11-25', policy_version: 1 } }], next_cursor: null };
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
    await expect(page.getByLabel('Enable default institutional GPA proposals', { exact: true })).not.toBeChecked();
    await page.getByLabel('Minimum institutional GPA credits', { exact: true }).fill('6.000');
    await band(page, 0);
    await page.getByLabel('Minimum institutional GPA credits', { exact: true }).fill('');
    await band(page, 1);
    await expect(page.getByLabel('Minimum institutional GPA credits', { exact: true })).toHaveValue('');
    await page.getByLabel('Minimum institutional GPA credits', { exact: true }).fill('6.000');
    await page.getByLabel('GPA floor', { exact: true }).nth(1).fill('2.000');
    await page.getByLabel('Enable default institutional GPA proposals', { exact: true }).check();
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
    await expect(page.locator('#drawer-body')).toContainText('Policy scope was not recorded in this earlier decision.');
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
    const enabled = page.getByLabel('Enable default institutional GPA proposals', { exact: true });
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


test('program and level editors preserve drafts and publish independent bands with the default disabled', async ({ page }) => {
    const state = await fixture(page);
    await policy(page);
    await page.getByLabel('Reason for policy publication', { exact: true }).fill('Fictional scoped publication.');
    await page.getByRole('button', { name: 'Add program policy', exact: true }).click();
    await page.getByRole('button', { name: 'Use this policy target', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Program policy · ARTS.UG · Fictional <Arts>', exact: true })).toBeVisible();
    await page.getByLabel('GPA basis', { exact: true }).selectOption('term');
    await page.getByLabel('Minimum institutional GPA credits', { exact: true }).fill('6.500');
    await band(page, 0);
    await expect(page.getByLabel('Minimum institutional GPA credits', { exact: true })).toHaveValue('6.500');
    state.targets.program[0].title = 'Fictional renamed program';
    await page.getByRole('button', { name: 'Re-select policy target', exact: true }).click();
    await page.getByRole('button', { name: 'Use this policy target', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Program policy · ARTS.UG · Fictional renamed program', exact: true })).toBeVisible();
    await expect(page.getByLabel('Minimum institutional GPA credits', { exact: true })).toHaveValue('6.500');
    await page.getByRole('button', { name: 'Back to proposal policy', exact: true }).first().click();
    await expect(page.getByLabel('Reason for policy publication', { exact: true })).toHaveValue('Fictional scoped publication.');
    // Choosing the same identity opens its existing draft instead of duplicating an override.
    await page.getByRole('button', { name: 'Add program policy', exact: true }).click();
    await page.getByRole('button', { name: 'Use this policy target', exact: true }).click();
    await expect(page.getByLabel('Minimum institutional GPA credits', { exact: true })).toHaveValue('6.500');
    await page.getByRole('button', { name: 'Back to proposal policy', exact: true }).first().click();
    await expect(page.getByRole('button', { name: 'Edit standing policy override', exact: true })).toHaveCount(1);
    await page.getByRole('button', { name: 'Add academic-level policy', exact: true }).click();
    await page.getByRole('button', { name: 'Use this policy target', exact: true }).click();
    await page.getByLabel('Enable GPA proposals for this override', { exact: true }).uncheck();
    await page.getByRole('button', { name: 'Back to proposal policy', exact: true }).first().click();
    await page.getByRole('button', { name: 'Review proposal policy', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Review standing proposal policy', exact: true })).toBeVisible();
    await expect(page.locator('#drawer-body summary')).toHaveCount(2);
    await page.locator('#drawer-body summary').first().click();
    await expect(page.locator('#drawer-body')).toContainText('6.500');
    await page.getByRole('button', { name: 'Publish proposal policy', exact: true }).click();
    expect(state.rules.enabled).toBe(false);
    expect(state.rules.overrides.map(o => o.kind)).toEqual(['program', 'academic_level']);
    expect(state.rules.overrides[0].target.title).toBe('Fictional renamed program');
    expect(state.rules.overrides[0].rules.minimum_gpa_credits_milli).toBe(6500);
    expect(state.rules.overrides[0].rules.gpa_basis).toBe('term');
    expect(state.rules.gpa_basis).toBe('cumulative');
    expect(state.rules.overrides[0].rules.bands[0].minimum_gpa_milli).toBe(0);
    expect(state.rules.overrides[1].rules.enabled).toBe(false);
    await expect(page.locator('#drawer-body Arts')).toHaveCount(0);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(state.errors).toEqual([]);
});

test('target search and paging retain unfinished defaults and reason and denial clears nested controls', async ({ page }) => {
    const state = await fixture(page);
    state.targets.program = Array.from({ length: 23 }, (_, n) => ({ id: id(100 + n), code: `P.${n}`, title: `Fictional program ${n}`, active: true }));
    await policy(page);
    await page.getByLabel('Minimum institutional GPA credits', { exact: true }).fill('');
    await page.getByLabel('Reason for policy publication', { exact: true }).fill('Fictional private draft.');
    await page.getByRole('button', { name: 'Add program policy', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Use this policy target', exact: true })).toHaveCount(20);
    await page.getByRole('button', { name: 'More policy targets', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Use this policy target', exact: true })).toHaveCount(3);
    await page.getByRole('button', { name: 'Previous policy targets', exact: true }).click();
    await page.getByLabel('Find a program or academic level', { exact: true }).fill('P.22');
    await page.getByRole('button', { name: 'Search policy targets', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Use this policy target', exact: true })).toHaveCount(1);
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByLabel('Minimum institutional GPA credits', { exact: true })).toHaveValue('');
    await expect(page.getByLabel('Reason for policy publication', { exact: true })).toHaveValue('Fictional private draft.');
    state.denied = true;
    await page.getByRole('button', { name: 'Add academic-level policy', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Standing policy access is no longer available');
    await expect(page.locator('#drawer-body input,#drawer-body textarea')).toHaveCount(0);
    expect(state.errors).toEqual([]);
});

test('nested override controls and review reflow at 320px and 200% with keyboard access', async ({ page }) => {
    const state = await fixture(page);
    await page.setViewportSize({ width: 320, height: 900 });
    await policy(page);
    await page.getByRole('button', { name: 'Add academic-level policy', exact: true }).click();
    await reflow(page);
    await page.getByRole('button', { name: 'Use this policy target', exact: true }).click();
    const enabled = page.getByLabel('Enable GPA proposals for this override', { exact: true });
    await enabled.focus(); await page.keyboard.press('Space');
    await expect(enabled).not.toBeChecked();
    await reflow(page);
    await page.screenshot({ path: '/tmp/etsis-standing-override-phone.png' });
    await page.getByRole('button', { name: 'Back to proposal policy', exact: true }).first().click();
    await page.getByLabel('Reason for policy publication', { exact: true }).fill('Fictional accessible scope.');
    await page.getByRole('button', { name: 'Review proposal policy', exact: true }).click();
    await page.locator('#drawer-body summary').focus(); await page.keyboard.press('Enter');
    await expect(page.locator('#drawer-body details')).toHaveAttribute('open', '');
    await reflow(page);
    await page.setViewportSize({ width: 640, height: 900 });
    await page.evaluate(() => { document.body.style.zoom = '2'; });
    await reflow(page);
    expect(state.errors).toEqual([]);
});


test('proposal and history show the accepted level labels without substituting a newer policy', async ({ page }) => {
    const state = await fixture(page);
    const scope = { kind: 'academic_level', code: 'UG', title: 'Fictional undergraduate' };
    state.proposalScope = scope;
    await standing(page);
    await page.getByRole('button', { name: 'Propose from current GPA', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Academic-level policy · UG · Fictional undergraduate');
    await page.getByLabel('Reason for change', { exact: true }).fill('Fictional retained level.');
    await page.getByRole('button', { name: 'Review changes', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm changes', exact: true }).click();
    state.historyScope = scope;
    state.proposalScope = { kind: 'academic_level', code: 'GR', title: 'Fictional graduate' };
    state.version = 2;
    await page.getByRole('button', { name: 'Standing history', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Academic-level policy · UG · Fictional undergraduate');
    await expect(page.locator('#drawer-body')).not.toContainText('Fictional graduate');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(state.errors).toEqual([]);
});


test('term selection pages and searches without losing the draft and retains term evidence on confirmation', async ({ page }) => {
    const state = await fixture(page); state.termRequired = true;
    await standing(page);
    await page.getByLabel('Reason for change', { exact: true }).fill('Fictional selected-term assessment.');
    await page.getByRole('button', { name: 'Propose from current GPA', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Select a posting term');
    await page.getByRole('button', { name: 'Choose proposal term', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Use this posting term', exact: true })).toHaveCount(20);
    await page.getByRole('button', { name: 'More posting terms', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Use this posting term', exact: true })).toHaveCount(3);
    await page.getByRole('button', { name: 'Previous posting terms', exact: true }).click();
    await page.getByLabel('Find a posting term', { exact: true }).fill('TERM.22');
    await page.getByRole('button', { name: 'Search posting terms', exact: true }).click();
    await page.getByRole('button', { name: 'Use this posting term', exact: true }).click();
    await expect(page.getByLabel('Reason for change', { exact: true })).toHaveValue('Fictional selected-term assessment.');
    await page.getByRole('button', { name: 'Propose from current GPA', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Current institutional GPA in a selected posting term');
    await expect(page.locator('#drawer-body')).toContainText('TERM.22');
    await page.getByRole('button', { name: 'Review changes', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm changes', exact: true }).click();
    expect(state.requests.find(r => r.path.endsWith('/standing/commit/')).data.proposal.term_id).toBe(id(222));
    await page.getByRole('button', { name: 'Standing history', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('TERM.22');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(state.errors).toEqual([]);
});

test('changing a proposal term replaces the pending proposed row while keeping dates and reason', async ({ page }) => {
    const state = await fixture(page); state.termRequired = true;
    await standing(page);
    await page.getByRole('button', { name: 'Choose proposal term', exact: true }).click();
    await page.getByRole('button', { name: 'Use this posting term', exact: true }).first().click();
    await page.getByRole('button', { name: 'Propose from current GPA', exact: true }).click();
    await page.getByLabel('Effective from', { exact: true }).fill('2026-11-26');
    await page.getByLabel('Policy reference (optional)', { exact: true }).fill('Fictional retained dates.');
    await page.getByLabel('Reason for change', { exact: true }).fill('Fictional retained reason.');
    await page.getByRole('button', { name: 'Choose proposal term', exact: true }).click();
    await page.getByRole('button', { name: 'Use this posting term', exact: true }).nth(1).click();
    await expect(page.getByRole('heading', { name: 'Current institutional GPA proposal', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Propose from current GPA', exact: true }).click();
    await expect(page.getByLabel('Effective from', { exact: true })).toHaveCount(1);
    await expect(page.getByLabel('Effective from', { exact: true })).toHaveValue('2026-11-26');
    await expect(page.getByLabel('Policy reference (optional)', { exact: true })).toHaveValue('Fictional retained dates.');
    await expect(page.getByLabel('Reason for change', { exact: true })).toHaveValue('Fictional retained reason.');
    await page.getByRole('button', { name: 'Clear proposal term', exact: true }).click();
    await expect(page.getByLabel('Effective from', { exact: true })).toHaveCount(0);
    state.termsDenied = true;
    await page.getByRole('button', { name: 'Choose proposal term', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional standing access lost.');
    await expect(page.locator('#drawer-body input,#drawer-body textarea')).toHaveCount(0);
    expect(state.errors).toEqual([]);
});

test('GPA basis and posting-term controls support keyboard input and narrow enlarged screens', async ({ page }) => {
    const state = await fixture(page);
    await page.setViewportSize({ width: 320, height: 900 });
    await policy(page);
    await page.getByLabel('GPA basis', { exact: true }).selectOption('term');
    await reflow(page);
    await page.screenshot({ path: '/tmp/etsis-standing-term-policy-phone.png' });
    await standing(page);
    await page.getByRole('button', { name: 'Choose proposal term', exact: true }).click();
    await reflow(page);
    const use = page.getByRole('button', { name: 'Use this posting term', exact: true }).first();
    await use.focus(); await use.press('Enter');
    await page.getByRole('button', { name: 'Propose from current GPA', exact: true }).click();
    await page.setViewportSize({ width: 640, height: 900 });
    await page.evaluate(() => { document.body.style.zoom = '2'; });
    await reflow(page);
    await page.screenshot({ path: '/tmp/etsis-standing-term-proposal-phone.png' });
    expect(state.errors).toEqual([]);
});
