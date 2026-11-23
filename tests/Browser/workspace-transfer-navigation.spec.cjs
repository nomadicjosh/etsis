const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function fixture(page, { limited = false, rejectPage = false } = {}) {
    const calls = [], errors = [];
    let rejected = false;
    const external = { id: id(3), code: 'HIST-200', title: 'History <script>advanced</script>',
        institution_id: id(1), institution_code: 'EXT', institution_name: 'Fictional College', revision: 'fictional-course' };
    const equivalency = number => ({ id: id(number), institution_id: id(1), institution_code: 'EXT',
        institution_name: 'Fictional College', external_course_id: id(3), external_code: external.code,
        external_title: external.title, course_id: id(9), course_code: number === 6 ? 'HIST.201' : 'HIST.101',
        course_title: 'Institutional history', starts_on: '2020-01-01', ends_on: null,
        credits_milli: 3000, awarded_grade: 'TR', minimum_grade_rank: 2, counts_gpa: 0, points_milli: 0,
        revision: 'fictional-equivalency' });
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname, raw = request.postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, method: request.method() });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/institutions/') result.items = [{ id: id(1), code: 'EXT', name: 'Fictional College' }];
        else if (path === `/sis/ui/transfer/institutions/${id(1)}/`) result = {
            id: id(1), code: 'EXT', name: 'Fictional College', revision: 'fictional-institution',
        };
        else if (path === `/sis/ui/transfer/institutions/${id(1)}/children/`) {
            if (data.after && rejectPage && !rejected) {
                rejected = true;
                return route.fulfill({ status: 409, json: { error: 'Fictional page read failed. Try again.' } });
            }
            result = {
            items: data.after ? [external] : [{ id: id(2), code: 'HIST-100', title: 'Foundations' }],
            next_after: data.after ? null : id(20),
            };
        }
        else if (path === `/sis/ui/transfer/external/${id(3)}/`) result = external;
        else if (path === `/sis/ui/transfer/external/${id(3)}/children/`) result = {
            items: [equivalency(data.after ? 6 : 5)], next_after: data.after ? null : id(21),
        };
        else if (path === `/sis/ui/transfer/equivalencies/${id(5)}/`) result = equivalency(5);
        else if (path === `/sis/ui/transfer/equivalencies/${id(6)}/`) result = equivalency(6);
        else if (path.endsWith('/awards/')) result = {
            items: [{ id: id(data.after ? 12 : 11), student_id: id(data.after ? 13 : 10),
                given_name: data.after ? 'Jamie' : 'Alex', family_name: 'Example',
                institutional_id: data.after ? 'S-SECOND' : 'S-FICTIONAL', completed_on: '2026-05-01',
                term_code: '26/FA', credits_milli: 3000, grade_code: 'TR', status: data.after ? 'V' : 'A' }],
            next_after: data.after ? null : id(22),
        };
        else if (/\/sis\/students\/\d+\/record\/$/u.test(path)) result = {
            id: id(13), person_id: id(15), given_name: 'Jamie', family_name: 'Example',
            institutional_id: 'S-SECOND', active: true,
            primary_program: { code: 'ARTS.UG', title: 'Undergraduate Arts' }, entry_term: { code: '26/FA' },
        };
        else if (path.includes('/transfer-awards/')) result = {
            id: id(11), student_id: id(10), equivalency_id: id(6), given_name: 'Alex', family_name: 'Example',
            institutional_id: 'S-FICTIONAL', institution_name: 'Fictional College', external_code: 'HIST-200',
            completed_on: '2026-05-01', term_code: '26/FA', course_version_id: id(16),
            academic_on: '2026-01-12', grade_code: 'TR', counts_gpa: false,
            credit: { id: id(17), course_id: id(9), course_code: 'HIST.201', course_title: 'Institutional history',
                completed_milli: 3000, attempted_milli: 3000, grade_points_milli: 0, status: 'A', version: 0 },
        };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(limited ? '/admin/transfer-only-fixture/' : '/admin/transfer-fixture/');
    await page.locator('.workspace-nav-link[data-workspace="institutions"]').click();
    await page.getByRole('button', { name: 'Fictional College', exact: true }).click();
    return { calls, errors };
}

