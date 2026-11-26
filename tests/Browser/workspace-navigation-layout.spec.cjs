const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const storageKey = 'etsis.workspace.navigation';

async function fixture(page, preference = null, path = '/admin/') {
    const errors = [], requests = [];
    page.on('pageerror', error => errors.push(error.message));
    if (preference !== null) await page.addInitScript(({ key, value }) => localStorage.setItem(key, value), { key: storageKey, value: preference });
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        requests.push({ path, method: route.request().method() });
        const data = path === '/sis/ui/metrics/' ? { cards: [], intake: [], pipeline: [], queue: [] } : { items: [], next_after: null };
        await route.fulfill({ json: data, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.route('**/portal/api/**', route => route.fulfill({ json: { items: [], unread: 0, has_more: false }, headers: { 'Cache-Control': 'private, no-store' } }));
    await page.goto(path);
    await expect(page.getByRole('button', { name: 'Navigation layout', exact: true })).toBeVisible();
    return { errors, requests };
}
async function choose(page, label) {
    await page.getByRole('button', { name: 'Navigation layout', exact: true }).click();
    await page.getByRole('menuitemradio', { name: new RegExp(`^${label}`) }).click();
    await expect(page.getByRole('button', { name: 'Navigation layout', exact: true })).toBeFocused();
    if (page.viewportSize().width >= 992) {
        if (label === 'Compact sidebar') await expect(page.locator('#workspace-navigation')).toHaveCSS('width', '80px');
        if (label === 'Top navigation') await expect(page.locator('#workspace-navigation')).toHaveCSS('flex-direction', 'row');
    }
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}
async function contained(locator, page) {
    await expect(locator).toBeVisible();
    const box = await locator.boundingBox(), viewport = page.viewportSize();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.y).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 1);
    expect(box.y + box.height).toBeLessThanOrEqual(viewport.height + 1);
}

