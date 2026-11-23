const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { workspaceTool } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');

async function fixture(page, delay = null) {
    const calls = [], errors = [];
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    const schools = [
        { id: id(10), code: 'ARTS', title: 'College of Arts', active: true, revision: 'fictional-school' },
        { id: id(11), code: 'GRAD', title: 'Graduate Studies', active: true, revision: 'fictional-school' },
    ];
    const rows = {
        department: [
            { id: id(2), code: 'SCI', title: 'Science <script>fictional</script>', active: 1, school: schools[0], revision: 'fictional-department' },
            { id: id(3), code: 'HIST', title: 'History', active: true, school: null, revision: 'fictional-department' },
            { id: id(4), code: 'OLD', title: 'Retired department', active: '0', school: null, revision: 'fictional-department' },
        ],
        school: schools,
    };
    const choices = { department: null, academic_level: null, course_level: null };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname, raw = request.postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, method: request.method() });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/courses/') result.items = [{ id: id(1), code: 'HIST.101', title: 'Fictional History', active: true, credits_milli: 3000 }];
        else if (path.startsWith('/sis/catalog/classifications/')) {
            const kind = path.split('/')[4];
            if (path.endsWith('/page/')) {
                if (delay === 'library') await pending;
                const items = rows[kind] || [];
                if (data.search) result.items = items.filter(row => `${row.code} ${row.title}`.toLowerCase().includes(data.search.toLowerCase()));
                else if (kind === 'department') result = {
                    items: data.after ? [items[1]] : [items[0], items[2]], next_after: data.after ? null : id(50),
                };
                else result.items = items;
            } else if (path.endsWith('/save/')) {
                const row = rows[kind].find(row => row.id === data.id);
                Object.assign(row, data, { school: schools.find(school => school.id === data.school_id) || null });
                if (delay === 'reference_save') await pending;
                result = row;
            }
        } else if (path === `/sis/courses/${id(1)}/classifications/`) {
            if (data) {
                for (const kind of Object.keys(choices)) {
                    const row = (rows[kind] || []).find(row => row.id === data[`${kind}_id`]);
                    choices[kind] = row ? { id: row.id, code: row.code, title: row.title } : null;
                }
                if (delay === 'assignment_save') await pending;
                result = { status: 'saved' };
            } else {
                if (delay === 'assignment') await pending;
                result = { course: { code: 'HIST.101', title: 'Fictional History' }, classifications: choices, revision: 'fictional-choices' };
            }
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    return { calls, errors, release, rows, choices };
}

async function assignment(page) {
    await page.getByRole('button', { name: 'Fictional History', exact: true }).click();
    await page.getByRole('region', { name: 'Course content', exact: true })
        .getByRole('button', { name: 'Departments & levels', exact: true }).click();
}

async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, nodes: v.nodes.map(n => ({ target: n.target, summary: n.failureSummary })) }))).toEqual([]);
    const reflow = await page.evaluate(() => ({
        width: document.documentElement.scrollWidth, viewport: innerWidth,
        overflow: [...document.querySelectorAll('body *')].filter(el => {
            const r = el.getBoundingClientRect();
            return r.width && r.right > innerWidth + 1 && getComputedStyle(el).visibility !== 'hidden';
        }).slice(0, 8).map(el => `${el.tagName}.${el.className} right=${el.getBoundingClientRect().right}`),
    }));
    expect(reflow.width, JSON.stringify(reflow)).toBeLessThanOrEqual(reflow.viewport);
}

