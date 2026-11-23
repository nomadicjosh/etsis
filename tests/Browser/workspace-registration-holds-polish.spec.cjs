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

async function registration(page, delayed = false) {
    const state = { calls: [], errors: [] };
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    state.release = release;
    const students = [
        { id: id(2), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL-1', active: true },
        { id: id(3), given_name: 'Jamie', family_name: 'Example', institutional_id: 'S-FICTIONAL-2', active: true },
    ];
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        const raw = request.postData(), data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        state.calls.push({ path, data });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result.items = path.includes('/registrationstudents/') ? students
            : [{ id: id(1), code: 'MATH.101.A', course_title: 'College Algebra', term_code: '26/FA', capacity: 25, active: true }];
        else if (path === '/sis/registration-batches/review/') {
            if (delayed) await pending;
            result = { operation_id: id(4), revision: 'fictional-review',
                section: { code: 'MATH.101.A', course_code: 'MATH.101', course_title: 'College Algebra',
                    credits_milli: 3000, seats_remaining: 4, fee_minor: 1250, currency: 'USD', active: true,
                    prerequisite_courses: [], corequisite_courses: [] },
                students: data.student_ids.map(selected => ({ ...students.find(student => student.id === selected),
                    enrollment_id: null, enrollment_status: null, prerequisites: { status: 'not_required' },
                    corequisites: { status: 'not_required' }, issues: [], readiness: [],
                    schedule: { status: 'not_rechecked', blocked: false, conflicts: [], conflict_count: 0 },
                    charges: { total_minor: 1250, currency: 'USD', problems: [],
                        items: [{ name: 'Section fee', minor: 1250, currency: 'USD', status: 'new' }] },
                })),
            };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/registration-fixture/');
    await page.locator('.workspace-nav-link[data-workspace="registration"]').click();
    await page.getByRole('button', { name: 'MATH.101.A', exact: true }).click();
    state.add = async value => {
        await page.getByRole('combobox', { name: /^Find a student/u }).selectOption(value);
        await page.getByRole('button', { name: 'Add student to group', exact: true }).click();
    };
    await state.add(id(2));
    await state.add(id(3));
    return state;
}

test('student removal preserves keyboard focus and registration confirmation precedes the reviewed roster', async ({ page }) => {
    const state = await registration(page);
    const selected = page.getByRole('region', { name: 'Selected students', exact: true });
    await page.screenshot({ path: 'test-results/workspace-polished-registration-selection.png' });
    const remove = selected.getByRole('button', { name: /^Remove .*Alex Example/u });
    await remove.focus();
    await page.keyboard.press('Enter');
    await expect(selected.getByRole('button', { name: /^Remove .*Jamie Example/u })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('button', { name: 'Add student to group', exact: true })).toBeFocused();
    await expect(selected).toContainText('0 selected students');
    await state.add(id(3));
    await state.add(id(2));
    await page.getByRole('button', { name: 'Review selected group', exact: true }).click();
    const confirm = page.getByRole('group', { name: 'Registration confirmation', exact: true });
    const roster = page.getByRole('region', { name: 'Reviewed registration group', exact: true });
    await expect(roster).toContainText('Alex Example');
    expect((await confirm.boundingBox()).y).toBeLessThan((await roster.boundingBox()).y);
    await expect(page.locator('#drawer-body')).not.toContainText(/\b(null|undefined)\b/u);
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-registration-review.png' });
    await reflow(page);
    expect(state.calls.find(call => call.path.endsWith('/review/')).data.student_ids).toEqual([id(3), id(2)]);
    expect(state.calls.some(call => call.path.endsWith('/commit/'))).toBe(false);
    expect(state.errors).toEqual([]);
});

test('closing a pending registration review does not reopen confirmation', async ({ page }) => {
    const state = await registration(page, true);
    await page.getByRole('button', { name: 'Review selected group', exact: true }).click();
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await expect(page.locator('#drawer-body')).toBeEmpty();
    const finished = page.waitForEvent('requestfinished', { predicate: request => new URL(request.url()).pathname === '/sis/registration-batches/review/' });
    state.release();
    await finished;
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#drawer-body')).toBeEmpty();
    expect(state.calls.some(call => call.path.endsWith('/commit/'))).toBe(false);
    expect(state.errors).toEqual([]);
});

async function hold(page, { limited = false, delayed = false, denied = false } = {}) {
    const state = { calls: [], errors: [] };
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    state.release = release;
    const record = { id: id(10), person_id: id(11), code: 'ADVISING.HOLD', severity: 99,
        person_name: 'Alex Example', institutional_id: 'S-FICTIONAL', state: 'active',
        starts_on: '2026-08-24', ends_on: null, blocks_staff: true, timezone: 'America/Los_Angeles',
        office_id: id(12), office_name: 'Registrar', today: '2026-11-16', revision: 'fictional-hold',
        follow_up: { assignee_name: 'Jamie Example', follow_up_on: '2026-11-20' },
    };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        state.calls.push(path);
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/restrictions/queue/') result.items = [record];
        else if (path === `/sis/restrictions/${id(10)}/`) {
            if (delayed) await pending;
            if (denied) return route.fulfill({ status: 403, json: { error: 'Access denied.' } });
            result = record;
        } else if (path.endsWith('/notes/')) result.items = [
            { id: id(13), actor_id: id(14), actor_name: 'Jamie Example', created_at: '2026-11-16T12:00:00Z',
                content: 'Fictional advising note. <img> This is a private test fixture.' },
        ];
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(limited ? '/admin/hold-office-fixture/' : '/admin/');
    await page.locator('.workspace-nav-link[data-workspace="holds"]').click();
    await expect(page.getByLabel('Find office holds', { exact: true })).toBeVisible();
    await accessible(page);
    await page.getByRole('button', { name: 'Review hold', exact: true }).click();
    return state;
}

test('hold menus keep daily tasks visible and disclose secondary work without changing the hold', async ({ page }) => {
    const state = await hold(page);
    const tasks = page.getByRole('group', { name: 'Hold tasks', exact: true });
    await expect(tasks.getByRole('button', { name: 'Add staff note', exact: true })).toBeVisible();
    await expect(tasks.getByRole('button', { name: 'Set or shorten end date', exact: true })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Internal staff notes', exact: true })).toContainText('Fictional advising note. <img>');
    const menu = tasks.getByRole('button', { name: 'More hold actions', exact: true });
    await menu.focus();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('button', { name: 'Review hold history', exact: true })).toBeFocused();
    await accessible(page);
    await page.keyboard.press('Escape');
    await expect(menu).toBeFocused();
    await expect(page.locator('#drawer-body img')).toHaveCount(0);
    await page.screenshot({ path: 'test-results/workspace-polished-hold.png' });
    await reflow(page);
    expect(state.calls.some(path => /\/(end|handoff|append)\//u.test(path))).toBe(false);
    expect(state.errors).toEqual([]);
});

test('office delegates receive no hidden handoff, audit or student-record actions', async ({ page }) => {
    const state = await hold(page, { limited: true });
    await expect(page.getByRole('button', { name: 'Review hold history', exact: true })).toBeVisible();
    for (const name of ['More hold actions', 'Hand off to another office', 'View restriction audit', 'Back to student']) {
        await expect(page.getByRole('button', { name, exact: true, includeHidden: true })).toHaveCount(0);
    }
    await reflow(page);
    expect(state.calls.some(path => /^\/sis\/(students|accounts|grades)\//u.test(path))).toBe(false);
    expect(state.errors).toEqual([]);
});

test('closing a pending hold read prevents late notes and task rendering', async ({ page }) => {
    const state = await hold(page, { delayed: true });
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await expect(page.locator('#drawer-body')).toBeEmpty();
    const finished = page.waitForEvent('requestfinished', { predicate: request => new URL(request.url()).pathname === `/sis/restrictions/${id(10)}/` });
    state.release();
    await finished;
    // The isolated fixture has no polling; settle the released read before checking that notes were skipped.
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#drawer-body')).toBeEmpty();
    expect(state.calls.some(path => path.endsWith('/notes/'))).toBe(false);
    expect(state.errors).toEqual([]);
});

test('denied hold reads offer no notes or mutation controls', async ({ page }) => {
    const state = await hold(page, { limited: true, denied: true });
    await expect(page.locator('#drawer-body')).toContainText('This task needs additional access.');
    await expect(page.getByRole('button', { name: 'Add staff note', exact: true, includeHidden: true })).toHaveCount(0);
    expect(state.calls.some(path => path.endsWith('/notes/'))).toBe(false);
    expect(state.errors).toEqual([]);
});
