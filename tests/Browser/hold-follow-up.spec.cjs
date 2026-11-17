const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

test('office follow-up keeps drafts on interruption, filters the worklist and renders colleague names safely', async ({ page }) => {
    const hold = id(1), person = id(2), office = id(3), colleague = id(4), actor = id(5), calls = [], errors = [];
    const name = 'Jamie <img src=x onerror=alert(1)> Example';
    let failed = false;
    const work = { office_id: office, assigned_user_id: null, assignee_name: null, follow_up_on: null,
        work_revision: 0, assignee_eligible: true, can_assign_self: true, self_user_id: actor, today: '2026-08-10' };
    const record = () => ({ id: hold, person_id: person, code: 'LIBRARY.HOLD', severity: 99, starts_on: '2026-08-01',
        ends_on: null, blocks_staff: true, state: 'active', revision: 'original-hold', today: work.today, timezone: 'UTC',
        office_id: office, office_name: 'Library', follow_up: { ...work } });
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const body = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : {};
        calls.push({ path, body, method: route.request().method(), csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/restrictions/queue/') result = { items: [{ ...record(), person_name: 'Alex Example', institutional_id: 'P-EXAMPLE',
            assignee_name: work.assignee_name, follow_up_on: work.follow_up_on }], next_after: null };
        else if (path === `/sis/restrictions/${hold}/`) result = record();
        else if (path.endsWith('/follow-up/people/')) result = { items: [{ id: colleague, name }], next_after: null };
        else if (path.endsWith('/follow-up/')) result = { ...work };
        else if (path.endsWith('/follow-up/save/')) {
            work.assigned_user_id = body.assigned_user_id;
            work.assignee_name = body.assigned_user_id === colleague ? name : null;
            work.follow_up_on = body.follow_up_on || null;
            work.work_revision = 1;
            if (!failed) { failed = true; await route.fulfill({ status: 500, json: { error: 'Fictional temporary interruption.' } }); return; }
            result = { status: 'saved' };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/hold-office-fixture/');
    await page.locator('.workspace-nav-link[data-workspace="holds"]').click();
    await page.getByRole('button', { name: 'Review hold', exact: true }).click();
    await page.getByRole('button', { name: 'Follow-up & assignment', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Assign to me', exact: true })).toBeVisible();
    await page.getByRole('button', { name: `Choose ${name}`, exact: true }).click();
    await page.getByLabel('Follow-up date', { exact: true }).fill('2026-08-10');
    const accessible = async () => {
        const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(result.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
    };
    await expect(page.locator('#drawer-body img')).toHaveCount(0);
    await accessible();
    await page.getByRole('button', { name: 'Save follow-up', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('Fictional temporary interruption.');
    await expect(page.getByLabel('Follow-up date', { exact: true })).toHaveValue('2026-08-10');
    await expect(page.locator('#drawer-body')).toContainText(`Responsible colleague: ${name}`);
    await page.getByRole('button', { name: 'Save follow-up', exact: true }).click();
    await expect(page.getByText('Assigned to', { exact: true }).locator('..').locator('dd')).toHaveText(name);
    await expect(page.getByText('Follow-up date', { exact: true }).locator('..').locator('dd')).toHaveText('2026-08-10');
    const saves = calls.filter(call => call.path.endsWith('/follow-up/save/'));
    expect(saves.map(call => call.body)).toEqual([0, 1].map(() => ({ office_id: office, work_revision: 0,
        assigned_user_id: colleague, follow_up_on: '2026-08-10' })));
    await page.getByRole('button', { name: 'Follow-up & assignment', exact: true }).click();
    await page.getByRole('button', { name: 'Clear follow-up', exact: true }).click();
    await expect(page.getByLabel('Follow-up date', { exact: true })).toHaveValue('');
    await expect(page.locator('#drawer-body')).toContainText('Responsible colleague: Unassigned');
    expect(calls.filter(call => call.path.endsWith('/follow-up/save/'))).toHaveLength(2);
    await page.setViewportSize({ width: 320, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible();
    await page.getByRole('button', { name: 'Back to restriction', exact: true }).click();
    await page.getByRole('button', { name: 'Back to holds', exact: true }).click();
    await page.getByLabel('Assignment filter', { exact: true }).selectOption('mine');
    await page.getByLabel('Due today or earlier', { exact: true }).check();
    await page.getByRole('button', { name: 'Find holds', exact: true }).click();
    await expect.poll(() => calls.filter(call => call.path === '/sis/restrictions/queue/').at(-1)?.body).toMatchObject({ assignment: 'mine', due: true });
    await accessible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(errors).toEqual([]);
    expect(calls.filter(call => call.method === 'POST').every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(calls.some(call => /^\/sis\/(students|accounts|grades)\//.test(call.path))).toBe(false);
});
