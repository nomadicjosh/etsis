const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const link = (id, label, children = [], type = 'link') => ({ id, label, type, children,
    description: '', enabled: true, new_tab: false, icon: '', page_id: null, url: type === 'link' ? '/portal/courses/' : null });
async function fixture(page, options = {}) {
    const calls = [], errors = [];
    let state = { slot: 'primary', revision: 'a'.repeat(64), can_publish: true, has_publication: false,
        items: [link('study', 'Study with us', [link('courses', 'Courses'),
            link('support', 'Student support', [link('help', 'Get help')], 'group')], 'group'), link('visit', 'Visit campus')] };
    let reject = options.reject;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/website/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const body = route.request().postData();
        const data = body ? JSON.parse(new URLSearchParams(body).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        if (data && reject) {
            const status = reject; reject = null;
            await route.fulfill({ status, json: { error: 'Fictional saved revision changed.' } }); return;
        }
        let result = state;
        if (path === '/sis/website/pages/') result = { items: [{ id: '1', name: 'Fictional admissions page' }], next_after: null };
        else if (path === '/sis/website/pages/1/') result = { page_id: '1', name: 'Fictional admissions page', status: 'review', can_publish: true,
            revision: 'a'.repeat(64), translations: [{ locale: 'en', title: 'Admissions', route: '/fictional-admissions/' }], review_note: null };
        else if (path.endsWith('/pages/1/review/')) result = { ...data, page_id: '1', operation_id: '01K00000000000000000000001', review_token: 'fictional-page-proof' };
        else if (path.endsWith('/pages/1/confirm/')) result = { page_id: '1', status: 'published' };
        else if (path.endsWith('/review/')) result = { slot: 'primary', items: state.items, revision: state.revision,
            operation_id: '01K00000000000000000000001', review_token: 'fictional-menu-proof' };
        else if (path.endsWith('/publish/')) result = { slot: 'primary', version: 2 };
        else if (data) { state = { ...state, items: data.items, revision: 'b'.repeat(64) }; result = state; }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/fixture/website-manager/' + (options.pages ? '?mode=pages' : ''));
    await expect(page.getByRole('button', { name: options.pages ? 'Publication settings' : 'Save menu draft', exact: true })).toBeVisible();
    return { calls, errors };
}
async function action(page, label, action) {
    const toggle = page.getByRole('button', { name: `Item actions: ${label}`, exact: true });
    await toggle.click();
    const popup = await toggle.getAttribute('aria-controls');
    await page.locator(`#${popup}`).getByRole('button', { name: action, exact: true }).click();
}
async function accessible(page) {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.map(value => ({ id: value.id, targets: value.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}
test('nested draft editing saves exact item settings and requires reviewed publication', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Edit item: Visit campus', exact: true }).click();
    await page.getByLabel('Navigation label', { exact: true }).fill('Visit our fictional campus');
    await page.getByLabel('Link address').fill('https://example.invalid/visit');
    await page.getByLabel('Open link in a new tab').check();
    await page.getByLabel('Bootstrap icon').selectOption('geo-alt');
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Save menu draft', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeEnabled();
    expect(f.calls[0]).toMatchObject({ data: { revision: 'a'.repeat(64), items: expect.arrayContaining([
        expect.objectContaining({ label: 'Visit our fictional campus', new_tab: true, icon: 'geo-alt', url: 'https://example.invalid/visit' }),
    ]) }, csrf: 'fictional-test-token' });
    await page.getByRole('button', { name: 'Review publication', exact: true }).click();
    await expect(page.locator('#site-review-body')).toContainText('opens in a new tab');
    expect(f.calls.some(value => value.path.endsWith('/publish/'))).toBe(false);
    await page.getByRole('button', { name: 'Confirm publication', exact: true }).click();
    await expect(page.locator('#site-status')).toContainText('Publication confirmed.');
    expect(f.calls.at(-1)).toMatchObject({ path: '/sis/website/menus/primary/publish/',
        data: { review_token: 'fictional-menu-proof' } });
    expect(f.errors).toEqual([]);
});
test('keyboard menu actions nest, move, duplicate, undo and remove without publishing', async ({ page }) => {
    const f = await fixture(page);
    await action(page, 'Visit campus', 'Nest under previous item');
    await expect(page.locator('[data-item-id="study"] > ol > [data-item-id="visit"]')).toHaveCount(1);
    await action(page, 'Visit campus', 'Move out one level');
    await action(page, 'Visit campus', 'Move up');
    await expect(page.locator('#site-manager > .site-builder .site-tree').first().locator(':scope > li').first()).toHaveAttribute('data-item-id', 'visit');
    await action(page, 'Visit campus', 'Duplicate item');
    await expect(page.getByRole('button', { name: 'Edit item: Visit campus', exact: true })).toHaveCount(2);
    await page.getByRole('button', { name: 'Undo', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Edit item: Visit campus', exact: true })).toHaveCount(1);
    await action(page, 'Visit campus', 'Remove item');
    await expect(page.getByRole('button', { name: 'Edit item: Visit campus', exact: true })).toHaveCount(0);
    expect(f.calls).toEqual([]); expect(f.errors).toEqual([]);
});
test('drag handles reorder the actual menu tree', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Drag to reorder: Visit campus', exact: true }).scrollIntoViewIfNeeded();
    const start = await page.getByRole('button', { name: 'Drag to reorder: Visit campus', exact: true }).boundingBox();
    const destination = await page.getByRole('button', { name: 'Drag to reorder: Study with us', exact: true }).boundingBox();
    await page.mouse.move(start.x + start.width / 2, start.y + start.height / 2);
    await page.mouse.down();
    await page.mouse.move(destination.x + destination.width / 2, destination.y + 2, { steps: 20 });
    await page.mouse.up();
    await expect(page.locator('#site-manager > .site-builder .site-tree').first().locator(':scope > li').first()).toHaveAttribute('data-item-id', 'visit');
    await page.getByRole('button', { name: 'Save menu draft', exact: true }).click();
    expect(f.calls[0].data.items.map(value => value.id)).toEqual(['visit', 'study']);
    expect(f.errors).toEqual([]);
});
test('dropdown previews and action menus remain inside narrow and enlarged screens', async ({ page }) => {
    const f = await fixture(page);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-site-menu-mobile.png', fullPage: true });
    const preview = page.locator('.site-preview');
    const study = preview.getByRole('button', { name: 'Study with us', exact: true });
    await study.scrollIntoViewIfNeeded(); await study.click();
    const support = page.getByRole('button', { name: 'Student support', exact: true });
    await support.focus(); await page.keyboard.press('ArrowRight');
    await expect(page.getByRole('button', { name: 'Get help', exact: true })).toBeFocused();
    for (const menu of await page.locator('.dropdown-menu.show').all()) {
        const box = await menu.boundingBox(); expect(box.x).toBeGreaterThanOrEqual(0); expect(box.x + box.width).toBeLessThanOrEqual(376);
    }
    await page.keyboard.press('ArrowLeft'); await expect(support).toBeFocused();
    await page.keyboard.press('Escape');
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await accessible(page);
    expect(f.errors).toEqual([]);
});
test('failed saves retain edits, unsaved location changes ask explicitly, and permission loss clears them', async ({ page }) => {
    await fixture(page, { reject: 409 });
    await page.getByRole('button', { name: 'Edit item: Visit campus', exact: true }).click();
    await page.getByLabel('Navigation label', { exact: true }).fill('Keep this fictional label');
    await page.getByRole('button', { name: 'Save menu draft', exact: true }).click();
    await expect(page.getByLabel('Navigation label', { exact: true })).toHaveValue('Keep this fictional label');
    await page.getByLabel('Menu location').selectOption('footer');
    await expect(page.locator('#site-status')).toContainText('You have unsaved changes.');
    await page.getByRole('button', { name: 'Keep editing', exact: true }).click();
    await page.unroute('**/sis/website/**');
    await page.route('**/sis/website/**', route => route.fulfill({ status: 403, json: { error: 'Private denied.' } }));
    await page.getByRole('button', { name: 'Save menu draft', exact: true }).click();
    await expect(page.locator('#site-status')).toContainText('Your website access ended.');
    await expect(page.getByLabel('Navigation label', { exact: true })).toHaveCount(0);
});
test('page publication reviews UTC dates and preserves entries when returning from review', async ({ page }) => {
    const f = await fixture(page, { pages: true });
    await page.getByRole('button', { name: 'Publication settings', exact: true }).click();
    await page.getByLabel('Publication starts (UTC)').fill('2026-12-01T12:30');
    await page.getByLabel('Publication ends (UTC)').fill('2026-12-20T12:30');
    await page.getByRole('button', { name: 'Review this action', exact: true }).click();
    await expect(page.locator('#site-review-body')).toContainText('2026-12-01T12:30:00Z');
    await page.getByRole('button', { name: 'Revise this action', exact: true }).click();
    await expect(page.getByLabel('Publication starts (UTC)')).toHaveValue('2026-12-01T12:30');
    await page.getByRole('button', { name: 'Review this action', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm reviewed action', exact: true }).click();
    await expect(page.locator('#site-status')).toContainText('Publication confirmed.');
    expect(f.calls.at(-1)).toMatchObject({ path: '/sis/website/pages/1/confirm/', data: { review_token: 'fictional-page-proof' } });
    expect(f.errors).toEqual([]);
});

test('the actual public template supports nested Bootstrap dropdowns outside clipped navbars and sidebars', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/fixture/site-navigation/');
    await page.getByRole('button', { name: 'Explore campus', exact: true }).click();
    const child = page.getByRole('button', { name: 'Campus resources', exact: true });
    await child.focus(); await page.keyboard.press('ArrowRight');
    const help = page.getByRole('link', { name: 'Fictional help (opens in a new tab)', exact: true });
    await expect(help).toBeFocused();
    await expect(help).toHaveAttribute('rel', 'noopener noreferrer');
    for (const menu of await page.locator('.dropdown-menu.show').all()) {
        const box = await menu.boundingBox();
        expect(box.x).toBeGreaterThanOrEqual(0); expect(box.x + box.width).toBeLessThanOrEqual(376);
        expect(await menu.evaluate(el => {
            const r = el.getBoundingClientRect();
            const front = document.elementFromPoint(r.x + r.width / 2, r.y + r.height - 8);
            // At phone widths a nested popup may legitimately overlap its parent.
            return el.contains(front) || Boolean(front?.closest('.dropdown-menu.show'));
        })).toBe(true);
    }
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-public-dropdown-mobile.png', fullPage: true });
    await page.keyboard.press('ArrowLeft'); await expect(child).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Explore campus', exact: true })).toBeFocused();
});

test('page chooser additions use the selected destination and page removal preserves native CSRF', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Website page', exact: true }).click();
    await page.getByRole('button', { name: 'Add selected page', exact: true }).click();
    await expect(page.locator('.site-settings')).toContainText('Fictional admissions page');
    await page.getByLabel('Navigation label', { exact: true }).fill('Apply to our campus');
    await page.getByRole('button', { name: 'Save menu draft', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toBeEnabled();
    expect(f.calls[0].data.items.at(-1)).toMatchObject({ type: 'page', page_id: '1', label: 'Apply to our campus', url: null });
    await page.goto('/fixture/website-manager/?mode=pages');
    await page.getByRole('button', { name: 'Remove page', exact: true }).click();
    const form = page.locator('#site-review-body form');
    await expect(form).toHaveAttribute('method', 'post');
    await expect(form.locator('input[name="csrf_token"]')).toHaveValue('fictional-test-token');
    expect(new URL(await form.getAttribute('action')).searchParams.get('action')).toBe('destroy');
    expect(f.errors).toEqual([]);
});
