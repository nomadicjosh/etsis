const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

test('registrars review paged holds, retry notes safely and end a restriction without deleting history', async ({ page }) => {
    const student = id(1), person = id(2), program = id(3), term = id(4), calls = [], errors = [];
    let nextOperation = 1000, appendFailed = false;
    const holds = Array.from({ length: 51 }, (_, index) => ({ id: id(10 + index), person_id: person,
        code: index ? `HOLD.${index}` : 'ADVISING', severity: 99, starts_on: '2026-08-01', ends_on: '2026-08-20', blocks_staff: true, version: 0 }));
    const notes = new Map([[id(10), Array.from({ length: 51 }, (_, index) => ({ id: id(200 + index), actor_id: id(9),
        actor_name: 'Taylor Example', created_at: '2026-08-10T12:00:00+00:00', content: `Fictional staff note ${index}` }))]]);
    const describe = hold => ({ ...hold, state: hold.ends_on && hold.ends_on < '2026-08-10' ? 'ended' : 'active',
        revision: `hold-${hold.version}`, today: '2026-08-10', timezone: 'UTC' });
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/operation-id/') result = { id: id(nextOperation++) };
        else if (path === '/sis/ui/data/students/') result = { items: [{ id: student, person_id: person, given_name: 'Alex', family_name: 'Example', active: true }], next_after: null };
        else if (path.startsWith('/sis/ui/')) result = { items: [], next_after: null };
        else if (path === `/sis/students/${student}/record/`) result = { id: student, person_id: person,
            given_name: 'Alex', family_name: 'Example', institutional_id: 'S-EXAMPLE', active: true,
            primary_program: { id: program, code: 'BS.CS', title: 'Computing' }, entry_term: { id: term, code: '26/FA' }, revision: 'student-revision' };
        else if (path === `/sis/students/${student}/programs/`) result = { items: [], next_after: null };
        else if (path === `/sis/persons/${person}/restrictions/`) result = {
            items: (data.after ? holds.slice(50) : holds.slice(0, 50)).map(describe), next_after: data.after ? null : holds[49].id,
        };
        else if (path === '/sis/restrictions/') {
            holds.unshift({ id: data.operation_id, person_id: person, code: data.code, severity: data.severity,
                starts_on: data.starts_on, ends_on: data.ends_on, blocks_staff: data.blocks_staff, version: 0 });
            notes.set(data.operation_id, [{ id: data.operation_id, actor_id: id(9), actor_name: 'Taylor Example',
                created_at: '2026-08-10T12:00:00+00:00', content: data.note }]);
            result = { id: data.operation_id };
        } else if (path.startsWith('/sis/restrictions/')) {
            const holdId = path.split('/')[3];
            const hold = holds.find(item => item.id === holdId);
            if (path.endsWith('/notes/append/')) {
                if (!appendFailed) {
                    appendFailed = true;
                    await route.fulfill({ status: 500, json: { error: 'Fictional temporary failure' } }); return;
                }
                notes.get(holdId).unshift({ id: data.operation_id, actor_id: id(9), actor_name: 'Taylor Example',
                    created_at: '2026-08-10T12:05:00+00:00', content: data.note });
            } else if (path.endsWith('/notes/')) {
                const rows = notes.get(holdId) || [];
                result = { items: data.after ? rows.slice(50) : rows.slice(0, 50), next_after: !data.after && rows.length > 50 ? rows[49].id : null };
            } else if (path.endsWith('/end/')) { hold.ends_on = data.ends_on; hold.version++; }
            else result = describe(hold);
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    const accessible = async () => {
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
    };
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator('#directory-search').fill('a');
    await page.locator('#directory-search').press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    const holdSection = page.locator('section[aria-label="Restrictions and holds"]');
    await expect(holdSection.locator('tbody tr')).toHaveCount(50);
    await page.getByRole('button', { name: 'Next restriction page' }).click();
    await expect(holdSection.locator('tbody tr')).toHaveCount(1);
    await page.getByRole('button', { name: 'Previous restriction page' }).click();
    await expect(holdSection.locator('tbody tr')).toHaveCount(50);
    await page.getByRole('button', { name: 'Review restriction', exact: true }).first().click();
    const noteSection = page.locator('section[aria-label="Internal staff notes"]');
    await expect(noteSection.locator('article')).toHaveCount(50);
    await page.getByRole('button', { name: 'Next note page' }).click();
    await expect(noteSection.locator('article')).toHaveCount(1);
    await page.getByRole('button', { name: 'Previous note page' }).click();
    await expect(noteSection.locator('article')).toHaveCount(50);
    await page.getByRole('button', { name: 'Add staff note', exact: true }).click();
    const message = 'Fictional follow-up <img src=x onerror=alert(1)>';
    await page.getByRole('textbox', { name: /^Staff note/ }).fill(message);
    await page.getByRole('button', { name: 'Save staff note', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('Fictional temporary failure');
    await expect(page.getByRole('textbox', { name: /^Staff note/ })).toHaveValue(message);
    await page.getByRole('button', { name: 'Save staff note', exact: true }).click();
    await expect(noteSection).toContainText(message);
    await expect(page.locator('#drawer-body img')).toHaveCount(0);
    const appends = calls.filter(call => call.path.endsWith('/notes/append/'));
    expect(appends).toHaveLength(2);
    expect(new Set(appends.map(call => call.data.operation_id)).size).toBe(1);
    await page.getByRole('button', { name: 'Back to student', exact: true }).click();
    await page.getByRole('button', { name: 'Place a restriction', exact: true }).click();
    await page.getByLabel('Restriction code').fill('ADVISING.NEW');
    await page.getByLabel(/^Starts on/).fill('2026-08-01');
    await page.getByLabel(/^Ends on/).fill('2026-08-20');
    await page.getByLabel('Initial staff note').fill('Fictional initial reason');
    await page.getByRole('button', { name: 'Place restriction', exact: true }).click();
    await expect(noteSection).toContainText('Fictional initial reason');
    const created = calls.find(call => call.path === '/sis/restrictions/');
    expect(created.data).toMatchObject({ severity: 99, blocks_staff: false, person_id: person });
    expect(created.data.operation_id).toMatch(/^[0-9]{26}$/);
    await page.getByRole('button', { name: 'Set or shorten end date' }).click();
    await page.getByLabel(/^Ends on/).fill('2026-08-09');
    await accessible();
    await page.getByRole('button', { name: 'Save restriction end date' }).click();
    await expect(page.locator('#drawer-body .status-badge')).toHaveText('ended');
    expect(calls.find(call => call.path.endsWith('/end/'))?.data).toEqual({ ends_on: '2026-08-09', revision: 'hold-0' });
    await expect(page.getByRole('button', { name: 'Set or shorten end date' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Add staff note', exact: true })).toBeVisible();
    await accessible();
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible();
    expect(errors).toEqual([]);
    expect(calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
