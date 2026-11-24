const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function fixture(page, kind = 'courses', options = {}) {
    const calls = [], errors = [];
    const row = { id: id(1), code: 'MATH.101', title: 'Fictional Algebra', credits_milli: 3000, active: true };
    const section = { id: id(2), code: 'MATH.101.A', course_id: row.id, course_code: row.code,
        course_title: row.title, term_code: '26/FA', capacity: 20, active: true };
    let settings = { revision: 'a'.repeat(64), mode: kind === 'courses' ? 'fixed' : 'inherit',
        ceu_milli: kind === 'courses' ? 1250 : null,
        ...(kind === 'sections' ? { course_continuing_education: { ceu_milli: 1250 },
            course_version_id: options.unbound ? null : id(3) } : {}) };
    let reject = options.reject;
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data && path.endsWith('/ceus/')) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = {
            items: path.includes('/courses/') ? [row] : path.includes('/sections/') ? [section] : [], next_after: null,
        };
        else if (path.endsWith('/ceus/')) {
            if (!data && options.delayed) await pending;
            if (data && reject) {
                const status = reject; reject = null;
                await route.fulfill({ status, json: { error: 'Fictional credit policy changed. Reload before saving.' } });
                return;
            }
            if (data) settings = { ...settings, revision: 'b'.repeat(64), mode: data.mode, ceu_milli: data.ceu_milli };
            result = settings;
        } else if (path.endsWith('/publication/page/')) result = { items: [], next_cursor: null, through_version: 0 };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    if (kind === 'sections') await page.getByRole('link', { name: 'Sections', exact: true }).click();
    await page.getByRole('button', { name: kind === 'courses' ? row.title : section.code, exact: true }).click();
    await page.getByRole('button', { name: kind === 'courses' ? 'Course CEUs' : 'Offered CEUs', exact: true }).click();
    return { calls, errors, release };
}

async function accessible(page) {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}

for (const kind of ['courses', 'sections']) {
    test(`${kind} CEU declaration saves exact values with publication and record returns`, async ({ page }) => {
        const f = await fixture(page, kind);
        if (kind === 'sections') await expect(page.locator('#drawer-body')).toContainText('Course declaration: 1.25 CEUs');
        await page.getByLabel('CEU declaration', { exact: true }).selectOption('fixed');
        await page.getByLabel('Continuing education units (CEUs)', { exact: true }).fill('1.234');
        await page.setViewportSize({ width: 320, height: 812 });
        await accessible(page);
        await page.screenshot({ path: `/tmp/etsis-ceu-${kind}-mobile.png`, fullPage: true });
        const save = page.getByRole('button', { name: 'Save CEU draft', exact: true });
        await save.focus(); await page.keyboard.press('Enter');
        await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeVisible();
        expect(f.calls[0]).toMatchObject({ data: { mode: 'fixed', ceu_milli: 1234, revision: 'a'.repeat(64) },
            csrf: 'fictional-test-token' });
        await page.getByLabel('CEU declaration', { exact: true }).selectOption('none');
        await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeHidden();
        await expect(page.getByLabel('Continuing education units (CEUs)', { exact: true })).toBeDisabled();
        await save.click();
        await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeVisible();
        expect(f.calls[1].data).toEqual({ mode: 'none', ceu_milli: null, revision: 'b'.repeat(64) });
        await page.setViewportSize({ width: 1280, height: 900 });
        const before = await save.evaluate(el => parseFloat(getComputedStyle(el).fontSize));
        const root = await page.evaluate(() => parseFloat(getComputedStyle(document.documentElement).fontSize));
        await page.route('**/fixtures/enlarged-ceu.css', route => route.fulfill({ contentType: 'text/css',
            body: `html {font-size:${root * 2}px!important;}` }));
        await page.addStyleTag({ url: '/fixtures/enlarged-ceu.css' });
        await expect.poll(() => save.evaluate(el => parseFloat(getComputedStyle(el).fontSize)))
            .toBeGreaterThanOrEqual(before * 2);
        await accessible(page);
        if (kind === 'courses') {
            await page.getByRole('button', { name: 'Review publication', exact: true }).click();
            await expect(page.locator('#drawer-title')).toHaveText('Published course definitions');
        }
        await page.getByRole('button', { name: 'Back to record', exact: true }).click();
        await expect(page.locator('#drawer-title')).toHaveText(kind === 'courses' ? 'Fictional Algebra' : 'MATH.101.A');
        expect(f.errors).toEqual([]);
        expect(await page.evaluate(() => sessionStorage.length)).toBe(0);
    });
}

