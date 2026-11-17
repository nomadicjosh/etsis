const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');

test('a reviewed office handoff confirms private access, preserves a retry and shows retained office history', async ({ page }) => {
    const source = id(1), target = id(2), hold = id(3), person = id(4), calls = [], errors = [];
    let accepted = false, failed = false;
    const facts = { id: hold, person_id: person, code: 'LIBRARY.HOLD', severity: 99, starts_on: '2026-08-01', ends_on: null, blocks_staff: true };
    const record = () => ({ ...facts, state: 'active', revision: 'same-academic-facts', today: '2026-08-10', timezone: 'UTC',
        office_id: accepted ? target : source, office_name: accepted ? 'Library' : 'Registrar' });
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const body = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : {};
        calls.push({ path, body, method: route.request().method(), csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/restrictions/queue/') result = { items: [{ ...record(), person_name: 'Alex Example', institutional_id: 'P-EXAMPLE' }], next_after: null };
        else if (path === `/sis/restrictions/${hold}/`) result = record();
        else if (path === '/sis/offices/') result = { items: [{ id: source, name: 'Registrar', active: 1 }, { id: target, name: 'Library', active: 1 }], can_administer: false };
        else if (path.endsWith('/notes/')) result = { items: [{ id: id(20), actor_id: id(21), actor_name: 'Jamie Example', content: 'Fictional private note.', created_at: '2026-08-10T17:00:00Z' }], next_after: null };
        else if (path.endsWith('/handoff/review/')) result = { review_token: 'fictional-protected-review', operation_id: id(22),
            from_office: 'Registrar', to_office: 'Library', hold: facts, person: { name: 'Alex Example', institutional_id: 'P-EXAMPLE' }, notes_count: 1, review_minutes: 20 };
        else if (path.endsWith('/handoff/confirm/')) {
            accepted = true;
            if (!failed) { failed = true; await route.fulfill({ status: 500, json: { error: 'Fictional temporary interruption.' } }); return; }
            result = { id: hold, operation_id: id(22), from_office_id: source, to_office_id: target };
        } else if (path.endsWith('/history/')) result = { items: [
            { action: 'placed', facts, office_name: 'Registrar', effective_on: '2026-08-01', recorded_at: '2026-08-10T17:00:00Z' },
            { action: 'office_changed', facts: null, from_office: 'Registrar', to_office: 'Library', effective_on: '2026-08-10', recorded_at: '2026-08-10T18:00:00Z' },
        ], next_cursor: null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    const accessible = async () => {
        const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(result.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
    };
    await page.goto('/admin/hold-handoff-fixture/');
    await page.locator('.workspace-nav-link[data-workspace="holds"]').click();
    await page.getByRole('button', { name: 'Review hold', exact: true }).click();
    await recordAction(page, 'Hand off to another office');
    await page.getByRole('combobox', { name: /^Receiving office/ }).selectOption(target);
    const reason = 'Fictional handoff <img src=x onerror=alert(1)>.';
    await page.getByLabel('Reason for the handoff').fill(reason);
    await page.getByRole('button', { name: 'Review office handoff', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Office handoff comparison' })).toContainText('Library');
    await expect(page.locator('#drawer-body')).toContainText(reason);
    await expect(page.locator('#drawer-body img')).toHaveCount(0);
    await accessible();
    await page.getByRole('button', { name: 'Confirm office handoff' }).click();
    expect(calls.filter(call => call.path.endsWith('/handoff/confirm/'))).toHaveLength(0);
    await page.getByRole('button', { name: 'Change handoff' }).click();
    await expect(page.getByLabel('Reason for the handoff')).toHaveValue(reason);
    await expect(page.getByRole('combobox', { name: /^Receiving office/ })).toHaveValue(target);
    await page.getByRole('button', { name: 'Review office handoff', exact: true }).click();
    await page.getByRole('checkbox', { name: /^I approve the receiving/ }).check();
    await page.getByRole('button', { name: 'Confirm office handoff' }).click();
    await expect(page.locator('#drawer-error')).toContainText('Fictional temporary interruption.');
    await expect(page.getByRole('checkbox', { name: /^I approve the receiving/ })).toBeChecked();
    await page.getByRole('button', { name: 'Confirm office handoff' }).click();
    await expect(page.locator('#drawer-body')).toContainText('Owning office: Library');
    expect(calls.filter(call => call.path.endsWith('/handoff/confirm/')).map(call => call.body)).toEqual([
        { review_token: 'fictional-protected-review', acknowledge_access: true },
        { review_token: 'fictional-protected-review', acknowledge_access: true },
    ]);
    await recordAction(page, 'Review hold history');
    await expect(page.getByRole('table', { name: 'Retained hold decisions' })).toContainText('Registrar → Library');
    await page.setViewportSize({ width: 320, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible();
    expect(errors).toEqual([]);
    expect(calls.filter(call => call.method === 'POST').every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
