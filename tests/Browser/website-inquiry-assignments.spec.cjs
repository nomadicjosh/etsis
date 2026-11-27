'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
const actor = id(1), actorPerson = id(2), colleague = id(3), colleaguePerson = id(4);
async function fixture(page, { many = false, readonly = false, pagedDirectory = false } = {}) {
    const f = { calls: [], errors: [], assignments: new Map(), removed: new Set(), stale: false,
        directoryDelay: false, directoryArrived: false, directoryRelease: null, denied: false, saveDelay: false, saveArrived: false, saveRelease: null };
    const rows = Array.from({ length: many ? 21 : 1 }, (_, i) => ({ id: id(100 - i), page_title: 'Fictional page',
        form_title: `Fictional inquiry ${100 - i}`, status: 'open', submitted_at: '2026-11-27T12:00:00Z', version: 0 }));
    const assignment = item => ({ version: 0, assignee_id: null, assignee_person_id: null, name: 'Unassigned',
        ...f.assignments.get(item.id), inquiry_version: item.version, actor_id: actor, actor_person_id: actorPerson,
        can_assign: !readonly && item.status === 'open' });
    page.on('pageerror', error => f.errors.push(error.message));
    await page.route('**/sis/website/form-inbox/**', async route => {
        const request = route.request(), url = new URL(request.url()), path = url.pathname;
        const payload = request.postData();
        const data = payload ? JSON.parse(new URLSearchParams(payload).get('_workspace_json')) : null;
        f.calls.push({ path, query: Object.fromEntries(url.searchParams), data, csrf: request.headers()['x-csrf-token'] });
        if (f.denied) return route.fulfill({ status: 403, json: { error: 'Fictional access ended.' } });
        const item = rows.find(row => path.includes(row.id));
        let result;
        if (path.endsWith('/assignees/')) {
            if (f.directoryDelay) { f.directoryArrived = true; await new Promise(resolve => { f.directoryRelease = resolve; }); }
            result = pagedDirectory ? { items: url.searchParams.has('after')
                ? [{ id: id(500), person_id: id(1500), given_name: 'Fictional', family_name: 'Later colleague' }]
                : Array.from({ length: 20 }, (_, index) => ({ id: id(200 + index), person_id: id(1200 + index),
                    given_name: 'Fictional', family_name: 'LongColleague'.repeat(12) + index })),
                next_after: url.searchParams.has('after') ? null : id(219) }
                : { items: [{ id: colleague, person_id: colleaguePerson, given_name: 'Fictional', family_name: '<img> Long Colleague' }], next_after: null };
        } else if (path.endsWith('/assignment/')) {
            if (f.saveDelay) { f.saveArrived = true; await new Promise(resolve => { f.saveRelease = resolve; }); }
            const current = assignment(item);
            if (f.stale) { f.stale = false; f.assignments.set(item.id, { ...current, version: current.version + 1 }); }
            if (data.version !== assignment(item).version) return route.fulfill({ status: 409, json: { error: 'Fictional assignment changed.' } });
            const saved = { ...current, version: assignment(item).version + 1, assignee_id: data.assignee_id,
                assignee_person_id: data.assignee_person_id,
                name: data.assignee_id === actor ? 'Fictional Operator' : data.assignee_id ? 'Fictional <img> Long Colleague' : 'Unassigned' };
            f.assignments.set(item.id, saved); result = saved;
        } else if (request.method() === 'POST') {
            if (data.action === 'delete') { f.removed.add(item.id); f.assignments.delete(item.id); }
            else { item.status = data.action === 'close' ? 'closed' : 'open'; item.version++; }
            result = { id: item.id, deleted: data.action === 'delete' };
        } else if (item) {
            result = { ...item, assignment: assignment(item), fields: [{ name: 'message', label: 'Message', options: [] }],
                answers: { message: `Fictional private message ${item.id}` }, newsletter: null };
        } else {
            const scope = url.searchParams.get('scope') || 'all', status = url.searchParams.get('state') || 'all';
            const visible = rows.filter(row => !f.removed.has(row.id) && (status === 'all' || row.status === status)
                && (scope === 'all' || (scope === 'mine' ? assignment(row).assignee_id === actor : !assignment(row).assignee_id))
                && (!url.searchParams.has('after') || row.id < url.searchParams.get('after')));
            result = { items: visible.slice(0, 20).map(row => ({ ...row, assignee_name: assignment(row).name })),
                next_after: visible.length > 20 ? visible[19].id : null, can_manage: !readonly };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } }).catch(() => {});
    });
    await page.goto('/fixture/website-manager/?mode=form-inbox');
    await expect(page.getByRole('button', { name: 'Read inquiry', exact: true }).first()).toBeVisible();
    return f;
}
const drawer = page => page.locator('#site-review');
const mode = page => drawer(page).getByLabel('Assignment action', { exact: true });
async function open(page) {
    await page.getByRole('button', { name: 'Read inquiry', exact: true }).first().click();
    await expect(drawer(page).getByRole('region', { name: 'Inquiry assignment', exact: true })).toBeVisible();
}

