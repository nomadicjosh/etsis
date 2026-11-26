'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
async function fixture(page, { stale = false, delay = null, preset = null, staleDefault = false } = {}) {
    const section = id(1), course = id(2), term = id(3), calls = [], errors = [];
    let choices = { instructional_method: null, campus: null, reporting_group: null }, revision = 'draft-0';
    const groups = [
        { id: id(10), kind: 'reporting_group', code: 'OLD', title: 'Retired reporting group', active: false, revision: 'ref-0' },
        { id: id(11), kind: 'reporting_group', code: 'FA-2026', title: 'Fictional fall <script>group</script>', active: true, revision: 'ref-1' },
        { id: id(12), kind: 'reporting_group', code: 'ALT-2026', title: 'Alternative reporting group', active: true, revision: 'ref-2' },
    ];
    const method = { id: id(20), kind: 'instructional_method', code: 'LEC', title: 'Lecture', active: true, revision: 'ref-method' };
    let defaultGroup = preset === null ? null : groups[preset], defaultRevision = 'term-default-0';
    const termRow = { id: term, code: '26/SHORT-A', name: 'Fictional short term', starts_on: '2026-08-01', ends_on: '2026-08-31', add_drop_ends_on: '2026-08-15' };
    const row = { id: section, code: 'ENG.101.A', course_id: course, term_id: term, course_code: 'ENG.101',
        course_title: 'Fictional Literature', term_code: '26/SHORT-A', capacity: 20, minimum_enrollment: 0,
        faculty_user_id: null, active: true, web_registration: true, fee_minor: 0, currency: 'USD', delivery_mode: 'online' };
    const definition = { course_id: course, course_version_id: id(30), term_id: term, term_code: row.term_code,
        term_starts_on: '2026-08-01', term_ends_on: '2026-08-31', instruction_starts_on: '2026-08-01', instruction_ends_on: '2026-08-31',
        code: row.code, capacity: 20, faculty_user_id: null, faculty_person_id: null, delivery_mode: 'online', fee_minor: 0, currency: 'USD' };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result.items = path.includes('/sections/') ? [row] : path.includes('/terms/') ? [termRow] : [];
        else if (path.endsWith('/reporting-default/')) {
            if (data) {
                if (staleDefault) return route.fulfill({ status: 409, json: { error: 'Reload the term reporting-group default before saving.' } });
                defaultGroup = groups.find(group => group.id === data.reporting_group_id) || null;
                defaultRevision = 'term-default-1';
            }
            result = { term: termRow, reporting_group: defaultGroup, revision: defaultRevision };
        }
        else if (path.startsWith('/sis/catalog/classifications/')) {
            const kind = path.split('/')[4];
            if (path.endsWith('/save/')) {
                const saved = { ...data, id: data.id || id(13), kind, revision: 'ref-saved' };
                groups.push(saved); result = saved;
            } else if (kind === 'reporting_group') {
                const matches = groups.filter(item => !data.search || `${item.code} ${item.title}`.toLowerCase().includes(data.search.toLowerCase()));
                result = data.search ? { items: matches, next_after: null }
                    : { items: data.after ? [groups[2]] : groups.slice(0, 2), next_after: data.after ? null : id(50) };
            } else result.items = kind === 'instructional_method' ? [method] : [];
        } else if (path.endsWith('/classifications/')) {
            if (data) {
                if (stale) return route.fulfill({ status: 409, json: { error: 'The section changed. Reopen its setup to review current choices.' } });
                const selected = key => [...groups, method].find(item => item.id === data[key + '_id']) || null;
                choices = Object.fromEntries(Object.keys(choices).map(key => [key, selected(key)]));
                revision = 'draft-1'; result = { status: 'saved' };
            } else {
                if (delay) await delay();
                result = { section: row, classifications: choices, revision };
            }
        } else if (path.endsWith('/publication/setup/')) result = { section: row, term: { code: row.term_code, starts_on: '2026-08-01', ends_on: '2026-08-31' },
            course_definition: { definition: { code: 'ENG.101', title: 'Fictional Literature' } }, classifications: choices,
            latest: null, publication_status: 'unpublished', selected_offering: null, faculty_label: 'Not assigned', has_student_records: false };
        else if (path.endsWith('/publication/page/')) result = { items: [], next_cursor: null };
        else if (path.endsWith('/publication/review/')) result = { definition: { ...definition, instruction_starts_on: data.instruction_starts_on, instruction_ends_on: data.instruction_ends_on },
            classifications: choices, expected_version: 0, reason: data.reason, review_token: 'fictional-publication-proof', faculty_label: 'Not assigned' };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole('link', { name: 'Sections', exact: true }).click();
    return { calls, errors, section, changePreset: n => { defaultGroup = groups[n]; } };
}
async function openSetup(page) {
    await page.getByRole('button', { name: 'ENG.101.A', exact: true }).click();
    await recordAction(page, 'Section classifications');
}

