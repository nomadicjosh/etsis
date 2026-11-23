const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');

async function fixture(page, { delay = null, dense = false } = {}) {
    const state = { calls: [], errors: [], deny: false, failNext: false, rejectWrite: false };
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    state.release = release;
    state.roles = [
        { id: id(1), name: 'registrar-team', description: 'Fictional team <script>reference</script>.', is_system: false },
        { id: id(2), name: 'student', description: 'Protected student baseline.', is_system: true },
        { id: id(3), name: 'super', description: 'Protected unrestricted role.', is_system: true },
    ];
    state.permissions = [
        { id: id(11), name: 'custom:read', description: 'Read fictional academic records.', is_system: false },
        { id: id(12), name: 'custom:write', description: 'Maintain fictional academic records.', is_system: false },
        { id: id(13), name: 'sis:academic-records:transfer-evaluation:review-with-registrar', description: 'Protected definition with a long identifier.', is_system: true },
    ];
    state.direct = new Map([[id(11), true], [id(12), false], [id(13), false]]);
    if (dense) {
        state.permissions = Array.from({ length: 50 }, (_, n) => ({
            id: id(100 + n), name: `sis:academic-records:fictional-transfer-evaluation:permission-${n}`,
            description: n % 2 ? `Fictional policy reference: ${'LONGREFERENCE'.repeat(20)}`
                : 'Fictional permission description for authorized academic staff. '.repeat(5),
            is_system: n % 3 === 0,
        }));
        state.permissions.forEach((permission, n) => state.direct.set(permission.id, n % 3 === 0));
    }
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname, method = request.method(), raw = request.postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        state.calls.push({ path, method, data, csrf: request.headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/people/') result.items = [{ id: id(30), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }];
        else if (path === `/sis/ui/people/${id(30)}/`) result = { person: { id: { value: id(30) }, givenName: 'Alex', familyName: 'Example', institutionalId: { value: 'S-FICTIONAL' }, ownerUserId: { value: id(31) } } };
        else if (path.startsWith('/sis/access/directory/')) {
            if (state.deny) return route.fulfill({ status: 403, json: { error: 'Fictional access denied.' } });
            const roles = path.endsWith('/roles/');
            if (!roles && !data.grant && delay === 'directory') await pending;
            if (state.failNext && data.after) {
                state.failNext = false;
                return route.fulfill({ status: 503, json: { error: 'Fictional page unavailable.' } });
            }
            let items = roles ? state.roles : state.permissions;
            if (data.search) items = items.filter(row => `${row.name} ${row.description}`.toLowerCase().includes(data.search.toLowerCase()));
            if (data.grant) {
                items = items.map(row => ({ ...row,
                    assigned: roles ? row.id === id(1) : state.direct.get(row.id),
                    effective: roles ? row.id === id(1) : row.id !== id(13) || state.direct.get(row.id),
                    assignable: row.name !== 'super',
                }));
                if (data.assigned_only) items = items.filter(row => row.assigned);
                result.items = items;
            } else if (!roles && !dense) {
                result = { items: data.after ? items.slice(1) : items.slice(0, 1), next_after: !data.after && items.length > 1 ? id(50) : null };
            } else result.items = items;
        } else if (path === `/sis/access/permissions/${id(12)}/` && method === 'PATCH') {
            state.permissions.find(row => row.id === id(12)).description = data.description;
            if (delay === 'save') await pending;
            result = {};
        } else if (path === `/sis/access/permissions/${id(12)}/` && method === 'DELETE') {
            state.permissions = state.permissions.filter(row => row.id !== id(12)); result = {};
        } else if (path.startsWith(`/sis/access/roles/${id(1)}/permissions/`) && ['PUT', 'DELETE'].includes(method)) {
            if (state.rejectWrite) {
                state.rejectWrite = false;
                return route.fulfill({ status: 409, json: { error: 'Fictional assignment changed. Refresh and retry.' } });
            }
            state.direct.set(path.split('/').at(-2), method === 'PUT'); result = {};
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="access"]').click();
    await expect(page.locator('#access-roles .role-card')).toHaveCount(3);
    state.role = () => page.locator('#access-roles .role-card').filter({ has: page.getByText('registrar-team', { exact: true }) }).click();
    state.definitions = () => page.getByRole('button', { name: 'Permission definitions', exact: true }).click();
    state.permission = () => page.locator('#drawer-body .role-card').filter({ has: page.getByText('custom:write', { exact: true }) }).click();
    return state;
}

async function nonOverlapping(rows) {
    const problems = await rows.evaluateAll(elements => elements.flatMap((row, index) => {
        const r = row.getBoundingClientRect();
        const previous = elements[index - 1]?.getBoundingClientRect();
        const result = [];
        if (previous && r.top < previous.bottom - 1 && r.left < previous.right && r.right > previous.left)
            result.push(`row ${index}: overlaps previous row`);
        for (const child of row.querySelectorAll('input, label, strong, small')) {
            const c = child.getBoundingClientRect();
            if (c.left < r.left - 1 || c.right > r.right + 1 || c.top < r.top - 1 || c.bottom > r.bottom + 1 || child.scrollWidth > child.clientWidth + 1)
                result.push(`row ${index}: ${child.tagName} extends outside its box`);
        }
        return result;
    }));
    expect(problems.slice(0, 10)).toEqual([]);
}

for (const list of ['role', 'definitions']) {
    test(`a full ${list} permission page has no overlapping boxes or labels`, async ({ page }) => {
        const f = await fixture(page, { dense: true });
        if (list === 'role') await f.role(); else await f.definitions();
        const rows = page.locator(list === 'role' ? '#drawer-body .access-grant' : '#drawer-body .role-card');
        await expect(rows).toHaveCount(50);
        await expect(page.locator('#record-drawer')).toHaveClass(/\bshow\b/);
        await expect(page.locator('#record-drawer')).not.toHaveClass(/\bshowing\b/);
        await page.evaluate(() => document.fonts.ready);
        for (const width of [1440, 1024, 768, 375, 320]) {
            await page.setViewportSize({ width, height: 900 });
            await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            await nonOverlapping(rows);
            if (width === 1440 || width === 320) {
                await page.screenshot({ path: `/tmp/etsis-permission-${list}-dense-${width}.png` });
            }
        }
        await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
        await nonOverlapping(rows);
        await rows.last().scrollIntoViewIfNeeded();
        await expect(rows.last()).toBeInViewport();
        await accessible(page);
        expect(f.errors).toEqual([]);
    });
}

async function secondPermission(page, f) {
    await f.definitions();
    await page.getByLabel('Search permissions', { exact: true }).fill('custom');
    await page.locator('#drawer-body').getByRole('button', { name: 'Find', exact: true }).click();
    await page.locator('#drawer-body').getByRole('button', { name: 'Next page', exact: true }).click();
    await f.permission();
}

async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}

test('permission definitions return to their searched page without an unrelated record link', async ({ page }) => {
    const f = await fixture(page);
    await secondPermission(page, f);
    await page.getByRole('button', { name: 'Back to permission definitions', exact: true }).click();
    await expect(page.getByLabel('Search permissions', { exact: true })).toHaveValue('custom');
    await expect(page.locator('#drawer-body .role-card')).toContainText('custom:write');
    await expect(page.locator('#drawer-body').getByRole('button', { name: 'Next page', exact: true })).toBeDisabled();
    await expect(page.locator('#drawer-body').getByRole('button', { name: 'Previous page', exact: true })).toBeEnabled();
    await expect(page.getByRole('button', { name: 'Back to record', exact: true })).toHaveCount(0);
    expect(f.calls.filter(call => call.path.endsWith('/directory/permissions/')).at(-1).data)
        .toMatchObject({ search: 'custom', after: id(50), limit: 50 });
    expect(f.errors).toEqual([]);
});

test('role edit and delete returns retain the permission filter and make no changes', async ({ page }) => {
    const f = await fixture(page); await f.role();
    await page.getByLabel('Search permissions', { exact: true }).fill('custom');
    await page.locator('#drawer-body').getByRole('button', { name: 'Find', exact: true }).click();
    await page.getByLabel('Show direct assignments only', { exact: true }).check();
    await recordAction(page, 'Edit description');
    await page.getByLabel('Description', { exact: false }).fill('Unsubmitted description.');
    await page.getByRole('button', { name: 'Back to role', exact: true }).click();
    await expect(page.getByLabel('Search permissions', { exact: true })).toHaveValue('custom');
    await expect(page.getByLabel('Show direct assignments only', { exact: true })).toBeChecked();
    await expect(page.locator('.permission-picker').getByRole('checkbox')).toHaveCount(1);
    await recordAction(page, 'Delete definition');
    await page.getByRole('button', { name: 'Back to role', exact: true }).click();
    await expect(page.getByLabel('Show direct assignments only', { exact: true })).toBeChecked();
    expect(f.calls.some(call => ['PATCH', 'PUT', 'DELETE'].includes(call.method))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('permission description saves and explicit deletion reopen the current definition library', async ({ page }) => {
    const f = await fixture(page); await secondPermission(page, f);
    await recordAction(page, 'Edit description');
    await page.getByLabel('Description', { exact: false }).fill('Accepted fictional description.');
    await page.getByRole('button', { name: 'Save description', exact: true }).click();
    await expect(page.locator('#drawer-body .role-card')).toContainText('Accepted fictional description.');
    await f.permission(); await recordAction(page, 'Delete definition');
    expect(f.calls.some(call => call.method === 'DELETE')).toBe(false);
    await page.getByRole('button', { name: 'Confirm deletion', exact: true }).click();
    await expect(page.getByLabel('Search permissions', { exact: true })).toHaveValue('custom');
    await expect(page.locator('#drawer-body')).toContainText('No matching definitions.');
    await expect(page.getByRole('button', { name: 'Back to record', exact: true })).toHaveCount(0);
    expect(f.calls.filter(call => ['PATCH', 'DELETE'].includes(call.method)).map(call => call.path))
        .toEqual([`/sis/access/permissions/${id(12)}/`, `/sis/access/permissions/${id(12)}/`]);
    expect(f.errors).toEqual([]);
});

test('a failed directory page preserves accepted paging and supports clear-search recovery', async ({ page }) => {
    const f = await fixture(page); await f.definitions();
    f.failNext = true;
    await page.locator('#drawer-body').getByRole('button', { name: 'Next page', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('previous page remains displayed');
    await expect(page.locator('#drawer-body .role-card')).toContainText('custom:read');
    await expect(page.locator('#drawer-body').getByRole('button', { name: 'Previous page', exact: true })).toBeDisabled();
    await page.locator('#drawer-body').getByRole('button', { name: 'Next page', exact: true }).click();
    await expect(page.locator('#drawer-body').getByRole('button', { name: 'Next page', exact: true })).toBeDisabled();
    await page.getByLabel('Search permissions', { exact: true }).fill('custom');
    await page.locator('#drawer-body').getByRole('button', { name: 'Find', exact: true }).click();
    await page.locator('#drawer-body').getByRole('button', { name: 'Clear search', exact: true }).click();
    await expect(page.getByLabel('Search permissions', { exact: true })).toHaveValue('');
    await expect(page.locator('#drawer-body').getByRole('button', { name: 'Clear search', exact: true })).toBeDisabled();
    expect(f.errors).toEqual([]);
});

test('direct grant changes retain inherited access and rejected changes revert the checkbox', async ({ page }) => {
    const f = await fixture(page); await f.role();
    const input = page.locator('.permission-picker').getByRole('checkbox', { name: /^custom:write/u });
    f.rejectWrite = true;
    await input.check(); await expect(page.locator('#drawer-error')).toContainText('Fictional assignment changed.');
    await expect(input).not.toBeChecked();
    await input.check(); await expect(input).toBeChecked();
    await expect(input.locator('..')).toContainText('Direct');
    await input.uncheck(); await expect(input).not.toBeChecked();
    await expect(input.locator('..')).toContainText('Inherited');
    expect(f.calls.filter(call => ['PUT', 'DELETE'].includes(call.method)).map(call => call.method)).toEqual(['PUT', 'PUT', 'DELETE']);
    expect(f.calls.filter(call => ['PUT', 'DELETE'].includes(call.method)).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(f.errors).toEqual([]);
});

test('a denied role refresh clears cached grant controls and the role directory', async ({ page }) => {
    const f = await fixture(page); await f.role();
    await expect(page.locator('.permission-picker').getByRole('checkbox')).toHaveCount(3);
    f.deny = true;
    await page.getByRole('button', { name: 'Refresh permissions', exact: true }).click();
    await expect(page.locator('#drawer-title')).toHaveText('Access unavailable');
    await expect(page.locator('#drawer-body')).not.toContainText('fictional academic records');
    await expect(page.locator('#drawer-body').getByRole('checkbox')).toHaveCount(0);
    await expect(page.locator('#access-roles .role-card')).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('denied person access clears both assignment panels while preserving the person workspace', async ({ page }) => {
    const f = await fixture(page);
    await page.locator('.workspace-nav-link[data-workspace="people"]').click();
    await page.getByLabel('Search this workspace', { exact: true }).fill('Alex');
    await page.getByLabel('Search this workspace', { exact: true }).press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await page.getByRole('tab', { name: 'Access', exact: true }).click();
    const panel = page.getByRole('tabpanel', { name: 'Access', exact: true });
    await expect(panel.getByRole('checkbox')).toHaveCount(8);
    f.deny = true;
    await panel.getByRole('button', { name: 'Refresh permissions', exact: true }).click();
    await expect(panel.getByRole('checkbox')).toHaveCount(0);
    await expect(panel).toContainText('Access is no longer available.');
    await expect(page.getByRole('heading', { name: 'Alex Example', exact: true })).toBeVisible();
    expect(f.errors).toEqual([]);
});

test('access controls and definition menus reflow at phone width and text zoom', async ({ page }) => {
    const f = await fixture(page); await f.role();
    await expect(page.locator('.permission-picker').getByRole('checkbox')).toHaveCount(3);
    await expect(page.locator('#drawer-body script')).toHaveCount(0);
    await accessible(page); await page.screenshot({ path: '/tmp/etsis-access-role-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 }); await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-access-role-phone.png' });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' }); await accessible(page);
    const toggle = page.getByRole('button', { name: 'Definition actions for registrar-team', exact: true });
    await toggle.focus(); await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('button', { name: 'Edit description', exact: true })).toBeFocused();
    const menu = page.locator('#drawer-body .dropdown-menu.show');
    expect(await menu.evaluate(el => {
        const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth + 1;
    })).toBe(true);
    await page.keyboard.press('Escape'); await expect(toggle).toBeFocused();
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('protected permission definitions stay read-only and their identifiers reflow', async ({ page }) => {
    const f = await fixture(page); await f.definitions();
    await page.locator('#drawer-body').getByRole('button', { name: 'Next page', exact: true }).click();
    await page.locator('#drawer-body .role-card').filter({ has: page.getByText(f.permissions[2].name, { exact: true }) }).click();
    await expect(page.locator('.access-record-context')).toContainText('Protected system permission');
    await expect(page.getByRole('button', { name: /^Definition actions for/u })).toHaveCount(0);
    await page.setViewportSize({ width: 320, height: 812 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await accessible(page);
    expect(f.calls.some(call => ['PATCH', 'DELETE', 'PUT'].includes(call.method))).toBe(false);
    expect(f.errors).toEqual([]);
});

for (const delay of ['directory', 'save']) {
    test(`a delayed access ${delay} result cannot reopen a closed drawer`, async ({ page }) => {
        const f = await fixture(page, { delay });
        if (delay === 'save') {
            await secondPermission(page, f); await recordAction(page, 'Edit description');
            await page.getByLabel('Description', { exact: false }).fill('Accepted after closing.');
            await page.getByRole('button', { name: 'Save description', exact: true }).click();
        } else await f.definitions();
        const target = delay === 'save' ? `/sis/access/permissions/${id(12)}/` : '/sis/access/directory/permissions/';
        await expect.poll(() => f.calls.some(call => call.path === target)).toBe(true);
        await page.getByRole('button', { name: 'Close record', exact: true }).click();
        await expect(page.locator('#drawer-body')).toBeEmpty();
        const finished = page.waitForEvent('requestfinished', { predicate: request => new URL(request.url()).pathname === target });
        f.release(); await finished; await page.waitForLoadState('networkidle');
        await expect(page.locator('#drawer-body')).toBeEmpty();
        if (delay === 'save') expect(f.permissions.find(row => row.id === id(12)).description).toBe('Accepted after closing.');
        expect(f.errors).toEqual([]);
    });
}