test('staff claim, delegate, close, reopen and clear the same inquiry with explicit saves and safe labels', async ({ page }) => {
    const f = await fixture(page);
    await open(page);
    await expect(drawer(page).getByRole('button', { name: 'Save assignment', exact: true })).toBeDisabled();
    await mode(page).selectOption('claim');
    expect(f.calls.filter(call => call.path.endsWith('/assignment/'))).toHaveLength(0);
    await drawer(page).getByRole('button', { name: 'Save assignment', exact: true }).click();
    await expect(drawer(page)).toContainText('Assigned to: Fictional Operator');
    await expect(drawer(page).getByText('Inquiry assignment saved.', { exact: true })).toBeFocused();
    await mode(page).selectOption('colleague');
    await drawer(page).getByLabel('Search eligible colleagues', { exact: true }).fill('Fictional');
    await drawer(page).getByRole('button', { name: 'Search colleagues', exact: true }).click();
    await drawer(page).getByRole('button', { name: 'Select colleague · Fictional <img> Long Colleague', exact: true }).click();
    await expect(drawer(page).getByRole('button', { name: 'Save assignment', exact: true })).toBeFocused();
    await drawer(page).getByRole('button', { name: 'Save assignment', exact: true }).press('Enter');
    await expect(drawer(page)).toContainText('Assigned to: Fictional <img> Long Colleague');
    await expect(drawer(page).locator('img')).toHaveCount(0);
    await drawer(page).getByRole('button', { name: 'Mark closed', exact: true }).click();
    await expect(drawer(page).getByLabel('Assignment action', { exact: true })).toHaveCount(0);
    await expect(drawer(page)).toContainText('Fictional <img> Long Colleague');
    await drawer(page).getByRole('button', { name: 'Reopen inquiry', exact: true }).click();
    await mode(page).selectOption('clear');
    await drawer(page).getByRole('button', { name: 'Save assignment', exact: true }).click();
    await expect(drawer(page)).toContainText('Assigned to: Unassigned');
    expect(f.calls.filter(call => call.path.endsWith('/assignment/')).map(call => [call.data.version, call.data.inquiry_version, call.data.assignee_id])).toEqual([
        [0, 0, actor], [1, 0, colleague], [2, 2, null],
    ]);
    expect(f.calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('stale saves preserve the colleague choice through explicit reload and refresh the expected revision', async ({ page }) => {
    const f = await fixture(page); await open(page);
    await mode(page).selectOption('colleague');
    await drawer(page).getByLabel('Search eligible colleagues', { exact: true }).fill('Fictional');
    await drawer(page).getByRole('button', { name: 'Search colleagues', exact: true }).click();
    await drawer(page).getByRole('button', { name: /Select colleague ·/ }).click();
    f.stale = true;
    await drawer(page).getByRole('button', { name: 'Save assignment', exact: true }).click();
    await expect(drawer(page)).toContainText('Your selection is kept.');
    await expect(mode(page)).toHaveValue('colleague');
    await drawer(page).getByRole('button', { name: 'Reload inquiry', exact: true }).click();
    await expect(drawer(page)).toContainText('Selected colleague: Fictional <img> Long Colleague');
    await drawer(page).getByRole('button', { name: 'Save assignment', exact: true }).click();
    await expect(drawer(page)).toContainText('Inquiry assignment saved.');
    expect(f.calls.filter(call => call.path.endsWith('/assignment/')).map(call => call.data.version)).toEqual([0, 1]);
    expect(f.errors).toEqual([]);
});

test('Back to inbox preserves status, work filter, page and focus before My inquiries narrows the list', async ({ page }) => {
    const f = await fixture(page, { many: true });
    await page.getByLabel('Inquiry status', { exact: true }).selectOption('open');
    await page.getByLabel('Inquiry work', { exact: true }).selectOption('unassigned');
    await page.getByRole('button', { name: 'Older inquiries', exact: true }).click();
    await open(page);
    await mode(page).selectOption('claim');
    await drawer(page).getByRole('button', { name: 'Save assignment', exact: true }).click();
    await expect(drawer(page)).toContainText('Inquiry assignment saved.');
    await drawer(page).getByRole('button', { name: 'Back to inbox', exact: true }).click();
    await expect(drawer(page)).not.toHaveClass(/\bshow\b/);
    await expect(page.getByLabel('Inquiry work', { exact: true })).toHaveValue('unassigned');
    await expect(page.getByLabel('Inquiry status', { exact: true })).toHaveValue('open');
    await page.getByRole('button', { name: 'Previous inquiries', exact: true }).click();
    await page.getByLabel('Inquiry work', { exact: true }).selectOption('mine');
    await expect(page.getByRole('button', { name: 'Read inquiry', exact: true })).toHaveCount(1);
    await expect(page.locator('#site-manager')).toContainText('Fictional inquiry 80');
    expect(f.errors).toEqual([]);
});

test('changing assignment mode discards a late colleague search and preserves keyboard and narrow-screen access', async ({ page }) => {
    const f = await fixture(page); await open(page);
    await mode(page).selectOption('colleague');
    await drawer(page).getByLabel('Search eligible colleagues', { exact: true }).fill('Fictional');
    f.directoryDelay = true;
    await drawer(page).getByRole('button', { name: 'Search colleagues', exact: true }).click();
    await expect.poll(() => f.directoryArrived).toBe(true);
    await mode(page).selectOption('claim'); f.directoryRelease();
    await expect(drawer(page).getByRole('button', { name: /Select colleague ·/ })).toHaveCount(0);
    await page.setViewportSize({ width: 320, height: 900 });
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await drawer(page).getByRole('button', { name: 'Save assignment', exact: true }).scrollIntoViewIfNeeded();
    const scan = await new AxeBuilder({ page }).include('#site-review').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    expect(f.errors).toEqual([]);
});

test('Back remains usable during a pending assignment and its late response cannot replace another inquiry', async ({ page }) => {
    const f = await fixture(page, { many: true }); await open(page);
    await mode(page).selectOption('claim'); f.saveDelay = true;
    await drawer(page).getByRole('button', { name: 'Save assignment', exact: true }).click();
    await expect.poll(() => f.saveArrived).toBe(true);
    await drawer(page).getByRole('button', { name: 'Back to inbox', exact: true }).click();
    await page.getByRole('button', { name: 'Read inquiry', exact: true }).nth(1).click();
    await expect(drawer(page)).toContainText('Fictional inquiry 99');
    f.saveRelease();
    await page.waitForTimeout(70);
    await expect(drawer(page)).toContainText('Fictional inquiry 99');
    await expect(drawer(page)).not.toContainText('Inquiry assignment saved.');
    expect(f.errors).toEqual([]);
});

test('access loss clears private cards and assignment drafts while read-only colleagues see no editor', async ({ page }) => {
    const f = await fixture(page); await open(page);
    await mode(page).selectOption('colleague'); f.denied = true;
    await drawer(page).getByLabel('Search eligible colleagues', { exact: true }).fill('Fictional');
    await drawer(page).getByRole('button', { name: 'Search colleagues', exact: true }).click();
    await expect(page.locator('#site-review-body')).toBeEmpty();
    await expect(page.locator('#site-manager')).toBeEmpty();
    expect(f.errors).toEqual([]);
});

test('read-only users can inspect current routing without mutation controls', async ({ page }) => {
    const f = await fixture(page, { readonly: true }); await open(page);
    await expect(drawer(page)).toContainText('Assigned to: Unassigned');
    await expect(mode(page)).toHaveCount(0);
    await expect(drawer(page).getByRole('button', { name: 'Delete inquiry', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});


test('colleague pages preserve a deliberate selection and reflow long names without horizontal overflow', async ({ page }) => {
    const f = await fixture(page, { pagedDirectory: true }); await open(page);
    await mode(page).selectOption('colleague');
    await drawer(page).getByLabel('Search eligible colleagues', { exact: true }).fill('Fictional');
    await drawer(page).getByRole('button', { name: 'Search colleagues', exact: true }).click();
    await expect(drawer(page).getByRole('button', { name: /Select colleague ·/ })).toHaveCount(20);
    await page.setViewportSize({ width: 320, height: 900 });
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await drawer(page).getByRole('button', { name: 'More colleagues', exact: true }).scrollIntoViewIfNeeded();
    const scan = await new AxeBuilder({ page }).include('#site-review').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await drawer(page).getByRole('button', { name: 'More colleagues', exact: true }).click();
    await drawer(page).getByRole('button', { name: 'Select colleague · Fictional Later colleague', exact: true }).click();
    await drawer(page).getByRole('button', { name: 'Previous colleagues', exact: true }).click();
    await expect(drawer(page).getByRole('button', { name: /Select colleague ·/ })).toHaveCount(20);
    await expect(drawer(page)).toContainText('Selected colleague: Fictional Later colleague');
    await drawer(page).getByRole('button', { name: 'Save assignment', exact: true }).click();
    await expect(drawer(page)).toContainText('Inquiry assignment saved.');
    expect(f.calls.find(call => call.path.endsWith('/assignment/')).data.assignee_id).toBe(id(500));
    expect(f.errors).toEqual([]);
});