test('classification category menus retain keyboard selection and stay above the sidebar at every width', async ({ page }) => {
    const f = await fixture(page);
    await expect(page.getByRole('button', { name: 'Fictional History', exact: true }).locator('.bi')).toHaveCount(0);
    await workspaceTool(page, 'Departments & levels');
    for (const width of [1440, 1024, 768, 375, 320]) {
        await page.setViewportSize({ width, height: 900 });
        const toggle = page.getByRole('button', { name: 'Change category', exact: true });
        await toggle.focus(); await page.keyboard.press('ArrowDown');
        const menu = page.locator('#drawer-body .dropdown-menu.show');
        await expect(menu.getByRole('button')).toHaveCount(4);
        await expect(menu.getByRole('button', { name: 'Departments', exact: true })).toBeFocused();
        await expect(menu.getByRole('button', { name: 'Departments', exact: true })).toHaveAttribute('aria-pressed', 'true');
        expect(await menu.evaluate(el => {
            const r = el.getBoundingClientRect();
            return r.left >= 0 && r.right <= innerWidth + 1 && r.top >= 0 && r.bottom <= innerHeight + 1 &&
                el.contains(document.elementFromPoint(r.left + 10, r.top + 10));
        })).toBe(true);
        await page.keyboard.press('Escape'); await expect(toggle).toBeFocused();
    }
    await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowDown'); await page.keyboard.press('Enter');
    await expect(page.getByRole('heading', { name: 'Academic levels', exact: true })).toBeVisible();
    await expect(page.locator('#drawer-body')).toContainText('No matching classifications.');
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test('clearing classification search resets the cursor and keeps the current library', async ({ page }) => {
    const f = await fixture(page);
    await workspaceTool(page, 'Departments & levels');
    await page.getByRole('button', { name: 'More classifications', exact: true }).click();
    await expect(page.getByRole('article', { name: 'Department HIST', exact: true })).toBeVisible();
    await page.getByLabel('Find departments', { exact: true }).fill('SCI');
    await page.getByRole('button', { name: 'Search classifications', exact: true }).click();
    await expect(page.getByRole('article', { name: 'Department SCI', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Clear classification search', exact: true }).click();
    await expect(page.getByLabel('Find departments', { exact: true })).toHaveValue('');
    await expect(page.getByRole('article', { name: 'Department OLD', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Previous classifications', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Clear classification search', exact: true })).toBeDisabled();
    expect(f.calls.filter(call => call.path.endsWith('/department/page/')).at(-1).data).toEqual({ search: '' });
    expect(f.errors).toEqual([]);
});

test('department school choice preserves the editor draft and its return restores the library page', async ({ page }) => {
    const f = await fixture(page);
    await workspaceTool(page, 'Departments & levels');
    await page.getByRole('button', { name: 'More classifications', exact: true }).click();
    await page.getByRole('button', { name: 'Edit HIST', exact: true }).click();
    await page.getByLabel('Department name', { exact: false }).fill('Unsaved history draft');
    await page.getByRole('button', { name: 'Choose department school/college', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Change category', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Choose GRAD', exact: true }).click();
    await expect(page.getByLabel('Department name', { exact: false })).toHaveValue('Unsaved history draft');
    await expect(page.getByRole('region', { name: 'Department school/college', exact: true })).toContainText('GRAD — Graduate Studies');
    await page.getByRole('button', { name: 'Back to departments', exact: true }).click();
    await expect(page.getByRole('article', { name: 'Department HIST', exact: true })).toContainText('History');
    await expect(page.getByRole('button', { name: 'Previous classifications', exact: true })).toBeVisible();
    expect(f.calls.some(call => call.path.endsWith('/save/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('chooser cards distinguish retired definitions and retain the course draft without saving', async ({ page }) => {
    const f = await fixture(page);
    await assignment(page);
    await page.getByRole('button', { name: 'Choose department', exact: true }).click();
    const retired = page.getByRole('article', { name: 'Department OLD', exact: true });
    await expect(retired).toContainText('Retired');
    await expect(retired).toContainText('Unavailable for new assignments.');
    await expect(retired.getByRole('button', { name: 'Choose OLD', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Change category', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Choose SCI', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Department', exact: true })).toContainText('SCI — Science');
    expect(f.calls.filter(call => call.path === `/sis/courses/${id(1)}/classifications/` && call.method === 'POST')).toHaveLength(0);
    expect(f.errors).toEqual([]);
});

test('classification cards and search controls reflow with escaped titles and school context', async ({ page }) => {
    const f = await fixture(page);
    await workspaceTool(page, 'Departments & levels');
    const card = page.getByRole('article', { name: 'Department SCI', exact: true });
    await expect(card).toContainText('Science <script>fictional</script>');
    await expect(card).toContainText('ARTS — College of Arts');
    await expect(page.locator('#drawer-body script')).toHaveCount(0);
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-classification-library-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    await card.screenshot({ path: '/tmp/etsis-classification-card-phone.png' });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await accessible(page);
    await page.getByRole('button', { name: 'Change category', exact: true }).focus();
    await page.keyboard.press('ArrowDown');
    const menu = page.locator('#drawer-body .dropdown-menu.show');
    await expect(menu).toBeVisible();
    expect(await menu.evaluate(el => {
        const r = el.getBoundingClientRect();
        return r.left >= 0 && r.right <= innerWidth + 1;
    })).toBe(true);
    await page.keyboard.press('Escape');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

for (const delay of ['library', 'reference_save', 'assignment', 'assignment_save']) {
    test(`a delayed classification ${delay} result cannot reopen a closed drawer`, async ({ page }) => {
        const f = await fixture(page, delay);
        if (delay === 'assignment' || delay === 'assignment_save') {
            await assignment(page);
            if (delay === 'assignment_save') {
                await page.getByRole('button', { name: 'Choose department', exact: true }).click();
                await page.getByRole('button', { name: 'Choose SCI', exact: true }).click();
                await page.getByRole('button', { name: 'Save draft classifications', exact: true }).click();
            }
        } else {
            await workspaceTool(page, 'Departments & levels');
            if (delay === 'reference_save') {
                await page.getByRole('button', { name: 'Edit SCI', exact: true }).click();
                await page.getByLabel('Department name', { exact: false }).fill('Accepted fictional name');
                await page.getByRole('button', { name: 'Save classification', exact: true }).click();
            }
        }
        const isSave = delay.endsWith('_save');
        const target = delay === 'library' ? '/sis/catalog/classifications/department/page/'
            : delay === 'reference_save' ? '/sis/catalog/classifications/department/save/'
            : `/sis/courses/${id(1)}/classifications/`;
        await expect.poll(() => f.calls.some(call => call.path === target && (isSave ? call.method === 'POST' : true))).toBe(true);
        await page.getByRole('button', { name: 'Close record', exact: true }).click();
        await expect(page.locator('#drawer-body')).toBeEmpty();
        const finished = page.waitForEvent('requestfinished', { predicate: request => new URL(request.url()).pathname === target });
        f.release(); await finished;
        await page.waitForLoadState('networkidle');
        await expect(page.locator('#drawer-body')).toBeEmpty();
        if (delay === 'reference_save') expect(f.rows.department[0].title).toBe('Accepted fictional name');
        if (delay === 'assignment_save') expect(f.choices.department.code).toBe('SCI');
        expect(f.errors).toEqual([]);
    });
}
