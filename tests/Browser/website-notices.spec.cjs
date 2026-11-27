const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const notice = (page, tone) => page.locator('.website-notice').filter({ hasText: `Retained fictional ${tone} body.` });
const close = (page, tone) => notice(page, tone).getByRole('button', { name: `Dismiss notice: Fictional ${tone} notice`, exact: true });

async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(issue => ({ id: issue.id, targets: issue.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}
for (const theme of ['campus-harbor', 'campus-quad', 'campus-horizon', 'bootstrap-business']) {
    test(`${theme}: published notices preserve saved content, independent dismissal and keyboard flow`, async ({ page }) => {
        const errors = [], writes = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => { if (request.method() !== 'GET') writes.push(request.url()); });
        await page.goto(`/fixture/website-blocks/?notices=1&theme=${theme}`);
        await expect(page.locator('.website-notice[role="note"]')).toHaveCount(9);
        await expect(page.locator('.website-notice[role="alert"],.website-notice[aria-live]')).toHaveCount(0);
        const labels = await page.locator('.website-notice').evaluateAll(nodes => nodes.map(node => ({
            id: node.getAttribute('aria-labelledby'),
            owned: node.contains(document.getElementById(node.getAttribute('aria-labelledby'))),
        })));
        expect(new Set(labels.map(label => label.id)).size).toBe(9);
        expect(labels.every(label => label.owned)).toBe(true);
        const earlier = page.locator('.website-notice').filter({ hasText: 'Retained earlier notice body.' });
        await expect(earlier.getByRole('button')).toHaveCount(0);
        await expect(earlier).toContainText('Warning');
        await accessible(page);
        await close(page, 'primary').focus(); await page.keyboard.press('Enter');
        await expect(notice(page, 'primary')).toHaveCount(0);
        await expect(close(page, 'secondary')).toBeFocused();
        const after = page.getByRole('link', { name: 'After the fictional notices', exact: true });
        await after.focus();
        await notice(page, 'secondary').evaluate(node => window.bootstrap.Alert.getOrCreateInstance(node).close());
        await expect(after).toBeFocused();
        await notice(page, 'success').evaluate(node => node.addEventListener('close.bs.alert', event => event.preventDefault(), { once: true }));
        await close(page, 'success').focus(); await page.keyboard.press('Space');
        await expect(notice(page, 'success')).toHaveCount(1);
        await expect(close(page, 'success')).toBeFocused();
        await page.keyboard.press('Space');
        await expect(notice(page, 'success')).toHaveCount(0);
        await expect(close(page, 'danger')).toBeFocused();
        await page.setViewportSize({ width: 320, height: 1000 });
        await page.addStyleTag({ content: 'html { font-size: 200%; }' });
        await close(page, 'danger').focus();
        expect(await close(page, 'danger').evaluate(node => getComputedStyle(node).boxShadow)).not.toBe('none');
        const bounds = await close(page, 'danger').boundingBox();
        expect(bounds.width).toBeGreaterThanOrEqual(44); expect(bounds.height).toBeGreaterThanOrEqual(44);
        await accessible(page);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
        expect(writes).toEqual([]); expect(errors).toEqual([]);
        await page.reload();
        await expect(page.locator('.website-notice')).toHaveCount(9);
        await expect(close(page, 'primary')).toBeVisible();
    });
}

test('invalid settings and hostile labels remain escaped while saved HTML content stays independent', async ({ page }) => {
    await page.goto('/fixture/website-blocks/?notices=1&hostile=1');
    const first = notice(page, 'primary');
    await expect(first).toHaveClass(/alert-primary/);
    await expect(first.locator('img,[onclick],script')).toHaveCount(0);
    await expect(first.locator('.website-notice-label')).toContainText('Fictional <img src=x onerror=alert(1)> & notice');
    await expect(first.getByRole('link', { name: 'Read primary information', exact: true })).toHaveAttribute('href', '#page-title');
    await accessible(page);
});

test('notices retain readable content and offer no inactive dismissal without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto('/fixture/website-blocks/?notices=1');
    await expect(page.locator('.website-notice')).toHaveCount(9);
    await expect(page.locator('.website-notice button:visible')).toHaveCount(0);
    await page.getByRole('link', { name: 'Read primary information', exact: true }).click();
    await expect(page).toHaveURL(/#page-title$/);
    await context.close();
});

test('dismissal controls stay hidden if the Bootstrap bundle is unavailable', async ({ page }) => {
    await page.route('**/assets/vendor/bootstrap*.js', route => route.fulfill({ contentType: 'text/javascript', body: '' }));
    await page.goto('/fixture/website-blocks/?notices=1');
    await expect(notice(page, 'primary')).toContainText('Retained fictional primary body.');
    await expect(page.locator('.website-notice button:visible')).toHaveCount(0);
});

test('the last dismissed notice returns to the page heading when no other control remains', async ({ page }) => {
    await page.goto('/fixture/website-blocks/?notices=1');
    await page.evaluate(() => {
        const current = [...document.querySelectorAll('.website-notice')].find(node => node.textContent.includes('Retained fictional primary body.'));
        for (const node of document.querySelectorAll('.website-notice')) if (node !== current) node.remove();
        for (const node of document.querySelectorAll('a,button,input,select,textarea,[tabindex],summary')) {
            if (!current.contains(node) && !node.contains(current)) node.remove();
        }
    });
    await close(page, 'primary').focus(); await page.keyboard.press('Enter');
    await expect(page.locator('#page-title')).toBeFocused();
    await expect(page.locator('#page-title')).toHaveAttribute('tabindex', '-1');
});

test('native preview and Settings changes preserve the existing editable alert-text child', async ({ page }) => {
    const errors = [], writes = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => { if (request.method() === 'POST') writes.push(request); });
    await page.route('https://**', route => route.abort());
    await page.goto('/fixture/website-editor/?notices=1');
    await expect(page.locator('#phpb-loading')).toHaveClass(/loaded/);
    await expect.poll(() => page.evaluate(() => !!window.afterInitialRendering)).toBe(true);
    const canvas = page.frameLocator('iframe.gjs-frame');
    const first = canvas.locator('.website-notice').filter({ hasText: 'Retained fictional primary body.' });
    await expect(first).toBeVisible();
    await expect(canvas.locator('.website-notice button')).toHaveCount(0);
    const body = first.locator('.website-notice-body p');
    await body.dblclick(); await expect(body).toHaveAttribute('contenteditable', 'true');
    await page.keyboard.press('ControlOrMeta+A'); await page.keyboard.type('Fictional revised editable notice body.');
    await page.locator('#page-editor-header h1').click();
    const edited = canvas.locator('.website-notice').filter({ hasText: 'Fictional revised editable notice body.' });
    await edited.locator('.website-notice-label').click();
    const label = page.locator('.gjs-trt-trait').filter({ hasText: 'Notice label (up to 160 characters)' }).locator('input');
    await label.fill('Fictional revised notice label'); await label.press('Tab');
    await expect(edited).toContainText('Fictional revised notice label');
    const tone = page.locator('.gjs-trt-trait').filter({ hasText: 'Context' }).locator('select');
    await tone.selectOption('alert-danger');
    await expect(edited).toHaveClass(/alert-danger/);
    await expect(edited).toContainText('Fictional revised editable notice body.');
    await expect(edited.locator('button')).toHaveCount(0);
    const saved = await page.evaluate(() => window.pageBlocks[window.currentLanguage]['IDnotice-primary']);
    expect(saved.settings.attributes['alert-label']).toBe('Fictional revised notice label');
    expect(saved.settings.attributes['alert-context']).toBe('alert-danger');
    expect(saved.blocks['alert-text'].html).toContain('Fictional revised editable notice body.');
    expect(writes.length).toBeGreaterThanOrEqual(2);
    expect(writes.every(request => request.headers()['x-csrf-token'] === 'fictional-test-token')).toBe(true);
    expect(errors).toEqual([]);
});