async function secondExternal(page) {
    await page.getByRole('button', { name: 'Next catalog page', exact: true }).click();
    await expect(page.getByRole('article', { name: 'External course HIST-200', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Open external course', exact: true }).click();
}

async function secondEquivalency(page) {
    await secondExternal(page);
    await page.getByRole('button', { name: 'Next catalog page', exact: true }).click();
    await expect(page.getByRole('article', { name: 'Equivalency HIST.201', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Review equivalency', exact: true }).click();
}

async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, nodes: v.nodes.map(n => ({ target: n.target, summary: n.failureSummary })) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}

test('transfer drill-downs name their parents and restore each catalog page through fresh reads', async ({ page }) => {
    const f = await fixture(page);
    await secondEquivalency(page);
    await page.getByRole('button', { name: 'Back to external course', exact: true }).click();
    await expect(page.getByRole('article', { name: 'Equivalency HIST.201', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Previous catalog page', exact: true })).toBeEnabled();
    await page.getByRole('button', { name: 'Back to institution', exact: true }).click();
    await expect(page.getByRole('article', { name: 'External course HIST-200', exact: true })).toBeVisible();
    expect(f.calls.filter(call => call.path === `/sis/ui/transfer/institutions/${id(1)}/`)).toHaveLength(2);
    expect(f.calls.filter(call => call.path === `/sis/ui/transfer/external/${id(3)}/`)).toHaveLength(2);
    expect(f.errors).toEqual([]);
});

test('returning from a catalog editor retains the related-record page without submitting a draft', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Next catalog page', exact: true }).click();
    await page.getByRole('button', { name: 'Edit institution', exact: true }).click();
    await page.getByRole('textbox', { name: /^Institution name/u }).fill('Unsaved fictional name');
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('article', { name: 'External course HIST-200', exact: true })).toBeVisible();
    expect(f.calls.some(call => call.path.endsWith('/edit/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('failed catalog paging retains the accepted page and permits a deliberate retry', async ({ page }) => {
    const f = await fixture(page, { rejectPage: true });
    const next = page.getByRole('button', { name: 'Next catalog page', exact: true });
    const previous = page.getByRole('button', { name: 'Previous catalog page', exact: true });
    await next.click();
    await expect(page.locator('#drawer-error')).toContainText('Fictional page read failed.');
    await expect(page.getByRole('article', { name: 'External course HIST-100', exact: true })).toBeVisible();
    await expect(previous).toBeDisabled(); await expect(next).toBeEnabled();
    await next.click();
    await expect(page.getByRole('article', { name: 'External course HIST-200', exact: true })).toBeVisible();
    await expect(previous).toBeEnabled(); await expect(next).toBeDisabled();
    await previous.click();
    await expect(page.getByRole('article', { name: 'External course HIST-100', exact: true })).toBeVisible();
    await expect(previous).toBeDisabled();
    expect(f.errors).toEqual([]);
});

test('a connected student returns to the viewed equivalency award page', async ({ page }) => {
    const f = await fixture(page);
    await secondEquivalency(page);
    await page.getByRole('button', { name: 'Next award page', exact: true }).click();
    const awards = page.getByRole('region', { name: 'Transfer awards', exact: true });
    await expect(awards).toContainText('Jamie Example');
    await expect(awards).toContainText('Voided');
    await awards.getByRole('button', { name: 'Open student record', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Student programs', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Transfer awards', exact: true })).toContainText('Jamie Example');
    expect(f.calls.filter(call => call.path.endsWith('/awards/')).at(-1).data.after).toBe(id(22));
    expect(f.errors).toEqual([]);
});

test('transfer cards escape source text and reflow at phone width and text zoom', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Next catalog page', exact: true }).click();
    await expect(page.getByRole('article')).toContainText('History <script>advanced</script>');
    await expect(page.locator('#drawer-body script')).toHaveCount(0);
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-transfer-catalog-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    await page.getByRole('article').screenshot({ path: '/tmp/etsis-transfer-catalog-card-phone.png' });
    await page.getByRole('button', { name: 'Open external course', exact: true }).click();
    await page.getByRole('button', { name: 'Review equivalency', exact: true }).click();
    await accessible(page);
    await page.getByRole('region', { name: 'Transfer awards', exact: true }).getByRole('article')
        .screenshot({ path: '/tmp/etsis-transfer-award-card-phone.png' });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await accessible(page);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('catalog-only access omits student award actions and protected reads', async ({ page }) => {
    const f = await fixture(page, { limited: true });
    await secondEquivalency(page);
    await expect(page.getByRole('button', { name: 'Award transfer credit', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Review award', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Open student record', exact: true })).toHaveCount(0);
    expect(f.calls.some(call => call.path.startsWith('/sis/students/'))).toBe(false);
    expect(f.errors).toEqual([]);
});
