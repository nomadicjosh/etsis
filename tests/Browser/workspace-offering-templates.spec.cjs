const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
const course = id(1), term = id(2), template = id(3), freshSection = id(4);
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}
async function fixture(page, interrupted = false) {
    const calls = [], errors = [], receipts = new Map(), changes = []; let serial = 20, version = 0, active = true, name = 'Fictional fall <b>defaults</b>';
    let defaults = [{ course_id: course, code: 'MATH.101.A', capacity: 30, faculty_user_id: null, fee_minor: 12500, currency: 'USD', delivery_mode: 'hybrid', web_registration: true }];
    const detail = rows => rows.map(row => ({ ...row, course_code: 'MATH.101', course_title: 'Algebra', course_active: true, faculty_name: null, warnings: [] }));
    const value = () => ({ id: template, name, version, active, section_count: defaults.length, sections: detail(defaults) });
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {}, status = 200;
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/operation-id/') result = { id: id(serial++) };
        else if (path.startsWith('/sis/ui/data/')) {
            const dataset = path.split('/')[4]; result = { items: { courses: [{ id: course, code: 'MATH.101', title: 'Algebra', credits_milli: 3000, active: 1 }], terms: [{ id: term, code: '27/FA' }] }[dataset] || [], next_after: null };
        } else if (path === '/sis/catalog/offering-templates/page/') result = { items: data.active === null || data.active === active ? [value()] : [], next_after: null };
        else if (path === '/sis/catalog/offering-templates/review/') result = { id: data.template_id || template, operation_id: id(90), version: data.version, name: data.name, active, sections: detail(data.sections), revision: 'a'.repeat(64) };
        else if (path === `/sis/catalog/offering-templates/${template}/`) result = value();
        else if (path.endsWith('/prepare/')) result = { template_id: template, template_name: name, template_version: version, term: { id: term, code: '27/FA' }, sections: defaults.map(row => ({ ...row, id: freshSection, term_id: term })), defaults: detail(defaults) };
        else if (path.endsWith('/save/')) {
            if (receipts.has(data.operation_id)) result = receipts.get(data.operation_id);
            else {
                name = data.name; defaults = data.sections; version = data.version + 1;
                result = { template_id: template, operation_id: data.operation_id, version, name, active }; receipts.set(data.operation_id, result);
                changes.push({ id: data.operation_id, version, name, section_count: defaults.length, action: data.version < 0 ? 'created' : 'revised', active, note: '', created_at: '2026-11-08T10:00:00Z' });
                if (interrupted) { status = 500; result = { error: 'Fictional interrupted confirmation. Check the saved template.' }; }
            }
        } else if (path.endsWith('/status/')) { active = data.active; version++; result = { template_id: template, operation_id: data.operation_id, version, name, active }; receipts.set(data.operation_id, result); changes.push({ ...result, id: data.operation_id, action: active ? 'restored' : 'retired', note: data.note, section_count: defaults.length, created_at: '2026-11-08T11:00:00Z' }); }
        else if (path.includes('/receipts/')) result = receipts.get(path.split('/').at(-2)) || {};
        else if (path.endsWith('/history/')) result = { items: changes, next_after_version: null };
        else if (path === '/sis/catalog/offerings/review/') result = { term: { id: term, code: '27/FA', starts_on: '2027-08-01', ends_on: '2027-12-31', add_drop_ends_on: '2027-08-15' }, sections: detail(data.sections), revision: 'b'.repeat(64), operation_id: id(91) };
        await route.fulfill({ status, json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/'); await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole('button', { name: 'Offering templates', exact: true }).click();
    return { calls, errors };
}
async function openTemplate(page) {
    await page.getByRole('button', { name: 'Fictional fall <b>defaults</b>', exact: true }).click();
}
async function loadPlan(page) {
    await openTemplate(page); await page.getByRole('button', { name: 'Use for a new term', exact: true }).click();
    await page.getByRole('combobox', { name: 'Offering term', exact: true }).selectOption(term);
    await page.getByRole('button', { name: 'Load editable defaults', exact: true }).click();
}

test('staff load defaults into a fresh editable offering plan and still review before saving drafts', async ({ page }) => {
    const f = await fixture(page); await loadPlan(page);
    await expect(page.locator('#drawer-body')).toContainText('MATH.101.A'); await expect(page.locator('#drawer-body')).toContainText('$125.00');
    expect(f.calls.some(call => call.path.endsWith('/save/'))).toBe(false);
    await page.getByRole('button', { name: 'Edit MATH.101.A', exact: true }).click();
    await expect(page.getByLabel('Section fee', { exact: false })).toHaveValue('125');
    await page.getByLabel('Section code').fill('MATH.101.27FA.A');
    await page.getByLabel('Seats', { exact: false }).fill('40');
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.getByRole('button', { name: 'Keep section in plan', exact: true }).click();
    await page.getByRole('button', { name: 'Review offering plan', exact: true }).click();
    expect(f.calls.find(call => call.path === '/sis/catalog/offerings/review/')).toMatchObject({ csrf: 'fictional-test-token', data: { term_id: term, sections: [{ id: freshSection, course_id: course, code: 'MATH.101.27FA.A', capacity: 40, fee_minor: 12500 }] } });
    expect(f.calls.some(call => call.path.includes('/publish/'))).toBe(false); expect(f.errors).toEqual([]);
});

test('template revisions use the planner without requiring a term and preserve context through edits', async ({ page }) => {
    const f = await fixture(page); await openTemplate(page);
    await page.getByRole('button', { name: 'Revise defaults in offering planner', exact: true }).click();
    await expect(page.locator('#drawer-title')).toHaveText('Template section defaults');
    await expect(page.getByRole('button', { name: 'Review offering plan', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Edit MATH.101.A', exact: true }).click();
    await page.getByLabel('Seats', { exact: false }).fill('45');
    await page.getByRole('button', { name: 'Keep section in plan', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review template changes', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Review template changes', exact: true }).click();
    await page.getByRole('button', { name: 'Review template', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm template save', exact: true }).click();
    const sent = f.calls.find(call => call.path.endsWith('/offering-templates/review/')).data;
    expect(sent).toMatchObject({ template_id: template, version: 0, sections: [{ capacity: 45 }] });
    expect(sent.sections[0]).not.toHaveProperty('id'); expect(sent.sections[0]).not.toHaveProperty('term_id');
    expect(f.calls.some(call => call.path.endsWith('/prepare/') || call.path === '/sis/catalog/offerings/review/')).toBe(false);
    await expect(page.locator('#drawer-body')).toContainText('45'); expect(f.errors).toEqual([]);
});

test('a new reusable template strips source identities and recovers an interrupted save without another write', async ({ page }) => {
    const f = await fixture(page, true); await loadPlan(page);
    await page.getByRole('button', { name: 'Save as reusable template', exact: true }).click();
    await page.getByLabel('Template name').fill('Fictional next term defaults');
    await page.getByRole('button', { name: 'Review template', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('student catalog years');
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.getByRole('button', { name: 'Confirm template save', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('interrupted');
    await expect(page.getByRole('button', { name: 'Change template name', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Check saved template', exact: true }).click();
    await expect(page.locator('#drawer-title')).toHaveText('Fictional next term defaults');
    const writes = f.calls.filter(call => call.path.endsWith('/save/')); expect(writes).toHaveLength(1);
    expect(writes[0].data.sections[0]).not.toHaveProperty('id'); expect(writes[0].data.sections[0]).not.toHaveProperty('term_id');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0); expect(f.errors).toEqual([]);
});

test('retirement and restoration require explicit reasons and display escaped change history', async ({ page }) => {
    const f = await fixture(page); await openTemplate(page);
    await page.getByRole('button', { name: 'Retire template', exact: true }).click();
    await page.getByLabel('Reason for this change').fill('Fictional <script>retirement</script>');
    await page.getByRole('button', { name: 'Confirm status change', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Use for a new term', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Restore template', exact: true }).click();
    await page.getByLabel('Reason for this change').fill('Reviewed defaults for reuse.');
    await page.getByRole('button', { name: 'Confirm status change', exact: true }).click();
    await page.getByRole('button', { name: 'Change history', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional <script>retirement</script>');
    await expect(page.locator('#drawer-body script')).toHaveCount(0); await accessible(page);
    expect(f.calls.filter(call => call.path.endsWith('/status/')).map(call => call.data)).toMatchObject([{ active: false, version: 0 }, { active: true, version: 1 }]);
    expect(f.errors).toEqual([]);
});

test('current access denial clears the active template details without retrying a preparation', async ({ page }) => {
    const f = await fixture(page); await openTemplate(page);
    await page.getByRole('button', { name: 'Use for a new term', exact: true }).click();
    await page.getByRole('combobox', { name: 'Offering term', exact: true }).selectOption(term);
    await page.route('**/sis/catalog/offering-templates/*/prepare/', route => route.fulfill({ status: 403, json: { error: 'Access denied.' } }));
    await page.getByRole('button', { name: 'Load editable defaults', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Template configuration is no longer available');
    await expect(page.locator('#drawer-body')).not.toContainText('Fictional fall');
    expect(f.calls.some(call => call.path === '/sis/catalog/offerings/review/')).toBe(false); expect(f.errors).toEqual([]);
});