test('the compact sidebar retains accessible names, focus and its selected layout across reloads', async ({ page }) => {
    const f = await fixture(page);
    const sidebar = page.locator('#workspace-navigation');
    const original = (await sidebar.boundingBox()).width;
    await page.getByRole('button', { name: 'Collapse navigation sidebar', exact: true }).click();
    expect(f.errors).toEqual([]);
    await expect(page.locator('html')).toHaveAttribute('data-workspace-navigation', 'compact');
    await expect(sidebar).toHaveCSS('width', '80px');
    expect((await sidebar.boundingBox()).width).toBe(80);
    expect(original).toBeGreaterThan(80);
    const people = sidebar.getByRole('link', { name: 'People', exact: true });
    await people.focus();
    await expect(page.getByRole('tooltip', { name: 'People', exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('tooltip')).toHaveCount(0);
    await expect(people).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.locator('#directory-heading')).toHaveText('People');
    await expect(people).toHaveAttribute('aria-current', 'page');
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-nav-compact.png' });
    await page.reload();
    await expect(page.getByRole('button', { name: 'Expand navigation sidebar', exact: true })).toBeVisible();
    expect((await sidebar.boundingBox()).width).toBe(80);
    expect(await page.evaluate(key => localStorage.getItem(key), storageKey)).toBe('compact');
    await page.getByRole('button', { name: 'Expand navigation sidebar', exact: true }).click();
    await expect(sidebar).toHaveCSS('width', `${original}px`);
    expect(f.errors).toEqual([]);
});

test('icon flyouts can be hovered and dismissed and stay in the viewport after sidebar scrolling', async ({ page }) => {
    await page.setViewportSize({ width: 1024, height: 640 });
    const f = await fixture(page, 'compact');
    const link = page.locator('#workspace-navigation').getByRole('link', { name: 'Moodle integration', exact: true });
    await link.hover();
    const tip = page.getByRole('tooltip', { name: 'Moodle integration', exact: true });
    await contained(tip, page);
    await tip.hover();
    await expect(tip).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(tip).toHaveCount(0);
    await page.mouse.move(500, 300);
    await link.focus();
    await contained(tip, page);
    await page.locator('.workspace-menu').evaluate(node => { node.scrollTop = 0; });
    await expect(tip).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('top navigation uses bounded Bootstrap menus, keyboard navigation and the current parent workspace', async ({ page }) => {
    const f = await fixture(page);
    await choose(page, 'Top navigation');
    const campus = page.getByRole('button', { name: 'Campus navigation', exact: true });
    await campus.focus(); await page.keyboard.press('ArrowDown');
    const menu = page.locator('#workspace-nav-items-1');
    await contained(menu, page);
    await expect(menu.getByRole('link', { name: 'Overview', exact: true })).toBeFocused();
    await page.keyboard.press('ArrowDown'); await page.keyboard.press('Enter');
    await expect(page.locator('#directory-heading')).toHaveText('People');
    await expect(campus).toHaveClass(/active/);
    await expect(campus).toHaveAttribute('aria-expanded', 'false');
    const tools = page.getByRole('button', { name: 'Tools navigation', exact: true });
    await tools.focus(); await page.keyboard.press('ArrowDown'); await page.keyboard.press('Escape');
    await expect(tools).toBeFocused();
    await expect(tools).toHaveAttribute('aria-expanded', 'false');
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-nav-horizontal.png' });
    await page.goto('/admin/workspace/sections/');
    await expect(page.locator('#directory-heading')).toHaveText('Course sections');
    await expect(page.getByRole('button', { name: 'Campus navigation', exact: true })).toHaveClass(/active/);
    await page.setViewportSize({ width: 1024, height: 700 });
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await page.getByRole('button', { name: 'Tools navigation', exact: true }).click();
    await contained(page.locator('.workspace-nav-group-items.show'), page);
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test('layout changes preserve an unsaved form and resizing preserves its open preview drawer', async ({ page }) => {
    const f = await fixture(page, null, '/admin/workspace/forms/');
    await page.locator('#form-name').fill('Fictional preserved draft');
    const before = f.requests.length;
    await choose(page, 'Compact sidebar');
    await choose(page, 'Top navigation');
    await expect(page.locator('#form-name')).toHaveValue('Fictional preserved draft');
    await expect(page.locator('#leave-modal')).toBeHidden();
    await page.locator('#form-preview').click();
    await expect(page.locator('#record-drawer')).toHaveClass(/show/);
    await page.setViewportSize({ width: 375, height: 812 });
    await expect(page.locator('#record-drawer')).toHaveClass(/show/);
    await expect(page.locator('#drawer-title')).toHaveText('Fictional preserved draft');
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await expect(page.locator('#form-name')).toHaveValue('Fictional preserved draft');
    expect(f.requests.slice(before)).toEqual([]);
    expect(f.errors).toEqual([]);
});

test('all navigation layouts retain the same restricted permission-filtered destinations', async ({ page }) => {
    const f = await fixture(page, null, '/admin/admissions-only-fixture/');
    const sidebar = page.locator('#workspace-navigation');
    const before = await sidebar.locator('.workspace-nav-link').evaluateAll(links => links.map(link => link.getAttribute('href')));
    expect(before).toEqual(['/admin/', '/admin/workspace/applications/', '/admin/workspace/teaching/']);
    await choose(page, 'Compact sidebar');
    await choose(page, 'Top navigation');
    expect(await sidebar.locator('.workspace-nav-link').evaluateAll(links => links.map(link => link.getAttribute('href')))).toEqual(before);
    await expect(page.getByRole('button', { name: 'Tools navigation', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Campus navigation', exact: true }).click();
    await expect(sidebar.getByRole('link', { name: 'Admissions', exact: true })).toBeVisible();
    await expect(sidebar.getByRole('link', { name: 'People', exact: true })).toHaveCount(0);
    await accessible(page);
    expect(f.errors).toEqual([]);
});

for (const preference of ['compact', 'horizontal']) {
    test(`${preference}: phone navigation keeps full labels, scrolls and returns focus after dismissal`, async ({ page }) => {
        await page.setViewportSize({ width: 320, height: 900 });
        const f = await fixture(page, preference);
        await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
        const sidebar = page.locator('#workspace-navigation');
        await expect(sidebar.getByRole('link', { name: 'People', exact: true })).toBeVisible();
        expect((await sidebar.boundingBox()).width).toBeLessThanOrEqual(320);
        await page.addStyleTag({ content: 'html { font-size: 200%; }' });
        await accessible(page);
        await page.keyboard.press('Escape');
        await expect(page.getByRole('button', { name: 'Open navigation', exact: true })).toBeFocused();
        await choose(page, preference === 'compact' ? 'Top navigation' : 'Compact sidebar');
        await expect(page.getByRole('status').filter({ hasText: 'Layout saved for larger screens.' })).toHaveText('Layout saved for larger screens. Mobile navigation keeps full labels.');
        await page.addStyleTag({ content: 'html { font-size: 100%; }' });
        await page.setViewportSize({ width: 1440, height: 1000 });
        const layout = preference === 'compact' ? 'horizontal' : 'compact';
        await expect(page.locator('html')).toHaveAttribute('data-workspace-navigation', layout);
        if (layout === 'horizontal') await expect(page.getByRole('button', { name: 'Campus navigation', exact: true })).toBeVisible();
        else await expect(sidebar).toHaveCSS('width', '80px');
        expect(f.errors).toEqual([]);
    });
    test(`${preference}: saved navigation layout is applied while the main workspace script is still pending`, async ({ page }) => {
        let release; const pending = new Promise(resolve => { release = resolve; });
        await page.addInitScript(({ key, value }) => localStorage.setItem(key, value), { key: storageKey, value: preference });
        await page.route('**/assets/js/workspace.js', async route => { await pending; await route.continue(); });
        await page.route('**/sis/**', route => route.fulfill({ json: { items: [], next_after: null }, headers: { 'Cache-Control': 'private, no-store' } }));
        await page.goto('/admin/workspace/people/', { waitUntil: 'commit' });
        await expect(page.locator('html')).toHaveAttribute('data-workspace-navigation', preference);
        await expect(page.locator('#workspace-loading')).toBeVisible();
        if (preference === 'compact') await expect(page.locator('#workspace-navigation')).toHaveCSS('width', '80px');
        else await expect(page.locator('.workspace-nav-group-toggle').first()).toBeVisible();
        await expect(page.locator('[data-panel="overview"]')).toBeHidden();
        release();
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('#directory-heading')).toHaveText('People');
    });
}

test('unavailable browser storage keeps navigation usable for the current page', async ({ page }) => {
    await page.addInitScript(() => {
        Storage.prototype.getItem = () => { throw new DOMException('Storage blocked', 'SecurityError'); };
        Storage.prototype.setItem = () => { throw new DOMException('Storage blocked', 'SecurityError'); };
    });
    const f = await fixture(page);
    await choose(page, 'Top navigation');
    await expect(page.getByRole('button', { name: 'Campus navigation', exact: true })).toBeVisible();
    await choose(page, 'Compact sidebar');
    expect((await page.locator('#workspace-navigation').boundingBox()).width).toBe(80);
    expect(f.errors).toEqual([]);
});

test('invalid saved layout values fall back to the full sidebar without becoming markup', async ({ page }) => {
    const f = await fixture(page, '<img src=x onerror=alert(1)>');
    await expect(page.locator('html')).toHaveAttribute('data-workspace-navigation', 'sidebar');
    await expect(page.getByRole('button', { name: 'Collapse navigation sidebar', exact: true })).toBeVisible();
    await expect(page.locator('img[onerror]')).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('the layout chooser exposes radio state and keyboard selection within a short enlarged phone viewport', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 550 });
    const f = await fixture(page);
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    const picker = page.getByRole('button', { name: 'Navigation layout', exact: true });
    await picker.focus(); await page.keyboard.press('ArrowDown');
    const full = page.getByRole('menuitemradio', { name: /^Full sidebar/ });
    await expect(full).toBeFocused();
    await expect(full).toHaveAttribute('aria-checked', 'true');
    await contained(page.locator('.workspace-layout-menu'), page);
    await accessible(page);
    await page.keyboard.press('ArrowDown'); await page.keyboard.press('Enter');
    await expect(picker).toBeFocused();
    await expect(page.locator('[data-navigation-layout="compact"]')).toHaveAttribute('aria-checked', 'true');
    expect(f.errors).toEqual([]);
});