test('failed CEU saves preserve draft values and authority loss clears them', async ({ page }) => {
    const f = await fixture(page, 'courses', { reject: 409 });
    await page.getByLabel('Continuing education units (CEUs)', { exact: true }).fill('4.567');
    await page.getByRole('button', { name: 'Save CEU draft', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional credit policy changed.');
    await expect(page.getByLabel('Continuing education units (CEUs)', { exact: true })).toHaveValue('4.567');
    await page.getByRole('button', { name: 'Save CEU draft', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeVisible();
    await page.unroute('**/sis/**');
    await page.route('**/sis/**', route => route.fulfill({ status: 403, json: { error: 'Fictional access ended.' } }));
    await page.getByRole('button', { name: 'Save CEU draft', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('This task needs additional access.');
    await expect(page.getByLabel('Continuing education units (CEUs)', { exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('returning while CEUs load discards the detached response', async ({ page }) => {
    const f = await fixture(page, 'courses', { delayed: true });
    await page.getByRole('button', { name: 'Back to record', exact: true }).click(); f.release();
    await expect(page.locator('#drawer-title')).toHaveText('Fictional Algebra');
    await expect(page.getByLabel('CEU declaration', { exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('an unbound section identifies draft inheritance and permits an explicit zero', async ({ page }) => {
    const f = await fixture(page, 'sections', { unbound: true });
    await expect(page.locator('#drawer-body')).toContainText('No course publication is selected.');
    await expect(page.getByRole('option', { name: 'Use the course draft declaration' })).toHaveCount(1);
    await page.getByLabel('CEU declaration', { exact: true }).selectOption('fixed');
    await page.getByLabel('Continuing education units (CEUs)', { exact: true }).fill('0');
    await page.getByRole('button', { name: 'Save CEU draft', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeVisible();
    expect(f.calls[0].data).toMatchObject({ mode: 'fixed', ceu_milli: 0 });
});

test('public discovery distinguishes offered quantities, zero, no CEUs and earlier unknowns', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/portal/api/courses/**', route => route.fulfill({ json: { items:
        [undefined, { ceu_milli: null }, { ceu_milli: 0 }, { ceu_milli: 1234 }].map((value, index) => ({
            id: id(index + 1), course_code: `CE.${index}`, section_code: `CE.${index}.A`, title: `Fictional workshop ${index}`,
            term_code: '26/FA', credits_milli: 0, fee_minor: 0, currency: 'USD', capacity: 20, enrolled: 0,
            ...(value === undefined ? {} : { continuing_education: value }),
        })), next_after: null } }));
    await page.goto('/portal/courses/');
    await expect(page.locator('#campus-course-results')).toContainText('Offered CEUs: 1.234 CEUs');
    await expect(page.locator('#campus-course-results')).toContainText('Offered CEUs: 0 CEUs');
    await expect(page.locator('#campus-course-results')).toContainText('Offered CEUs: No CEUs');
    await expect(page.locator('#campus-course-results')).toContainText('Offered CEUs: Not recorded in this publication');
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    expect(errors).toEqual([]);
});

test('private enrollment lists identify accepted offered CEUs without claiming earned units', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/portal/api/**', route => {
        const path = new URL(route.request().url()).pathname;
        const json = path === '/portal/api/me/' ? {
            person: { id: id(1), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' },
            contact: { preferred_name: 'Alex', phone: '', address: '', version: 0 },
            students: [{ id: id(2), primary_program_id: id(3), entry_term_id: id(4), active: 1, enrollments: [{
                section_id: id(5), term_id: id(4), section_code: 'CE.101.A', course_code: 'CE.101',
                title: 'Fictional workshop', status: 'C', credits_milli: 0, continuing_education: { ceu_milli: 1250 },
            }] }], applications: [], restrictions: [], accounts: [], news: [], transcript_requests: [],
        } : { items: [], next_after: null };
        return route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/portal/student/registration/');
    await expect(page.locator('#campus-service-content')).toContainText('Accepted offered CEUs');
    await expect(page.locator('#campus-service-content')).toContainText('1.25 CEUs');
    await expect(page.locator('#campus-service-content')).toContainText('Offered CEUs are not earned units.');
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    expect(errors).toEqual([]);
});
