const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}
test('page settings shares manager branding, keeps language labels unique and submits the complete native form', async ({ page }) => {
    await page.goto('/fixture/website-page-settings/');
    await expect(page.getByRole('heading', { name: 'Page settings', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Website pages', exact: true })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByLabel('Internal page name', { exact: true })).toHaveValue('Fictional admissions <page>');
    await expect(page.getByRole('group', { name: 'English', exact: true }).getByLabel('Page title', { exact: true })).toHaveValue('Fictional admissions');
    await page.getByRole('group', { name: 'Español', exact: true }).getByLabel('Page title', { exact: true }).fill('Fictional revised Spanish title');
    await page.getByLabel('Page layout', { exact: true }).selectOption('page-template');
    await page.getByLabel('Navigation order', { exact: true }).fill('24');
    await page.route('**/admin/manager/**', async route => {
        const data = new URLSearchParams(route.request().postData());
        expect(data.get('title[es]')).toBe('Fictional revised Spanish title');
        expect(data.get('title[en]')).toBe('Fictional admissions');
        expect(data.get('layout')).toBe('page-template');
        expect(data.get('nav_position')).toBe('24');
        expect(data.get('csrf_token')).toBe('fictional-test-token');
        expect(new URL(route.request().url()).searchParams.get('action')).toBe('edit');
        await route.fulfill({ body: '<p>Fictional accepted submission</p>', contentType: 'text/html' });
    });
    await page.getByRole('button', { name: 'Save page settings', exact: true }).click();
    await expect(page.locator('body')).toContainText('Fictional accepted submission');
});
test('new page setup reflows with enlarged text and protects an unsaved draft on leaving', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/fixture/website-page-settings/?create=1');
    await expect(page.getByRole('heading', { name: 'Create a website page', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Create page', exact: true })).toBeVisible();
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-page-settings-mobile.png', fullPage: true });
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await accessible(page);
    await page.getByLabel('Internal page name', { exact: true }).fill('Fictional preserved draft');
    page.once('dialog', dialog => dialog.dismiss());
    await page.getByRole('link', { name: 'Back to website pages', exact: true }).click();
    await expect(page.getByLabel('Internal page name', { exact: true })).toHaveValue('Fictional preserved draft');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
async function editor(page) {
    const errors = [], requests = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('https://**', route => route.abort());
    page.on('request', request => { if (request.method() === 'POST') requests.push(request); });
    await page.goto('/fixture/website-editor/');
    await expect(page.locator('#phpb-loading')).toHaveClass(/loaded/);
    await expect.poll(() => page.evaluate(() => !!window.afterInitialRendering)).toBe(true);
    return { errors, requests, canvas: page.frameLocator('iframe.gjs-frame') };
}
test('native canvas keeps the layout and PHP/PHTML output fixed while HTML remains editable', async ({ page }) => {
    const f = await editor(page);
    await expect(page.getByRole('heading', { name: 'Design page Fictional editor page', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save page', exact: true })).toBeVisible();
    const permissions = await page.evaluate(() => {
        const wrapper = window.editor.getWrapper();
        const attributes = id => { const model = wrapper.find(`#${id}`)[0]; return { editable: model.get('editable'), removable: model.get('removable'), selectable: model.get('selectable') }; };
        return { layout: attributes('fixed-layout'), dynamic: attributes('dynamic-heading'), html: attributes('editable-heading') };
    });
    expect(permissions.layout).toMatchObject({ editable: false, removable: false, selectable: false });
    expect(permissions.dynamic.editable).toBe(false);
    expect(permissions.html.editable).toBe(true);
    await f.canvas.locator('#editable-heading').click();
    await expect(page.locator('#page-editor-guidance')).toContainText('Editable content:');
    await f.canvas.locator('#editable-heading').dblclick();
    await expect(f.canvas.locator('#editable-heading')).toHaveAttribute('contenteditable', 'true');
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.type('Fictional edited heading');
    await page.locator('#page-editor-header h1').click();
    await expect(f.canvas.locator('#editable-heading')).toContainText('Fictional edited heading');
    await f.canvas.locator('#dynamic-heading').click();
    await expect(page.locator('#page-editor-guidance')).toContainText('Dynamic content:');
    const setting = page.locator('.gjs-trt-trait').filter({ hasText: 'Dynamic heading' }).locator('input');
    await expect(setting).toBeVisible();
    await setting.fill('Fictional dynamic setting');
    await setting.press('Tab');
    await expect(f.canvas.locator('#dynamic-heading')).toContainText('Fictional dynamic setting');
    expect(f.requests.filter(request => new URL(request.url()).searchParams.get('action') === 'renderBlock').length).toBeGreaterThan(0);
    expect(f.requests.at(-1).headers()['x-csrf-token']).toBe('fictional-test-token');
    expect(f.errors).toEqual([]);
});
