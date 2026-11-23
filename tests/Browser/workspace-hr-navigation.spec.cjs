const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');
const body = page => page.locator('#drawer-body');
const button = (page, name) => body(page).getByRole('button', { name, exact: true });
const library = (page, kind = 'Job definitions') => body(page).getByRole('list', { name: kind, exact: true });
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(row => ({ id: row.id, nodes: row.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const reflow = await body(page).evaluate(el => ({ overflow: Math.max(0, el.scrollWidth - el.clientWidth - 1),
        elements: el.scrollWidth <= el.clientWidth + 1 ? [] : [...el.querySelectorAll('*')].filter(child => child.getBoundingClientRect().right > el.getBoundingClientRect().right + 1)
            .slice(0, 8).map(child => ({ tag: child.tagName, class: child.className, text: child.textContent.slice(0, 60) })) }));
    expect(reflow).toEqual({ overflow: 0, elements: [] });
}
async function fixture(page, { dense = false } = {}) {
    const f = { calls: [], errors: [], fail: null, deny: null, delay: null };
    let release;
    const pending = new Promise(resolve => { release = resolve; }); f.release = release;
    f.jobs = Array.from({ length: dense ? 50 : 2 }, (_, n) => ({ id: id(n + 1), code: `LIB.${n + 1}`,
        title: dense ? `Fictional ${'LibraryServices'.repeat(8)} ${n}` : n ? 'Library coordinator' : 'Library assistant <script>fictional</script>',
        currency: 'USD', basis: n % 2 ? 'monthly' : 'hourly', rate_minor: n % 2 ? 350050 : 2500,
        weekly_minutes: 2250, pay_grade_id: id(50), version: 2, active: n % 2 ? 0 : true }));
    f.grades = [{ id: id(50), code: 'PAY.1', title: 'Fictional pay band', currency: 'USD', basis: 'monthly', minimum_minor: 100000, maximum_minor: 500000, active: true, version: 1 }];
    if (dense) f.grades.push(...Array.from({ length: 49 }, (_, n) => ({ ...f.grades[0], id: id(500 + n), code: `PAY.${n + 2}`, title: `Fictional ${'PayGrade'.repeat(12)} ${n}` })));
    page.on('pageerror', error => f.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const req = route.request(), path = new URL(req.url()).pathname;
        const data = req.postData() ? JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json')) : {};
        f.calls.push({ path, data, method: req.method(), csrf: req.headers()['x-csrf-token'] });
        if (path === f.deny) return route.fulfill({ status: 403, json: { error: 'Fictional access denied.' } });
        if (path === f.fail) { f.fail = null; return route.fulfill({ status: 503, json: { error: 'Fictional page unavailable.' } }); }
        const delayed = path === f.delay;
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        const match = path.match(/^\/sis\/hr\/(jobs|pay-grades)\/(page|save|[A-Z0-9]{26})\/$/);
        if (match) {
            const jobs = match[1] === 'jobs', rows = jobs ? f.jobs : f.grades;
            if (match[2] === 'page') {
                const items = rows.filter(row => !data.search || `${row.code} ${row.title}`.toLowerCase().includes(data.search.toLowerCase()));
                result = { items: jobs && !dense ? items.slice(data.after ? 1 : 0, data.after ? 2 : 1) : items,
                    next_after: jobs && !dense && !data.after && items.length > 1 ? id(1) : null,
                    new_id: id(jobs ? 200 : 300), currencies: [{ code: 'USD', digits: 2 }, { code: 'JPY', digits: 0 }, { code: 'BHD', digits: 3 }] };
            } else if (match[2] === 'save') {
                result = { ...data, version: (data.version ?? -1) + 1 };
                const index = rows.findIndex(row => row.id === data.id);
                if (index < 0) rows.push(result); else rows[index] = result;
            } else result = rows.find(row => row.id === match[2]);
        }
        if (delayed) await pending;
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="employees"]').click();
    f.open = () => page.getByRole('button', { name: 'HR setup', exact: true }).click();
    f.second = async () => {
        await f.open(); await page.getByLabel('Search job definitions', { exact: true }).fill('LIB');
        await button(page, 'Find definitions').click(); await button(page, 'Next definitions').click();
        await expect(library(page)).toContainText('Library coordinator');
        await button(page, 'Edit definition').click();
        await expect(page.getByLabel('Code', { exact: true })).toHaveValue('LIB.2');
    };
    return f;
}

test('nested pay-grade cancellation preserves the job draft and searched library page', async ({ page }) => {
    const f = await fixture(page); await f.second();
    await page.getByLabel('Title', { exact: true }).fill('Unsaved fictional coordinator');
    await page.getByLabel('Default rate', { exact: true }).fill('3800.50');
    await page.getByLabel('Weekly hours', { exact: true }).fill('0');
    await expect(page.getByLabel('Active definition', { exact: true })).not.toBeChecked();
    await button(page, 'Create pay grade').click(); await button(page, 'Back to job draft').click();
    await expect(page.getByLabel('Title', { exact: true })).toHaveValue('Unsaved fictional coordinator');
    await expect(page.getByLabel('Default rate', { exact: true })).toHaveValue('3800.50');
    await expect(page.getByLabel('Weekly hours', { exact: true })).toHaveValue('0');
    await expect(page.getByLabel('Active definition', { exact: true })).not.toBeChecked();
    await button(page, 'Back to job definitions').click();
    await expect(page.getByLabel('Search job definitions', { exact: true })).toHaveValue('LIB');
    await expect(library(page)).toContainText('Library coordinator');
    await expect(button(page, 'Next definitions')).toBeDisabled();
    expect(f.calls.some(call => call.path.endsWith('/save/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('nested creation and job saves return to the accepted library page with existing save contracts', async ({ page }) => {
    const f = await fixture(page); await f.second();
    await button(page, 'Create pay grade').click();
    await page.getByLabel('Code', { exact: true }).fill('PAY.NEW');
    await page.getByLabel('Title', { exact: true }).fill('Fictional new monthly band');
    await page.getByLabel('Minimum rate', { exact: true }).fill('1000');
    await page.getByLabel('Maximum rate', { exact: true }).fill('6000');
    await button(page, 'Save pay grade').click();
    await expect(page.getByLabel('Pay grade', { exact: true })).toHaveValue(id(300));
    await button(page, 'Save job definition').click();
    await expect(library(page)).toContainText('Library coordinator');
    await expect(page.getByLabel('Search job definitions', { exact: true })).toHaveValue('LIB');
    await expect(button(page, 'Next definitions')).toBeDisabled();
    expect(f.calls.find(call => call.path === '/sis/hr/jobs/save/').data).toMatchObject({ id: id(2), version: 2, pay_grade_id: id(300), basis: 'monthly', currency: 'USD', rate_minor: 350050, weekly_minutes: 2250, active: false });
    expect(f.calls.filter(call => call.path.endsWith('/save/')).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(f.errors).toEqual([]);
});

test('failed library paging preserves accepted results and supports retry refresh and clear', async ({ page }) => {
    const f = await fixture(page); f.fail = '/sis/hr/jobs/page/'; await f.open();
    await expect(body(page)).toContainText('Definitions could not be loaded.');
    await expect(button(page, 'Add job definition')).toBeDisabled();
    await button(page, 'Refresh setup').click();
    await page.getByLabel('Search job definitions', { exact: true }).fill('LIB'); await button(page, 'Find definitions').click();
    f.fail = '/sis/hr/jobs/page/'; await button(page, 'Next definitions').click();
    await expect(body(page)).toContainText('previous page remains displayed');
    await expect(library(page)).toContainText('Library assistant');
    await expect(button(page, 'Previous definitions')).toBeDisabled();
    await button(page, 'Next definitions').click(); await expect(library(page)).toContainText('Library coordinator');
    await expect(button(page, 'Next definitions')).toBeDisabled();
    await button(page, 'Refresh setup').click(); await expect(button(page, 'Next definitions')).toBeDisabled();
    await button(page, 'Clear search').click();
    await expect(page.getByLabel('Search job definitions', { exact: true })).toBeFocused();
    await expect(library(page)).toContainText('Library assistant');
    await expect(button(page, 'Previous definitions')).toBeDisabled();
    expect(f.errors).toEqual([]);
});

test('denied library reads clear definition facts and controls', async ({ page }) => {
    const f = await fixture(page); await f.open(); await expect(library(page)).toBeVisible();
    f.deny = '/sis/hr/jobs/page/'; await button(page, 'Refresh setup').click();
    await expect(body(page)).toContainText('HR setup access is no longer available.');
    await expect(body(page)).not.toContainText('Library assistant');
    await expect(button(page, 'Edit definition')).toHaveCount(0);
    await expect(button(page, 'Add job definition')).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('pay-grade lookup searches on Enter without saving the job and clears denied choices', async ({ page }) => {
    const f = await fixture(page); await f.second();
    await page.getByLabel('Search pay grades', { exact: true }).fill('PAY');
    await page.getByLabel('Search pay grades', { exact: true }).press('Enter');
    await expect.poll(() => f.calls.some(call => call.path === '/sis/hr/pay-grades/page/' && call.data.search === 'PAY')).toBe(true);
    expect(f.calls.some(call => call.path.endsWith('/save/'))).toBe(false);
    f.deny = '/sis/hr/pay-grades/page/'; await button(page, 'Find pay grades').click();
    await expect(body(page)).toContainText('HR setup access is no longer available.');
    await expect(page.getByLabel('Pay grade', { exact: true })).toHaveCount(0);
    await expect(button(page, 'Save job definition')).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('a denied save clears the HR editor and leaves the definition unchanged', async ({ page }) => {
    const f = await fixture(page); await f.second();
    await page.getByLabel('Title', { exact: true }).fill('Fictional rejected title');
    f.deny = '/sis/hr/jobs/save/'; await button(page, 'Save job definition').click();
    await expect(body(page)).toContainText('HR setup access is no longer available.');
    await expect(page.getByLabel('Title', { exact: true })).toHaveCount(0);
    await expect(button(page, 'Save job definition')).toHaveCount(0);
    expect(f.jobs[1]).toMatchObject({ title: 'Library coordinator', version: 2 });
    expect(f.errors).toEqual([]);
});

test('full definition lists and grouped editors reflow and keep category menus left aligned', async ({ page }) => {
    const f = await fixture(page, { dense: true }); await f.open();
    await expect(library(page).locator(':scope > li')).toHaveCount(50);
    await accessible(page); await page.screenshot({ path: '/tmp/etsis-hr-library-desktop.png' });
    await page.setViewportSize({ width: 320, height: 900 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await accessible(page);
    const toggle = button(page, 'Change HR setup category'); await toggle.click();
    const menu = page.locator('#' + await toggle.getAttribute('aria-controls'));
    await expect(menu.getByRole('button', { name: 'Job definitions', exact: true })).toHaveAttribute('aria-pressed', 'true');
    expect(await menu.locator('.dropdown-item').evaluateAll(items => items.every(item => !['center', 'space-around', 'space-evenly'].includes(getComputedStyle(item).justifyContent)))).toBe(true);
    const bounds = await menu.boundingBox(); expect(bounds.x).toBeGreaterThanOrEqual(0); expect(bounds.x + bounds.width).toBeLessThanOrEqual(321);
    await page.keyboard.press('Escape'); await expect(toggle).toBeFocused();
    await library(page).locator(':scope > li').last().scrollIntoViewIfNeeded();
    await expect(library(page).locator(':scope > li').last()).toBeInViewport();
    await library(page).locator(':scope > li').first().getByRole('button', { name: 'Edit definition', exact: true }).click();
    await expect(page.getByRole('group', { name: 'Default workload', exact: true })).toBeVisible();
    await button(page, 'Save job definition').scrollIntoViewIfNeeded();
    await expect(button(page, 'Save job definition')).toBeInViewport();
    await accessible(page); await page.screenshot({ path: '/tmp/etsis-hr-job-phone.png' });
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.addStyleTag({ content: 'html { font-size: 100% !important; }' });
    await body(page).locator('.hr-definition-section').nth(1).screenshot({ path: '/tmp/etsis-hr-job-pay-defaults-desktop.png' });
    await page.setViewportSize({ width: 320, height: 900 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await button(page, 'Back to job definitions').click();
    await recordAction(page, 'Pay grades'); await expect(library(page, 'Pay grades')).toContainText('Fictional pay band');
    await expect(library(page, 'Pay grades').locator(':scope > li')).toHaveCount(50);
    await accessible(page);
    await library(page, 'Pay grades').locator(':scope > li').first().getByRole('button', { name: 'Edit definition', exact: true }).click();
    await expect(page.getByRole('group', { name: 'Pay range', exact: true })).toBeVisible();
    await accessible(page);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

for (const stage of ['library read', 'selected grade read', 'accepted job save']) {
    test(`closing a pending ${stage} cannot reopen an HR drawer`, async ({ page }) => {
        const f = await fixture(page);
        if (stage === 'library read') { f.delay = '/sis/hr/jobs/page/'; await f.open(); }
        else if (stage === 'selected grade read') {
            await f.open(); await expect(library(page)).toBeVisible(); f.delay = `/sis/hr/pay-grades/${id(50)}/`;
            await button(page, 'Edit definition').click();
        } else {
            await f.second(); f.delay = '/sis/hr/jobs/save/'; await button(page, 'Save job definition').click();
        }
        await expect.poll(() => f.calls.some(call => call.path === f.delay)).toBe(true);
        await page.getByRole('button', { name: 'Close record', exact: true }).click(); await expect(body(page)).toBeEmpty();
        f.release(); await page.waitForLoadState('networkidle');
        await expect(body(page)).toBeEmpty(); await expect(page.locator('#record-drawer')).not.toHaveClass(/\bshow\b/);
        if (stage === 'accepted job save') expect(f.jobs[1].version).toBe(3);
        expect(f.errors).toEqual([]);
    });
}
