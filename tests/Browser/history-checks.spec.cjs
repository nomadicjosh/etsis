'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
const person = id(1), student = id(2), account = id(3), owner = id(4), program = id(5), term = id(6), application = id(7);
async function fixture(page, kind, { denied = false, delay = null, outcome = null } = {}) {
    const calls = [], errors = [], receipts = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = { items: path.includes('/people/')
            ? [{ id: person, owner_user_id: owner, given_name: 'Fictional', family_name: 'Person', institutional_id: 'S-FICTIONAL' }]
            : path.includes('/students/') ? [{ id: student, person_id: person, given_name: 'Fictional', family_name: 'Person', institutional_id: 'S-FICTIONAL', active: true }]
            : path.includes('/applications/') ? [{ id: application, given_name: 'Fictional', family_name: 'Person', status: 'submitted' }]
            : [{ id: account, name: 'Fictional account', currency: 'USD' }], next_after: null };
        else if (path === `/sis/ui/people/${person}/`) result = { person: { id: { value: person }, ownerUserId: { value: owner }, givenName: 'Fictional', familyName: 'Person', institutionalId: { value: 'S-FICTIONAL' } }, students: [], employees: [], accounts: [], applications: [] };
        else if (path === `/sis/students/${student}/`) result = { id: student, person_id: person, given_name: 'Fictional', family_name: 'Person', institutional_id: 'S-FICTIONAL', active: true, primary_program: { id: program, code: 'BS.CS', title: 'Computing' }, entry_term: { id: term, code: '26/FA' }, revision: 'student' };
        else if (path === `/sis/students/${student}/programs/`) result = { items: [{ id: id(8), program_code: 'ARTS-UG', program_title: 'Arts', term_code: '26/FA', status: 'active', is_primary: true }], next_after: null };
        else if (path.endsWith('/advisors/')) result = {rows: [], revision: 'a'.repeat(64), editable: true,
            known_history: false, program: {code: 'ARTS-UG', title: 'Arts'}, status: 'active'};
        else if (path.endsWith('/degree-requirements/')) result = {selection: null, cohort_changed: false,
            scope: {status: 'active'}, requirements: null};
        else if (path.endsWith('/load/terms/')) result = { items: [{ id: term, code: '26/FA', starts_on: '2026-08-01', ends_on: '2026-12-15' }], next_after: null };
        else if (path.endsWith('/load/determinations/')) result = { items: [{ id: data.after ? id(12) : id(11), as_of_date: '2026-08-10', credits_milli: 3000, enrollment_count: 1,
            classification: { label: 'Fictional full time' }, program: { code: 'ARTS-UG', title: 'Arts' }, recorded_at: '2026-08-10T17:00:00Z',
            policy: { source: 'global', definition: { bands: [{ label: 'Fictional full time', min_credits_milli: 3000 }] } } }], next_after: data.after ? null : id(90) };
        else if (path === `/sis/accounts/${account}/`) result = { id: account, institutional_id: 'S-FICTIONAL', balance_minor: 1000, currency: 'USD', position: 1, entries: [], active_charge_total_minor: 1000 };
        else if (path === `/sis/applications/${application}/review/`) result = {
            application: { id: { value: application }, status: 'submitted' },
            person: { id: { value: person }, givenName: 'Fictional', familyName: 'Person', institutionalId: { value: 'S-FICTIONAL' } },
            labels: { program_code: 'ARTS-UG', program_title: 'Arts', term_code: '26/FA' },
        };
        else if (path.includes('/history-checks/')) {
            if (denied) return route.fulfill({ status: 403, json: { error: 'Current support duty is required.' } });
            if (path.endsWith('/run/')) {
                if (delay) await delay();
                const status = outcome || (kind === 'person' ? 'history_gaps' : kind === 'student' ? 'observed_empty' : 'verified');
                const checked = kind === 'standing' && status === 'observed_empty' ? 0 : 2;
                const receipt = { reference: id(20 + receipts.length), status, events_checked: checked, checked_at: '2026-08-10T17:00:00Z' };
                receipts.unshift(receipt);
                result = { result: { status, events_checked: checked, records_checked: ['standing', 'load', 'advisors', 'requirements'].includes(kind) ? (status === 'observed_empty' ? 0 : 1) : null, position: kind === 'student' ? null : checked, current_records: kind === 'student' ? 0 : null, issues: [] }, receipt, event_limit: 200 };
            } else result = { items: data.after ? [{ reference: id(10), status: 'limited', events_checked: 200, checked_at: '2026-08-09T17:00:00Z' }] : receipts, next_after: receipts.length && !data.after ? receipts.at(-1).reference : null };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    const dataset = { person: 'people', student: 'students', account: 'accounts', application: 'applications', standing: 'students', load: 'students', advisors: 'students', requirements: 'students' }[kind];
    await page.locator(`.workspace-nav-link[data-workspace="${dataset}"]`).click();
    await page.getByLabel('Search this workspace').fill('Fictional');
    await page.getByLabel('Search this workspace').press('Enter');
    await page.getByRole('button', { name: kind === 'account' ? 'Fictional account' : 'Fictional Person', exact: true }).click();
    return { calls, errors };
}
for (const kind of ['person', 'student', 'account', 'application']) {
    test(`${kind} support check is explicit, bounded and separate from source facts`, async ({ page }) => {
        const { calls, errors } = await fixture(page, kind);
        const label = { person: 'Check profile history', student: 'Check academic history', account: 'Check account history', application: 'Check application history' }[kind];
        if (kind === 'account') await page.getByRole('button', { name: label, exact: true }).click();
        else await recordAction(page, label);
        await expect(page.getByRole('region', { name: 'Previous history checks', exact: true })).toContainText('No previous checks');
        expect(calls.some(c => c.path.endsWith('/run/'))).toBe(false);
        const run = page.getByRole('button', { name: 'Run history check', exact: true });
        if (kind === 'application') {
            await run.focus();
            await page.keyboard.press('Enter');
        } else await run.click();
        const result = page.getByRole('region', { name: 'Current check result', exact: true });
        await expect(result).toContainText('Support reference:');
        await expect(result).toContainText('maximum 200');
        await expect(result).not.toContainText('1000');
        await expect(result).not.toContainText('USD');
        await expect(result).not.toContainText('Fictional Person');
        const history = page.getByRole('region', { name: 'Previous history checks', exact: true });
        await expect(history).toContainText('2026-08-10T17:00:00Z');
        await page.getByRole('button', { name: 'Older checks', exact: true }).click();
        await expect(history).toContainText('Check limit reached');
        await page.getByRole('button', { name: 'Previous checks page', exact: true }).click();
        await expect(history).toContainText('2026-08-10T17:00:00Z');
        await page.setViewportSize({ width: 320, height: 812 });
        const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(axe.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
        expect(calls.filter(c => c.path.includes('/history-checks/')).every(c => c.csrf === 'fictional-test-token')).toBe(true);
        expect(errors).toEqual([]);
    });
}

test('leaving a pending check prevents its result from replacing the next record', async ({ page }) => {
    let requested, release;
    const pending = new Promise(resolve => { requested = resolve; });
    const hold = new Promise(resolve => { release = resolve; });
    const { errors } = await fixture(page, 'person', { delay: async () => { requested(); await hold; } });
    await recordAction(page, 'Check profile history');
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await pending;
    await expect(page.getByRole('button', { name: 'Run history check', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    const accepted = page.waitForResponse(response => response.url().endsWith('/run/'));
    release(); await accepted;
    await expect(page.locator('#drawer-title')).toContainText('Person workspace');
    await expect(page.getByRole('region', { name: 'Current check result', exact: true })).toHaveCount(0);
    expect(errors).toEqual([]);
});

test('lost support authority clears a displayed earlier check result', async ({ page }) => {
    const { errors } = await fixture(page, 'student');
    await recordAction(page, 'Check academic history');
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Current check result', exact: true })).toContainText('Support reference:');
    await page.route('**/history-checks/**', route => route.fulfill({ status: 403, json: { error: 'Current support duty is required.' } }));
    await page.getByRole('button', { name: 'Refresh previous checks', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Current check result', exact: true })).toBeEmpty();
    await expect(page.getByRole('region', { name: 'Previous history checks', exact: true })).toBeEmpty();
    expect(errors).toEqual([]);
});

test('application check returns to its record and discards a late result', async ({ page }) => {
    let requested, release;
    const pending = new Promise(resolve => { requested = resolve; });
    const held = new Promise(resolve => { release = resolve; });
    const { errors } = await fixture(page, 'application', { delay: async () => { requested(); await held; } });
    await recordAction(page, 'Check application history');
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await pending;
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    const finished = page.waitForResponse(response => response.url().endsWith('/run/'));
    release(); await finished;
    await expect(page.getByRole('group', { name: 'Application tasks', exact: true })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Current check result', exact: true })).toHaveCount(0);
    expect(errors).toEqual([]);
});

test('application check clears prior results after authority loss and preserves narrow enlarged layouts', async ({ page }) => {
    const { errors } = await fixture(page, 'application');
    await recordAction(page, 'Check application history');
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Current check result', exact: true })).toContainText('Support reference:');
    await page.screenshot({ path: '/tmp/etsis-application-check-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    await page.getByRole('button', { name: 'Run history check', exact: true }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: '/tmp/etsis-application-check-phone.png' });
    await page.route('**/history-checks/**', route => route.fulfill({ status: 403, json: { error: 'Current support duty is required.' } }));
    await page.getByRole('button', { name: 'Refresh previous checks', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Current check result', exact: true })).toBeEmpty();
    await expect(page.getByRole('region', { name: 'Previous history checks', exact: true })).toBeEmpty();
    expect(errors).toEqual([]);
});

async function openDecisionCheck(page, kind) {
    if (kind === 'standing') return recordAction(page, 'Check standing history');
    await recordAction(page, 'Enrollment load');
    await page.getByRole('button', { name: 'Recorded determinations', exact: true }).click();
    await page.getByRole('button', { name: '26/FA · 2026-08-01–2026-12-15', exact: true }).click();
    await page.getByRole('button', { name: 'Older determinations', exact: true }).click();
    await page.getByRole('button', { name: 'Check retained load history', exact: true }).click();
}

for (const kind of ['standing', 'load']) {
    test(`${kind} history checks use the selected retained context and preserve record returns`, async ({ page }) => {
        const { calls, errors } = await fixture(page, kind);
        await openDecisionCheck(page, kind);
        const run = page.getByRole('button', { name: 'Run history check', exact: true });
        await expect(run).toBeEnabled();
        await run.focus(); await page.keyboard.press('Enter');
        await expect(page.getByRole('region', { name: 'Current check result', exact: true })).toContainText('Verified within this check');
        await expect(page.getByRole('region', { name: 'Current check result', exact: true })).toContainText(kind === 'standing' ? 'Standing periods checked: 1' : 'Retained determinations checked: 1');
        const reference = kind === 'standing' ? id(8) : id(12);
        expect(calls.find(call => call.path === `/sis/history-checks/${kind}/${reference}/run/`)).toBeTruthy();
        await page.getByRole('button', { name: 'Back to record', exact: true }).click();
        if (kind === 'load') {
            await expect(page.getByRole('heading', { name: 'Recorded enrollment load · 26/FA', exact: true })).toBeVisible();
            const reads = calls.filter(call => call.path.endsWith('/load/determinations/'));
            expect(reads.at(-1).data).toEqual({ term_id: term, after: id(90) });
            await expect(page.getByRole('button', { name: 'Newer determinations', exact: true })).toBeVisible();
        } else await expect(page.getByRole('group', { name: 'Student tasks', exact: true })).toBeVisible();
        expect(errors).toEqual([]);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    });
}

test('empty standing observation keeps earlier facts unknown and stays accessible on a phone', async ({ page }) => {
    const { errors } = await fixture(page, 'standing', { outcome: 'observed_empty' });
    await openDecisionCheck(page, 'standing');
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Current check result', exact: true })).toContainText('Earlier standing remains unknown.');
    await expect(page.getByRole('region', { name: 'Previous history checks', exact: true })).toContainText('No recorded standing observed');
    await page.screenshot({ path: '/tmp/etsis-decision-check-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    await page.getByRole('button', { name: 'Run history check', exact: true }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: '/tmp/etsis-decision-check-phone.png' });
    expect(errors).toEqual([]);
});

test('load support clears earlier results when its independent authority is lost', async ({ page }) => {
    const { errors } = await fixture(page, 'load');
    await openDecisionCheck(page, 'load');
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Current check result', exact: true })).toContainText('Verified within this check');
    await page.route('**/history-checks/load/**', route => route.fulfill({ status: 403, json: { error: 'Current load support duty is required.' } }));
    await page.getByRole('button', { name: 'Refresh previous checks', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Current check result', exact: true })).toBeEmpty();
    await expect(page.getByRole('region', { name: 'Previous history checks', exact: true })).toBeEmpty();
    expect(errors).toEqual([]);
});

test('a closed standing support drawer ignores its late verification response', async ({ page }) => {
    let requested, release;
    const pending = new Promise(resolve => { requested = resolve; });
    const held = new Promise(resolve => { release = resolve; });
    const { errors } = await fixture(page, 'standing', { delay: async () => { requested(); await held; } });
    await openDecisionCheck(page, 'standing');
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await pending;
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    const finished = page.waitForResponse(response => response.url().endsWith('/run/'));
    release(); await finished;
    await expect(page.getByRole('group', { name: 'Student tasks', exact: true })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Current check result', exact: true })).toHaveCount(0);
    expect(errors).toEqual([]);
});

for (const kind of ['advisors', 'requirements']) {
    test(`${kind} curriculum checks preserve their record and drafts through the support drawer`, async ({page}) => {
        const {calls, errors} = await fixture(page, kind);
        if (kind === 'advisors') {
            await recordAction(page, 'Academic advisors');
            await page.getByLabel('Reason for change', {exact: true}).fill('Unsaved fictional advisor correction.');
            await page.getByRole('button', {name: 'Check advisor assignment history', exact: true}).click();
        } else {
            await recordAction(page, 'Degree requirements');
            await expect(page.locator('dialog.degree-audit-studio')).toBeVisible();
            await page.getByRole('button', {name: 'Check degree selection history', exact: true}).click();
            await expect(page.locator('dialog.degree-audit-studio')).toHaveCount(0);
        }
        const run = page.getByRole('button', {name: 'Run history check', exact: true});
        await expect(run).toBeEnabled();
        await run.focus(); await page.keyboard.press('Enter');
        const result = page.getByRole('region', {name: 'Current check result', exact: true});
        await expect(result).toContainText('Verified within this check');
        await expect(result).toContainText(kind === 'advisors' ? 'Retained advisor periods checked: 1' : 'Selected requirement baselines checked: 1');
        await page.setViewportSize({width: 320, height: 900});
        const axe = await new AxeBuilder({page}).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(axe.violations.map(v => ({id: v.id, targets: v.nodes.map(n => n.target)}))).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const originalFont = await result.evaluate(element => parseFloat(getComputedStyle(element).fontSize));
        const rootSize = await page.evaluate(() => parseFloat(getComputedStyle(document.documentElement).fontSize));
        // Keep CSP enforced: emulate enlarged text with an allowed same-origin stylesheet.
        await page.route('**/fixtures/enlarged-text.css', route => route.fulfill({
            contentType: 'text/css', body: `html { font-size: ${rootSize * 2}px !important; }`,
        }));
        await page.addStyleTag({url: '/fixtures/enlarged-text.css'});
        await expect.poll(() => result.evaluate(element => parseFloat(getComputedStyle(element).fontSize)),
            {timeout: 3000}).toBeGreaterThanOrEqual(originalFont * 2);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const skip = page.locator('.skip-link');
        expect(await skip.evaluate(element => element.getBoundingClientRect().bottom)).toBeLessThanOrEqual(0);
        await page.screenshot({path: `/tmp/etsis-${kind}-history-check.png`});
        await page.getByRole('button', {name: 'Back to record', exact: true}).click();
        if (kind === 'advisors') {
            await expect(page.getByLabel('Reason for change', {exact: true})).toHaveValue('Unsaved fictional advisor correction.');
            expect(calls.filter(c => c.path.endsWith('/advisors/'))).toHaveLength(1);
        } else {
            await expect(page.getByRole('heading', {name: 'Student degree requirements', exact: true})).toBeVisible();
        }
        // The global skip link is outside the modal focus scope; test it after leaving the record.
        if (kind === 'requirements')
            await page.getByRole('button', {name: 'Close builder', exact: true}).click();
        else await page.locator('.record-drawer .btn-close').click();
        await expect(page.locator('.record-drawer')).not.toBeVisible();
        await skip.focus();
        await expect(skip).toBeFocused();
        await expect.poll(() => skip.evaluate(element => {
            const rect = element.getBoundingClientRect();
            return rect.top >= 0 && rect.left >= 0 && rect.right <= innerWidth;
        }), {timeout: 3000}).toBe(true);
        expect(calls.filter(c => c.path.endsWith(`/history-checks/${kind}/${id(8)}/run/`))).toHaveLength(1);
        expect(calls.filter(c => c.path.includes('/history-checks/')).every(c => c.csrf === 'fictional-test-token')).toBe(true);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
        expect(errors).toEqual([]);
    });

    test(`${kind} empty history remains unknown and access denial clears the result`, async ({page}) => {
        const {errors} = await fixture(page, kind, {outcome: 'observed_empty'});
        await recordAction(page, kind === 'advisors' ? 'Academic advisors' : 'Degree requirements');
        await page.getByRole('button', {name: kind === 'advisors' ? 'Check advisor assignment history' : 'Check degree selection history', exact: true}).click();
        await page.getByRole('button', {name: 'Run history check', exact: true}).click();
        const result = page.getByRole('region', {name: 'Current check result', exact: true});
        await expect(result).toContainText(kind === 'advisors' ? 'Earlier advising remains unknown' : 'does not assign requirements');
        await page.route('**/sis/history-checks/**', route => route.fulfill({status: 403, json: {error: 'Current support duty required.'}}));
        await page.getByRole('button', {name: 'Refresh previous checks', exact: true}).click();
        await expect(result).toBeEmpty();
        await expect(page.getByRole('region', {name: 'Previous history checks', exact: true})).toBeEmpty();
        await expect(page.getByRole('button', {name: 'Back to record', exact: true})).toBeVisible();
        expect(errors).toEqual([]);
    });
}
