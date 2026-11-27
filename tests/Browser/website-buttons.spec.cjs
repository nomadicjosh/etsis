const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(issue => ({ id: issue.id, targets: issue.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}
for (const theme of ['campus-harbor', 'campus-quad', 'campus-horizon', 'bootstrap-business']) {
    test(`${theme}: explicit native button behavior, link notices and keyboard access`, async ({ page }) => {
        const errors = [], external = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => { if (!request.url().startsWith('http://127.0.0.1:8127')) external.push(request.url()); });
        await page.goto(`/fixture/website-blocks/?buttons=1&theme=${theme}`);
        await page.evaluate(() => {
            window.fictionalSubmissions = 0;
            document.querySelector('form').addEventListener('submit', event => {
                event.preventDefault(); window.fictionalSubmissions++;
            });
        });
        const form = page.getByRole('form', { name: 'Fictional button behavior' });
        const action = form.getByRole('button', { name: 'Fictional action', exact: true });
        await expect(action).toHaveAttribute('type', 'button');
        await action.focus(); await page.keyboard.press('Enter'); await page.keyboard.press('Space');
        expect(await page.evaluate(() => window.fictionalSubmissions)).toBe(0);
        const submit = form.getByRole('button', { name: 'Submit fictional form', exact: true });
        await submit.focus(); await page.keyboard.press('Space');
        expect(await page.evaluate(() => window.fictionalSubmissions)).toBe(1);
        const local = form.getByRole('link', { name: 'Explore fictional information', exact: true });
        await local.focus(); await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/#page-title$/);
        expect(await page.evaluate(() => window.fictionalSubmissions)).toBe(1);
        const destination = form.getByRole('link', { name: 'Fictional external information (opens in a new tab)', exact: true });
        await expect(destination).toHaveAttribute('target', '_blank');
        await expect(destination).toHaveAttribute('rel', 'noopener noreferrer');
        await expect(destination).toHaveAttribute('href', 'https://example.invalid/programs/?level=ug&subject=arts');
        await expect(destination.locator('.bi-box-arrow-up-right')).toHaveAttribute('aria-hidden', 'true');
        const unavailable = form.getByRole('link', { name: 'Unavailable fictional destination', exact: true });
        await expect(unavailable).toHaveAttribute('aria-disabled', 'true');
        await expect(unavailable).not.toHaveAttribute('href');
        await destination.focus(); await page.keyboard.press('Tab');
        const email = form.getByRole('link', { name: 'Email fictional admissions', exact: true });
        await expect(email).toBeFocused();
        await expect(email).not.toHaveAttribute('target');
        await expect(form.locator('img,script,[onclick]')).toHaveCount(0);
        await expect(form.getByRole('button', { name: 'Fictional <img src=x onerror=alert(1)> & information', exact: true })).toHaveAttribute('type', 'button');
        await accessible(page);
        await page.setViewportSize({ width: 320, height: 1000 });
        await page.addStyleTag({ content: 'html { font-size: 200%; }' });
        await action.focus();
        await expect(action).toBeFocused();
        expect(await action.evaluate(node => getComputedStyle(node).boxShadow)).not.toBe('none');
        expect((await form.locator('[data-button-case="long"] .website-button').boundingBox()).height).toBeGreaterThanOrEqual(44);
        await accessible(page);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
        expect(external).toEqual([]); expect(errors).toEqual([]);
    });
}

test('the native preview exposes no actionable links or submit controls', async ({ page }) => {
    await page.goto('/fixture/website-blocks/?buttons=1&edit=1');
    const form = page.getByRole('form', { name: 'Fictional button behavior' });
    await expect(form.locator('a,button[type="submit"]')).toHaveCount(0);
    const buttons = await form.locator('button').evaluateAll(nodes => nodes.map(node => ({ type: node.type, disabled: node.disabled })));
    expect(buttons.length).toBeGreaterThan(0);
    expect(buttons.every(button => button.type === 'button' && button.disabled)).toBe(true);
    await expect(form.getByText('Choose a valid link destination in Settings before publishing.')).toBeVisible();
});

test('native links remain usable without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto('/fixture/website-blocks/?buttons=1');
    await page.getByRole('link', { name: 'Explore fictional information', exact: true }).click();
    await expect(page).toHaveURL(/#page-title$/);
    await expect(page.getByRole('button', { name: 'Fictional action', exact: true })).toHaveAttribute('type', 'button');
    await context.close();
});

test('native Settings rerender button labels and destinations while keeping the canvas inactive', async ({ page }) => {
    const errors = [], requests = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => { if (request.method() === 'POST') requests.push(request); });
    await page.route('https://**', route => route.abort());
    await page.goto('/fixture/website-editor/?buttons=1');
    await expect(page.locator('#phpb-loading')).toHaveClass(/loaded/);
    await expect.poll(() => page.evaluate(() => !!window.afterInitialRendering)).toBe(true);
    const canvas = page.frameLocator('iframe.gjs-frame');
    await expect(canvas.locator('.website-button').first()).toBeVisible();
    await canvas.getByRole('link', { name: 'Explore fictional information', exact: true }).locator('..').click();
    const label = page.locator('.gjs-trt-trait').filter({ hasText: 'Visible label (up to 200 characters)' }).locator('input');
    await expect(label).toBeVisible();
    await label.fill('Fictional revised link'); await label.press('Tab');
    await expect(canvas.getByRole('link', { name: 'Fictional revised link', exact: true })).toHaveAttribute('aria-disabled', 'true');
    const url = page.locator('.gjs-trt-trait').filter({ hasText: 'Link destination (local path, HTTP(S), email or telephone)' }).locator('input');
    await url.fill('/fictional-programs/?view=all&level=ug'); await url.press('Tab');
    await expect.poll(() => requests.filter(request => new URL(request.url()).searchParams.get('action') === 'renderBlock').length).toBeGreaterThanOrEqual(2);
    await expect(canvas.locator('.website-button[href],.website-button[type="submit"]')).toHaveCount(0);
    const saved = await page.evaluate(() => window.pageBlocks[window.currentLanguage]['IDbutton-local'].settings.attributes);
    expect(saved['button-text']).toBe('Fictional revised link');
    expect(saved['button-url']).toBe('/fictional-programs/?view=all&level=ug');
    expect(requests.every(request => request.headers()['x-csrf-token'] === 'fictional-test-token')).toBe(true);
    expect(errors).toEqual([]);
});