test('reporting groups use the shared library and preserve other section choices through paging and search', async ({ page }) => {
    const f = await fixture(page);
    await openSetup(page);
    await page.getByRole('button', { name: 'Choose instructional method', exact: true }).click();
    await page.getByRole('button', { name: 'Choose LEC', exact: true }).click();
    await page.getByRole('button', { name: 'Choose reporting group', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Choose OLD', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'More classifications', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Choose ALT-2026', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Previous classifications', exact: true }).click();
    await page.getByRole('searchbox').fill('FA-2026');
    await page.getByRole('button', { name: 'Search classifications', exact: true }).click();
    await page.getByRole('button', { name: 'Choose FA-2026', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('LEC — Lecture');
    await expect(page.locator('#drawer-body')).toContainText('FA-2026 — Fictional fall <script>group</script>');
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeDisabled();
    expect(f.calls.filter(call => call.path.endsWith('/classifications/') && call.data)).toHaveLength(0);
    const save = page.getByRole('button', { name: 'Save section setup', exact: true });
    await save.focus(); await page.keyboard.press('Enter');
    await expect(page.getByRole('status').filter({ hasText: 'Section setup saved.' })).toBeVisible();
    expect(f.calls.find(call => call.path.endsWith('/classifications/') && call.data).data).toEqual({
        instructional_method_id: id(20), campus_id: null, reporting_group_id: id(11), revision: 'draft-0' });
    await page.getByRole('button', { name: 'Review publication', exact: true }).click();
    await page.getByLabel('Reason for section publication').fill('Fictional reporting-group approval');
    await page.getByRole('button', { name: 'Review section publication', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional fall <script>group</script>');
    await expect(page.locator('#drawer-body')).toContainText('26/SHORT-A');
    expect(await page.locator('#drawer-body script').count()).toBe(0);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('new reporting definitions can be created inside a draft and explicitly cleared with accessible narrow controls', async ({ page }) => {
    const f = await fixture(page);
    await openSetup(page);
    await page.getByRole('button', { name: 'Choose reporting group', exact: true }).click();
    await page.getByRole('button', { name: 'Add reporting group', exact: true }).click();
    await page.getByRole('textbox', { name: 'Reporting group code', exact: true }).fill('FULL-FA');
    await page.getByRole('textbox', { name: 'Reporting group name', exact: true }).fill('Fictional combined fall reporting');
    await page.getByRole('button', { name: 'Save classification', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('FULL-FA — Fictional combined fall reporting');
    await page.screenshot({ path: '/tmp/etsis-reporting-section-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    await page.getByRole('button', { name: 'Clear reporting group', exact: true }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: '/tmp/etsis-reporting-section-phone.png' });
    await page.getByRole('button', { name: 'Clear reporting group', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Reporting group', exact: true })).toContainText('Not specified');
    expect(f.errors).toEqual([]);
});

test('a stale classification save preserves the proposed reporting group for deliberate review', async ({ page }) => {
    const f = await fixture(page, { stale: true });
    await openSetup(page);
    await page.getByRole('button', { name: 'Choose reporting group', exact: true }).click();
    await page.getByRole('button', { name: 'Choose FA-2026', exact: true }).click();
    const failed = page.waitForResponse(response => response.url().endsWith('/classifications/') && response.status() === 409);
    await page.getByRole('button', { name: 'Save section setup', exact: true }).click();
    await failed;
    await expect(page.locator('#drawer-body')).toContainText('FA-2026 — Fictional fall <script>group</script>');
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeDisabled();
    expect(f.calls.filter(call => call.path.endsWith('/classifications/') && call.data)).toHaveLength(1);
    expect(f.errors).toEqual([]);
});

test('closing a pending section classification read prevents a late reporting-group drawer from reopening', async ({ page }) => {
    let requested, release;
    const pending = new Promise(resolve => { requested = resolve; });
    const held = new Promise(resolve => { release = resolve; });
    const f = await fixture(page, { delay: async () => { requested(); await held; } });
    await openSetup(page); await pending;
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    const finished = page.waitForResponse(response => response.url().endsWith('/classifications/'));
    release(); await finished;
    await expect(page.getByRole('button', { name: 'Choose reporting group', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('term default setup preserves picker drafts, saves explicitly and returns to the term record', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('link', { name: 'Terms', exact: true }).click();
    await page.getByRole('button', { name: '26/SHORT-A', exact: true }).click();
    await page.getByRole('button', { name: 'Reporting-group default', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Save term default', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Choose reporting group', exact: true }).click();
    await page.getByRole('button', { name: 'Choose FA-2026', exact: true }).click();
    await page.getByRole('button', { name: 'Choose reporting group', exact: true }).click();
    await page.getByRole('button', { name: 'Back to term default', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('FA-2026 — Fictional fall <script>group</script>');
    expect(f.calls.filter(call => call.path.endsWith('/reporting-default/') && call.data)).toHaveLength(0);
    const save = page.getByRole('button', { name: 'Save term default', exact: true });
    await save.focus(); await page.keyboard.press('Enter');
    await expect(page.getByRole('status').filter({ hasText: 'Term default saved.' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Back to record', exact: true }).locator('.bi-arrow-left')).toHaveCount(1);
    expect(f.calls.find(call => call.path.endsWith('/reporting-default/') && call.data)).toMatchObject({
        data: { reporting_group_id: id(11), revision: 'term-default-0' }, csrf: 'fictional-test-token' });
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations.map(item => item.id)).toEqual([]);
    await page.screenshot({ path: '/tmp/etsis-term-reporting-default-phone.png' });
    await page.getByRole('button', { name: 'Clear term default', exact: true }).click();
    await page.getByRole('button', { name: 'Save term default', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('No term default');
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Registration window');
    expect(f.errors).toEqual([]);
});

test('copying rechecks the current preset and preserves other draft choices until explicit save', async ({ page }) => {
    const f = await fixture(page, { preset: 1 });
    await openSetup(page);
    await page.getByRole('button', { name: 'Choose instructional method', exact: true }).click();
    await page.getByRole('button', { name: 'Choose LEC', exact: true }).click();
    f.changePreset(2);
    await page.getByRole('button', { name: 'Copy term default', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Reporting group', exact: true })).toContainText('ALT-2026 — Alternative reporting group');
    await expect(page.locator('#drawer-body')).toContainText('LEC — Lecture');
    expect(f.calls.filter(call => call.path.endsWith('/classifications/') && call.data)).toHaveLength(0);
    await page.getByRole('button', { name: 'Save section setup', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Section setup saved.' })).toBeVisible();
    expect(f.calls.find(call => call.path.endsWith('/classifications/') && call.data).data).toMatchObject({
        instructional_method_id: id(20), reporting_group_id: id(12) });
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('a retired preset cannot be copied and a stale term edit keeps the proposed draft', async ({ page }) => {
    const f = await fixture(page, { preset: 0, staleDefault: true });
    await openSetup(page);
    await expect(page.getByRole('button', { name: 'Copy term default', exact: true })).toBeDisabled();
    await expect(page.locator('#drawer-body')).toContainText('This reporting group is retired.');
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.getByRole('link', { name: 'Terms', exact: true }).click();
    await page.getByRole('button', { name: '26/SHORT-A', exact: true }).click();
    await page.getByRole('button', { name: 'Reporting-group default', exact: true }).click();
    await page.getByRole('button', { name: 'Clear term default', exact: true }).click();
    await page.getByRole('button', { name: 'Save term default', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('No term default');
    await expect(page.getByRole('button', { name: 'Save term default', exact: true })).toBeEnabled();
    expect(f.errors).toEqual([]);
});
