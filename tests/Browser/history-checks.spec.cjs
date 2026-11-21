'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
const person = id(1), student = id(2), account = id(3), owner = id(4), program = id(5), term = id(6);
async function fixture(page, kind, { denied = false, delay = null } = {}) {
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
            : [{ id: account, name: 'Fictional account', currency: 'USD' }], next_after: null };
        else if (path === `/sis/ui/people/${person}/`) result = { person: { id: { value: person }, ownerUserId: { value: owner }, givenName: 'Fictional', familyName: 'Person', institutionalId: { value: 'S-FICTIONAL' } }, students: [], employees: [], accounts: [], applications: [] };
        else if (path === `/sis/students/${student}/`) result = { id: student, person_id: person, given_name: 'Fictional', family_name: 'Person', institutional_id: 'S-FICTIONAL', active: true, primary_program: { id: program, code: 'BS.CS', title: 'Computing' }, entry_term: { id: term, code: '26/FA' }, revision: 'student' };
        else if (path === `/sis/accounts/${account}/`) result = { id: account, institutional_id: 'S-FICTIONAL', balance_minor: 1000, currency: 'USD', position: 1, entries: [], active_charge_total_minor: 1000 };
        else if (path.includes('/history-checks/')) {
            if (denied) return route.fulfill({ status: 403, json: { error: 'Current support duty is required.' } });
            if (path.endsWith('/run/')) {
                if (delay) await delay();
                const status = kind === 'person' ? 'history_gaps' : kind === 'student' ? 'observed_empty' : 'verified';
                const receipt = { reference: id(20 + receipts.length), status, events_checked: 2, checked_at: '2026-08-10T17:00:00Z' };
                receipts.unshift(receipt);
                result = { result: { status, events_checked: 2, position: kind === 'student' ? null : 2, current_records: kind === 'student' ? 0 : null, issues: [] }, receipt, event_limit: 200 };
            } else result = { items: data.after ? [{ reference: id(10), status: 'limited', events_checked: 200, checked_at: '2026-08-09T17:00:00Z' }] : receipts, next_after: receipts.length && !data.after ? receipts.at(-1).reference : null };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    const dataset = { person: 'people', student: 'students', account: 'accounts' }[kind];
    await page.locator(`.workspace-nav-link[data-workspace="${dataset}"]`).click();
    await page.getByLabel('Search this workspace').fill('Fictional');
    await page.getByLabel('Search this workspace').press('Enter');
    await page.getByRole('button', { name: kind === 'account' ? 'Fictional account' : 'Fictional Person', exact: true }).click();
    return { calls, errors };
}
for (const kind of ['person', 'student', 'account']) {
    test(`${kind} support check is explicit, bounded and separate from source facts`, async ({ page }) => {
        const { calls, errors } = await fixture(page, kind);
        const label = { person: 'Check profile history', student: 'Check academic history', account: 'Check account history' }[kind];
        if (kind === 'account') await page.getByRole('button', { name: label, exact: true }).click();
        else await recordAction(page, label);
        await expect(page.getByRole('region', { name: 'Previous history checks', exact: true })).toContainText('No previous checks');
        expect(calls.some(c => c.path.endsWith('/run/'))).toBe(false);
        await page.getByRole('button', { name: 'Run history check', exact: true }).click();
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
