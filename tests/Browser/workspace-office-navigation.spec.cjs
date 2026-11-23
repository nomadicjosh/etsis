const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
const body = page => page.locator('#drawer-body');
const offices = page => body(page).getByRole('list', { name: 'Institution offices', exact: true });
const delegates = page => body(page).getByRole('list', { name: 'Office delegates', exact: true });
const control = (page, name) => body(page).getByRole('button', { name, exact: true });
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(row => ({ id: row.id, nodes: row.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await body(page).evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
}
async function fixture(page, { dense = false } = {}) {
    const state = { calls: [], errors: [], fail: false, deny: false, delay: null };
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    state.release = release;
    state.offices = Array.from({ length: dense ? 20 : 1 }, (_, n) => ({
        id: id(1 + n), code: `OFFICE-${n}`, name: n ? `Fictional ${'StudentServices'.repeat(5)} ${n}` : 'Library <script>fictional</script>', active: n % 2 ? 0 : 1, revision: 2,
    }));
    state.members = Array.from({ length: dense ? 50 : 2 }, (_, n) => ({
        id: id(100 + n), user_id: id(200 + n), first_name: dense ? 'Fictional'.repeat(6) : n ? 'Second' : 'First', last_name: 'Example',
        starts_on: '2026-08-01', ends_on: n % 2 ? '2026-08-20' : null, active: n % 2 ? 0 : 1, revision: 3,
    }));
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const req = route.request(), path = new URL(req.url()).pathname;
        const data = req.postData() ? JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json')) : {};
        state.calls.push({ path, data, method: req.method(), csrf: req.headers()['x-csrf-token'] });
        const delayed = state.delay === path;
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/offices/') {
            if (state.denyOffices) return route.fulfill({ status: 403, json: { error: 'Fictional access denied.' } });
            result = { items: state.offices, can_administer: true, today: '2026-08-10' };
        }
        else if (path === '/sis/ui/operation-id/') result = { id: id(900) };
        else if (path === `/sis/offices/${id(1)}/delegates/`) {
            if (state.deny) return route.fulfill({ status: 403, json: { error: 'Fictional access denied.' } });
            if (state.fail) { state.fail = false; return route.fulfill({ status: 503, json: { error: 'Fictional page unavailable.' } }); }
            result = { office: state.offices[0], today: '2026-08-10', items: dense ? state.members : [state.members[data.after ? 1 : 0]], next_after: dense || data.after ? null : id(100) };
        } else if (path === '/sis/offices/accounts/') {
            if (state.deny) return route.fulfill({ status: 403, json: { error: 'Fictional access denied.' } });
            result.items = [{ owner_user_id: id(300), given_name: 'Jamie', family_name: 'Example', institutional_id: 'P-JAMIE' }];
        }
        else if (path.endsWith('/delegates/save/')) {
            const member = state.members.find(row => row.user_id === data.user_id);
            if (member) Object.assign(member, data, { active: Number(data.active), revision: data.revision + 1 });
            result = { status: 'saved' };
        } else if (path.endsWith('/save/')) {
            const office = state.offices.find(row => row.id === path.split('/')[3]);
            if (office) Object.assign(office, data, { active: Number(data.active), revision: data.revision + 1 });
            result = { status: 'saved' };
        }
        if (delayed) await pending;
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/office-admin-fixture/');
    await page.locator('.workspace-nav-link[data-workspace="holds"]').click();
    state.open = () => page.getByRole('button', { name: 'Offices & delegation', exact: true }).click();
    state.manage = () => offices(page).locator('li').first().getByRole('button', { name: 'Manage delegates', exact: true }).click();
    return state;
}

test('office and delegate returns keep their context and accepted delegate page without writes', async ({ page }) => {
    const f = await fixture(page); await f.open();
    await recordAction(page, 'Edit office');
    await expect(page.getByLabel(/^Office name/)).toHaveValue(f.offices[0].name);
    await control(page, 'Back to offices').click();
    await f.manage();
    await control(page, 'Next delegate page').click();
    await expect(delegates(page)).toContainText('Second Example');
    await control(page, 'Edit delegation').click();
    await expect(body(page)).toContainText(f.offices[0].name);
    await control(page, 'Back to office delegates').click();
    await expect(delegates(page)).toContainText('Second Example');
    await control(page, 'Add delegate').click();
    await control(page, 'Back to office delegates').click();
    await expect(delegates(page)).toContainText('Second Example');
    await control(page, 'Back to offices').click();
    await expect(offices(page)).toContainText(f.offices[0].name);
    await control(page, 'Back to holds').click();
    await expect(page.locator('#record-drawer')).not.toHaveClass(/\bshow\b/);
    expect(f.calls.some(call => /\/(save|delegates\/save)\/$/.test(call.path))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('delegate paging preserves the accepted cursor after failure and owns unavailable buttons', async ({ page }) => {
    const f = await fixture(page); await f.open(); await f.manage();
    await expect(control(page, 'Previous delegate page')).toBeDisabled();
    f.fail = true; await control(page, 'Next delegate page').click();
    await expect(body(page)).toContainText('previous page remains displayed');
    await expect(delegates(page)).toContainText('First Example');
    await expect(control(page, 'Previous delegate page')).toBeDisabled();
    await control(page, 'Next delegate page').click();
    await expect(delegates(page)).toContainText('Second Example');
    await expect(control(page, 'Next delegate page')).toBeDisabled();
    await control(page, 'Refresh delegate page').click();
    await expect(delegates(page)).toContainText('Second Example');
    await expect(control(page, 'Next delegate page')).toBeDisabled();
    await control(page, 'Previous delegate page').click();
    await expect(delegates(page)).toContainText('First Example');
    await expect(control(page, 'Previous delegate page')).toBeDisabled();
    expect(f.calls.filter(call => call.path.endsWith('/delegates/')).map(call => call.data.after)).toEqual(['', id(100), id(100), id(100), '']);
    expect(f.errors).toEqual([]);
});

test('delegate changes retain dates, revision and office scope and return to the viewed page', async ({ page }) => {
    const f = await fixture(page); await f.open(); await f.manage();
    await control(page, 'Next delegate page').click(); await control(page, 'Edit delegation').click();
    await page.getByLabel('Enable this delegation').check();
    await page.getByLabel('Delegation ends').fill('2026-08-25');
    await control(page, 'Save office delegation').click();
    await expect(delegates(page)).toContainText('Second Example');
    await expect(delegates(page)).toContainText('Enabled');
    const saved = f.calls.find(call => call.path.endsWith('/delegates/save/'));
    expect(saved.data).toMatchObject({ user_id: id(201), revision: 3, starts_on: '2026-08-01', ends_on: '2026-08-25', active: true });
    expect(saved.csrf).toBe('fictional-test-token');
    expect(f.calls.some(call => call.path.includes('/sis/access/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('a denied delegate refresh clears names and mutation controls', async ({ page }) => {
    const f = await fixture(page); await f.open(); await f.manage();
    await expect(delegates(page)).toContainText('First Example');
    f.deny = true; await control(page, 'Refresh delegate page').click();
    await expect(body(page)).toContainText('Office delegation access is no longer available.');
    await expect(body(page)).not.toContainText('First Example');
    await expect(body(page)).not.toContainText(f.offices[0].name);
    await expect(control(page, 'Add delegate')).toHaveCount(0);
    await control(page, 'Back to offices').click();
    await expect(offices(page)).toBeVisible();
    expect(f.errors).toEqual([]);
});

test('a denied office library retains its workspace return without showing office facts', async ({ page }) => {
    const f = await fixture(page); f.denyOffices = true; await f.open();
    await expect(body(page)).toContainText('Office setup access is no longer available.');
    await expect(body(page)).not.toContainText(f.offices[0].name);
    await expect(control(page, 'Add office')).toHaveCount(0);
    await control(page, 'Back to holds').click();
    await expect(page.locator('#record-drawer')).not.toHaveClass(/\bshow\b/);
    expect(f.errors).toEqual([]);
});

test('office rows and a full delegate page reflow with long names and accessible Bootstrap actions', async ({ page }) => {
    const f = await fixture(page, { dense: true }); await f.open();
    await expect(offices(page).locator(':scope > li')).toHaveCount(20);
    const first = offices(page).locator(':scope > li').first();
    await first.getByRole('button', { name: /Office actions for/ }).click();
    await page.keyboard.press('ArrowDown');
    await expect(first.getByRole('button', { name: 'Edit office', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(first.getByRole('button', { name: /Office actions for/ })).toBeFocused();
    for (const width of [1440, 768, 320]) {
        await page.setViewportSize({ width, height: 900 });
        await accessible(page);
        if (width !== 768) await page.screenshot({ path: `/tmp/etsis-offices-${width}.png` });
    }
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await accessible(page);
    await first.getByRole('button', { name: /Office actions for/ }).click();
    const edit = first.getByRole('button', { name: 'Edit office', exact: true });
    await expect(edit).toBeVisible();
    const bounds = await edit.boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(320);
    expect(bounds.y).toBeGreaterThanOrEqual(0);
    expect(bounds.y + bounds.height).toBeLessThanOrEqual(900);
    await page.keyboard.press('Escape');
    await f.manage();
    await expect(delegates(page).locator('li')).toHaveCount(50);
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-office-delegates-phone-zoom.png' });
    await delegates(page).locator('li').last().scrollIntoViewIfNeeded();
    await expect(delegates(page).locator('li').last()).toBeInViewport();
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('a denied delegate search removes previous account matches and keeps its return', async ({ page }) => {
    const f = await fixture(page); await f.open(); await f.manage();
    await expect(delegates(page)).toBeVisible(); await control(page, 'Add delegate').click();
    await expect(body(page)).toContainText(f.offices[0].name);
    await page.getByRole('searchbox', { name: 'Find an enabled account' }).fill('Jamie');
    await control(page, 'Search people').click();
    await expect(control(page, 'Jamie Example · P-JAMIE')).toBeVisible();
    f.deny = true; await control(page, 'Search people').click();
    await expect(control(page, 'Jamie Example · P-JAMIE')).toHaveCount(0);
    await expect(body(page)).toContainText('Access to this search is no longer available.');
    f.deny = false; await control(page, 'Back to office delegates').click();
    await expect(delegates(page)).toBeVisible();
    expect(f.errors).toEqual([]);
});

test('office setup opened from roles and access returns to that workspace', async ({ page }) => {
    const f = await fixture(page);
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="access"]').click();
    await page.getByRole('button', { name: 'Offices & delegation', exact: true }).click();
    await expect(offices(page)).toBeVisible();
    await recordAction(page, 'Edit office');
    await control(page, 'Back to offices').click();
    await control(page, 'Back to roles & access').click();
    await expect(page.locator('#record-drawer')).not.toHaveClass(/\bshow\b/);
    await expect(page.locator('#access-roles')).toContainText('Permission definitions');
    expect(f.calls.some(call => call.path.endsWith('/save/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

for (const stage of ['office list', 'office preparation', 'delegate choice', 'delegation save']) {
    test(`closing a pending ${stage} prevents late navigation`, async ({ page }) => {
        const f = await fixture(page);
        if (stage === 'office list') { f.delay = '/sis/offices/'; await f.open(); }
        else {
            await f.open();
            if (stage === 'office preparation') { f.delay = '/sis/ui/operation-id/'; await control(page, 'Add office').click(); }
            else {
                await f.manage(); await expect(delegates(page)).toBeVisible();
                if (stage === 'delegate choice') {
                    await control(page, 'Add delegate').click();
                    await page.getByRole('searchbox', { name: 'Find an enabled account' }).fill('Jamie');
                    await control(page, 'Search people').click();
                    f.delay = `/sis/offices/${id(1)}/delegates/`;
                    await control(page, 'Jamie Example · P-JAMIE').click();
                } else {
                    await control(page, 'Edit delegation').click();
                    f.delay = `/sis/offices/${id(1)}/delegates/save/`;
                    await control(page, 'Save office delegation').click();
                }
            }
        }
        await expect.poll(() => f.calls.some(call => call.path === f.delay)).toBe(true);
        await page.getByRole('button', { name: 'Close record', exact: true }).click();
        await expect(body(page)).toBeEmpty();
        f.release();
        await page.waitForLoadState('networkidle');
        await expect(body(page)).toBeEmpty();
        await expect(page.locator('#record-drawer')).not.toHaveClass(/\bshow\b/);
        if (stage === 'delegation save') expect(f.members[0].revision).toBe(4);
        expect(f.errors).toEqual([]);
    });
}
