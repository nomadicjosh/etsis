const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K000000000000000000000${String(n).padStart(2, '0')}`;

async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
}

async function reflow(page) {
    await page.setViewportSize({ width: 320, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
}

async function access(page) {
    const state = { calls: [], errors: [] };
    const roles = [
        { id: id(1), name: 'registrar-team', description: 'Fictional registrar team. Review student academic records and transfer evaluations.', is_system: false },
        { id: id(2), name: 'student', description: 'Protected student baseline. Individual academic records still require ownership.', is_system: true },
        { id: id(3), name: 'super', description: 'Protected unrestricted administrator.', is_system: true },
    ];
    const permissions = [
        { id: id(4), name: 'sis:student:read', description: 'Read authorized student academic records.', assigned: true, effective: true },
        { id: id(5), name: 'sis:registration:manage', description: 'Registration authority inherited through a permission group.', assigned: false, effective: true },
        { id: id(6), name: 'sis:academic-records:transfer-evaluation:review-with-registrar', description: 'Fictional definition with a long identifier to check narrow-screen wrapping.', assigned: false, effective: false },
    ];
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        const raw = request.postData(), data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        state.calls.push({ path, data, method: request.method() });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/access/directory/roles/') result.items = roles;
        else if (path === '/sis/access/directory/permissions/') result.items = permissions.map(item => ({ ...item, assignable: true }));
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="access"]').click();
    await expect(page.locator('#access-roles .role-card')).toHaveCount(3);
    state.open = name => page.locator('#access-roles .role-card').filter({ has: page.getByText(name, { exact: true }) }).click();
    return state;
}

test('access setup and definition menus stay readable and require explicit deletion', async ({ page }) => {
    const state = await access(page);
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-access.png' });
    await state.open('registrar-team');
    const picker = page.locator('#drawer-body .permission-picker');
    await expect(picker.getByRole('checkbox')).toHaveCount(3);
    await expect(picker).toContainText('Direct');
    await expect(picker).toContainText('Inherited');
    const toggle = page.getByRole('button', { name: 'Definition actions for registrar-team', exact: true });
    expect((await toggle.boundingBox()).y).toBeLessThan((await picker.boundingBox()).y);
    await toggle.focus();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('button', { name: 'Edit description', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(toggle).toBeFocused();
    await page.screenshot({ path: 'test-results/workspace-polished-permissions.png' });
    await reflow(page);
    await toggle.click();
    await page.getByRole('button', { name: 'Delete definition', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm deletion', exact: true })).toBeVisible();
    expect(state.calls.some(call => ['PUT', 'PATCH', 'DELETE'].includes(call.method))).toBe(false);
    expect(state.calls.filter(call => call.path.startsWith('/sis/access/directory/')).every(call => call.data.limit === 50)).toBe(true);
    expect(state.errors).toEqual([]);
});

test('system roles retain read-only permissions and super needs no permission list', async ({ page }) => {
    const state = await access(page);
    await state.open('student');
    const permissions = page.locator('#drawer-body .permission-picker').getByRole('checkbox');
    await expect(permissions).toHaveCount(3);
    for (const control of await permissions.all()) await expect(control).toBeDisabled();
    await expect(page.getByRole('button', { name: /^Definition actions for /u, includeHidden: true })).toHaveCount(0);
    await accessible(page);
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    const reads = state.calls.filter(call => call.path === '/sis/access/directory/permissions/').length;
    await state.open('super');
    await expect(page.locator('#drawer-body')).toContainText('including future permissions');
    await expect(page.locator('#drawer-body').getByRole('checkbox', { includeHidden: true })).toHaveCount(0);
    expect(state.calls.filter(call => call.path === '/sis/access/directory/permissions/')).toHaveLength(reads);
    expect(state.calls.some(call => ['PUT', 'PATCH', 'DELETE'].includes(call.method))).toBe(false);
    expect(state.errors).toEqual([]);
});

async function message(page, status = 'draft') {
    const state = { calls: [], errors: [] };
    const wording = Array.from({ length: 18 }, (_, index) => `Fictional paragraph ${index + 1}. This notice is only a browser fixture. Review the campus welcome schedule and bring your questions.`).join('\n\n');
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        state.calls.push({ path, method: request.method() });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/campaigns/') result.items = [{ id: id(10), status }];
        else if (path === `/sis/campaigns/${id(10)}/`) result = {
            id: id(10), subject: 'Fictional campus welcome', body: wording, status, version: 3, is_owner: true, can_dispatch: true,
        };
        else if (path.endsWith('/delivery-progress/')) result = {
            status: 'held', campaign_status: 'queued', version: 2, total: 3,
            counts: { sent: 2, queued: 0, sending: 0, needs_review: 1, closed: 0 },
        };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="campaigns"]').click();
    await page.getByRole('button', { name: `…${id(10).slice(-8)}`, exact: true }).click();
    await expect(page.locator('#drawer-title')).toHaveText('Message details');
    await expect(page.locator('#drawer-body .message-preview')).toContainText('Fictional paragraph 18');
    return state;
}

test('draft tasks precede long wording and omit an empty delivery queue', async ({ page }) => {
    const state = await message(page);
    const tasks = page.getByRole('group', { name: 'Message tasks', exact: true });
    await expect(tasks.getByRole('button', { name: 'Choose recipients', exact: true })).toBeVisible();
    expect((await tasks.boundingBox()).y).toBeLessThan((await page.locator('.message-preview').boundingBox()).y);
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-message.png' });
    await reflow(page);
    await page.getByRole('button', { name: 'Edit draft', exact: true }).click();
    await expect(page.getByRole('group', { name: 'Message content', exact: true })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Message', exact: true })).toHaveValue(/Fictional paragraph 18/u);
    expect(state.calls.some(call => call.path.includes('/context/deliveries/') || call.path.endsWith('/delivery-progress/'))).toBe(false);
    expect(state.calls.some(call => /\/(queue|dispatch|delivery-confirm)\/$/u.test(call.path))).toBe(false);
    expect(state.errors).toEqual([]);
});

test('sending progress remains ahead of long message content without sending from the browser', async ({ page }) => {
    const state = await message(page, 'queued');
    const progress = page.getByRole('progressbar', { name: 'Confirmed sends', exact: true });
    await expect(progress).toHaveAttribute('aria-valuenow', '66');
    expect((await progress.boundingBox()).y).toBeLessThan((await page.locator('.message-preview').boundingBox()).y);
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-sending.png' });
    await page.getByRole('button', { name: 'Refresh progress', exact: true }).click();
    await expect(progress).toHaveAttribute('aria-valuetext', '2 of 3 messages confirmed sent');
    await reflow(page);
    expect(state.calls.filter(call => call.path.endsWith('/delivery-progress/'))).toHaveLength(2);
    expect(state.calls.some(call => /\/(queue|dispatch|delivery-confirm)\/$/u.test(call.path))).toBe(false);
    expect(state.errors).toEqual([]);
});

test('template menu keeps editing prominent and retirement requires confirmation', async ({ page }) => {
    const state = await message(page, 'template');
    const toggle = page.getByRole('button', { name: 'More template actions', exact: true });
    await expect(page.getByRole('button', { name: 'Edit template', exact: true })).toBeVisible();
    await toggle.focus();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('button', { name: 'Submit for institutional approval', exact: true })).toBeFocused();
    await accessible(page);
    await page.keyboard.press('Escape');
    await expect(toggle).toBeFocused();
    await toggle.click();
    await page.getByRole('button', { name: 'Retire template', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm template retirement', exact: true })).toBeVisible();
    expect(state.calls.some(call => call.path.endsWith('/template/status/') || call.path.endsWith('/template/review/'))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(state.errors).toEqual([]);
});
