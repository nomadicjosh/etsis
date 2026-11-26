const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function fixture(page, kind = 'courses', options = {}) {
    const calls = [], errors = [];
    const row = { id: id(1), code: 'MATH.101', title: 'Fictional Algebra', credits_milli: 3000, active: true };
    const section = { id: id(2), code: 'MATH.101.A', course_id: row.id, course_code: row.code,
        course_title: row.title, term_code: '26/FA', capacity: 20, active: true };
    let settings = { revision: 'a'.repeat(64), credits_milli: 3000,
        credit_policy: { minimum_milli: 1000, maximum_milli: 6000, increment_milli: 500 },
        ...(kind === 'sections' ? { offering_credits_milli: null, course_version_id: options.unbound ? null : id(3) } : {}) };
    let reject = options.reject;
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data && path.endsWith('/credits/')) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = {
            items: path.includes('/courses/') ? [row] : path.includes('/sections/') ? [section] : [], next_after: null,
        };
        else if (path.endsWith('/credits/')) {
            if (!data && options.delayed) await pending;
            if (data && reject) {
                const status = reject; reject = null;
                await route.fulfill({ status, json: { error: 'Fictional credit policy changed. Reload before saving.' } });
                return;
            }
            if (data) settings = { ...settings, revision: 'b'.repeat(64), ...(kind === 'courses'
                ? { credits_milli: data.credits_milli, credit_policy: { minimum_milli: data.minimum_milli,
                    maximum_milli: data.maximum_milli, increment_milli: data.increment_milli } }
                : { offering_credits_milli: data.use_default ? null : data.offering_credits_milli }) };
            result = settings;
        } else if (path.endsWith('/publication/page/')) result = { items: [], next_cursor: null, through_version: 0 };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    if (kind === 'sections') await page.getByRole('link', { name: 'Sections', exact: true }).click();
    await page.getByRole('button', { name: kind === 'courses' ? row.title : section.code, exact: true }).click();
    await page.getByRole('button', { name: kind === 'courses' ? 'Course credit policy' : 'Offering credits', exact: true }).click();
    return { calls, errors, release };
}

async function accessible(page) {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}

test('course range saves exact values and leads to publication with narrow and enlarged text', async ({ page }) => {
    const f = await fixture(page);
    await page.getByLabel('Default academic credits', { exact: true }).fill('3.5');
    await page.getByLabel('Course credit rule').selectOption('range');
    await page.getByLabel('Minimum academic credits', { exact: true }).fill('1');
    await page.getByLabel('Maximum academic credits', { exact: true }).fill('6');
    await page.getByLabel('Credit increment', { exact: true }).fill('0.5');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-credit-policy-course-mobile.png', fullPage: true });
    await page.getByRole('button', { name: 'Save credit draft', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeVisible();
    expect(f.calls[0]).toMatchObject({ data: { credits_milli: 3500, minimum_milli: 1000,
        maximum_milli: 6000, increment_milli: 500, revision: 'a'.repeat(64) }, csrf: 'fictional-test-token' });
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await accessible(page);
    await page.getByRole('button', { name: 'Review publication', exact: true }).click();
    await expect(page.locator('#drawer-title')).toHaveText('Published course definitions');
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.locator('#drawer-title')).toHaveText('Fictional Algebra');
    expect(f.errors).toEqual([]);
});

test('offering choice and inherited default save without losing the record return', async ({ page }) => {
    const f = await fixture(page, 'sections');
    await expect(page.locator('#drawer-body')).toContainText('Allowed: 1–6 academic credits, in increments of 0.5.');
    await expect(page.getByLabel('Default credits for this offering')).toBeDisabled();
    await page.getByLabel('Use the retained course default').uncheck();
    await page.getByLabel('Default credits for this offering').fill('2.5');
    await page.getByRole('button', { name: 'Save credit draft', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeVisible();
    expect(f.calls[0].data).toEqual({ revision: 'a'.repeat(64), use_default: false, offering_credits_milli: 2500, student_selectable: false });
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-credit-policy-section-mobile.png', fullPage: true });
    await page.getByLabel('Use the retained course default').check();
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeHidden();
    await page.getByRole('button', { name: 'Save credit draft', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeVisible();
    expect(f.calls[1].data).toEqual({ revision: 'b'.repeat(64), use_default: true, offering_credits_milli: null, student_selectable: false });
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.locator('#drawer-title')).toHaveText('MATH.101.A');
    expect(f.errors).toEqual([]);
});

test('failed saves preserve entered values and permission loss clears the form', async ({ page }) => {
    await fixture(page, 'courses', { reject: 409 });
    await page.getByLabel('Default academic credits', { exact: true }).fill('4.5');
    await page.getByRole('button', { name: 'Save credit draft', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional credit policy changed.');
    await expect(page.getByLabel('Default academic credits', { exact: true })).toHaveValue('4.5');
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeHidden();
    await page.getByRole('button', { name: 'Save credit draft', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeVisible();
    await page.unroute('**/sis/**');
    await page.route('**/sis/**', route => route.fulfill({ status: 403, json: { error: 'Fictional access ended.' } }));
    await page.getByLabel('Default academic credits', { exact: true }).fill('5');
    await page.getByRole('button', { name: 'Save credit draft', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('This task needs additional access.');
    await expect(page.getByLabel('Default academic credits', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Back to record', exact: true })).toBeVisible();
});

test('a credit read finishing after return cannot replace the record', async ({ page }) => {
    const f = await fixture(page, 'courses', { delayed: true });
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    f.release();
    await expect(page.locator('#drawer-title')).toHaveText('Fictional Algebra');
    await expect(page.getByLabel('Default academic credits', { exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('an unbound section labels its draft policy without claiming a retained publication', async ({ page }) => {
    await fixture(page, 'sections', { unbound: true });
    await expect(page.locator('#drawer-body')).toContainText('No course publication is selected. These limits come from the course draft.');
    await expect(page.getByLabel('Use the course draft default')).toBeChecked();
    await expect(page.getByLabel('Use the retained course default')).toHaveCount(0);
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.locator('#drawer-title')).toHaveText('MATH.101.A');
});


test('section opts into individual credit choices only under a retained range', async ({ page }) => {
    const f = await fixture(page, 'sections');
    const choice = page.getByLabel('Allow each student to choose credits within the published range');
    await choice.focus();
    await page.keyboard.press('Space');
    await expect(choice).toBeChecked();
    await page.getByRole('button', { name: 'Save credit draft', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeVisible();
    expect(f.calls[0].data.student_selectable).toBe(true);
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    expect(f.errors).toEqual([]);
});
