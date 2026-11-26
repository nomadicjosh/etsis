const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
test.use({ serviceWorkers: 'block' });

async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}
async function fictionalFrames(page) {
    const external = [];
    page.on('request', request => { if (!request.url().startsWith('http://127.0.0.1:8127')) external.push(request.url()); });
    await page.context().route(/.*/, route => {
        if (new URL(route.request().url()).origin === 'http://127.0.0.1:8127') return route.continue();
        return route.fulfill({ contentType: 'text/html', body:
            '<!doctype html><html lang="en"><title>Fictional external content</title><body><main>'
            + '<button type="button">Fictional provider control</button></main></body></html>' });
    });
    return external;
}
for (const theme of ['campus-harbor', 'campus-quad', 'campus-horizon', 'bootstrap-business']) {
    test(`${theme}: external media loads only on request with independent keyboard controls`, async ({ page }) => {
        const external = await fictionalFrames(page);
        const errors = []; page.on('pageerror', error => errors.push(error.message));
        const consoleErrors = []; page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text()); });
        await page.goto(`/fixture/website-blocks/?embeds=1&theme=${theme}`);
        const video = page.locator('.website-embed-video');
        const map = page.locator('.website-embed-map');
        await expect(video.getByRole('button', { name: 'Load video', exact: true })).toBeVisible();
        expect(external).toEqual([]);
        await expect(page.locator('.website-embed iframe')).toHaveCount(0);
        await expect(map).toContainText('Example Hall, 100 Fictional Lane');
        await accessible(page);
        const toggle = video.locator('[data-website-embed]');
        await toggle.focus(); await page.keyboard.press('Enter');
        await expect(toggle).toBeFocused();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(video.getByRole('status')).toHaveText('External content enabled. Use Tab to reach its controls.');
        await expect(video.locator('iframe')).toHaveAttribute('title', 'Fictional campus tour');
        await expect(video.locator('iframe')).toHaveAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        await expect(video.locator('iframe')).toHaveAttribute('src', /www.youtube-nocookie.com\/embed\/abcdefghijk\?.*autoplay=0/);
        await expect(map.locator('iframe')).toHaveCount(0);
        await expect.poll(() => external.length).toBe(1);
        await expect.poll(async () => ({
            errors: consoleErrors,
            body: await video.frameLocator('iframe').locator('body').innerText().catch(() => 'Frame body unavailable')
        })).toEqual({ errors: [], body: 'Fictional provider control' });
        await page.keyboard.press('Tab');
        await expect(video.getByRole('link')).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(video.frameLocator('iframe').getByRole('button', { name: 'Fictional provider control' })).toBeFocused();
        await page.keyboard.press('Shift+Tab'); await page.keyboard.press('Shift+Tab');
        await expect(toggle).toBeFocused();
        await page.keyboard.press('Space');
        await expect(video.locator('iframe')).toHaveCount(0);
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(video.getByRole('status')).toHaveText('External content hidden.');
        await page.setViewportSize({ width: 320, height: 1000 });
        await page.addStyleTag({ content: 'html { font-size: 200%; }' });
        const mapToggle = map.locator('[data-website-embed]');
        await mapToggle.focus(); await page.keyboard.press('Space');
        await expect(mapToggle).toBeFocused();
        await expect(map.locator('iframe')).toHaveAttribute('title', 'Fictional campus location');
        const bounds = await map.locator('iframe').boundingBox();
        expect(bounds.x).toBeGreaterThanOrEqual(0);
        expect(bounds.x + bounds.width).toBeLessThanOrEqual(320);
        expect(bounds.height).toBeGreaterThanOrEqual(240);
        await accessible(page);
        expect(external).toHaveLength(2);
        expect(external.every(url => /^https:\/\/www\.(youtube-nocookie\.com\/embed\/|google\.com\/maps\/embed\?)/.test(url))).toBe(true);
        await expect(video.locator('iframe')).toHaveCount(0);
        await expect(map.getByRole('link')).toHaveAttribute('href', /maps\/search\/\?api=1&query=Example/);
        if (theme === 'campus-harbor') {
            await page.addStyleTag({ content: 'html { font-size: 100%; }' });
            await map.scrollIntoViewIfNeeded();
            await page.screenshot({ path: '/tmp/etsis-embeds-phone.png' });
        }
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
        expect(errors).toEqual([]);
    });
}
test('without JavaScript the native embeds keep readable alternatives and safe direct links', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false, serviceWorkers: 'block' });
    const page = await context.newPage();
    const external = await fictionalFrames(page);
    await page.goto('/fixture/website-blocks/?embeds=1');
    await expect(page.locator('.website-embed iframe')).toHaveCount(0);
    await expect(page.locator('.website-embed').getByRole('button')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Open video on YouTube' })).toHaveAttribute('href', 'https://www.youtube.com/watch?v=abcdefghijk&t=12');
    await expect(page.getByRole('link', { name: 'Open location in Google Maps' })).toBeVisible();
    await expect(page.locator('.website-embed-video')).toContainText('Tour summary:');
    expect(external).toEqual([]);
    await context.close();
});
test('unsafe saved URLs are inactive and saved titles and descriptions remain plain text', async ({ page }) => {
    const external = await fictionalFrames(page);
    await page.goto('/fixture/website-blocks/?embeds=1&hostile=1');
    await expect(page.locator('.website-embed').getByRole('button')).toHaveCount(0);
    await expect(page.locator('.website-embed').getByRole('link')).toHaveCount(0);
    await expect(page.locator('.website-embed iframe, .website-embed img, .website-embed script')).toHaveCount(0);
    await expect(page.locator('.website-embed-video figcaption')).toHaveText('<img src=x onerror=alert(1)>');
    await expect(page.locator('.website-embed-map .website-embed-description')).toHaveText('<script>alert(1)</script>');
    expect(external).toEqual([]);
    await accessible(page);
});
test('the native preview contains no activation controls or remote requests', async ({ page }) => {
    const external = await fictionalFrames(page);
    await page.goto('/fixture/website-blocks/?embeds=1&edit=1');
    await expect(page.getByText('External content is inactive in the page designer.')).toHaveCount(2);
    await expect(page.locator('.website-embed iframe, .website-embed button, .website-embed a')).toHaveCount(0);
    expect(external).toEqual([]);
});
test('a blocked provider retains its direct alternative without claiming a successful load', async ({ page }) => {
    const external = await fictionalFrames(page);
    await page.route('**/fixture/website-blocks/**', async route => {
        const response = await route.fetch();
        return route.fulfill({ response, headers: { ...response.headers(),
            'content-security-policy': "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
                + "img-src 'self' data:; font-src 'self'; frame-src 'none'" } });
    });
    await page.goto('/fixture/website-blocks/?embeds=1');
    const video = page.locator('.website-embed-video');
    await video.getByRole('button', { name: 'Load video', exact: true }).click();
    await expect(video.getByRole('status')).toHaveText('External content enabled. Use Tab to reach its controls.');
    await expect(video.getByRole('link', { name: 'Open video on YouTube' })).toBeVisible();
    await expect(video).toContainText('If embedded content does not appear, use the direct link above.');
    expect(external).toEqual([]);
    await video.getByRole('button', { name: 'Hide video', exact: true }).click();
    await expect(video.locator('iframe')).toHaveCount(0);
    await expect(video.getByRole('button', { name: 'Load video', exact: true })).toBeFocused();
});
test('native media Settings rerender escaped titles and retain their saved configuration without loading providers', async ({ page }) => {
    const external = await fictionalFrames(page);
    const requests = []; page.on('request', request => { if (request.method() === 'POST') requests.push(request); });
    await page.goto('/fixture/website-editor/?embeds=1');
    await expect(page.locator('#phpb-loading')).toHaveClass(/loaded/);
    await expect.poll(() => page.evaluate(() => !!window.afterInitialRendering)).toBe(true);
    expect(await page.evaluate(() => window.editor.getConfig().telemetry)).toBe(false);
    const canvas = page.frameLocator('iframe.gjs-frame');
    await expect(canvas.getByText('External content is inactive in the page designer.')).toHaveCount(2);
    requests.length = 0;
    await canvas.locator('.website-embed-video figcaption').click();
    const title = page.locator('.gjs-trt-trait').filter({ hasText: 'Video title' }).locator('input');
    await title.fill('Fictional revised <video>'); await title.press('Tab');
    await expect(canvas.locator('.website-embed-video figcaption')).toHaveText('Fictional revised <video>');
    await canvas.locator('.website-embed-map figcaption').click();
    const address = page.locator('.gjs-trt-trait').filter({ hasText: 'Street address or location description' }).locator('input');
    await address.fill('Revised fictional address'); await address.press('Tab');
    await expect(canvas.locator('.website-embed-map')).toContainText('Revised fictional address');
    await expect(canvas.locator('.website-embed iframe, .website-embed button')).toHaveCount(0);
    const settings = await page.evaluate(() => window.pageBlocks[window.currentLanguage]);
    const youtube = settings.IDyoutube.settings.attributes;
    const map = settings['IDgoogle-map'].settings.attributes;
    expect(youtube.youtube_title).toBe('Fictional revised <video>');
    expect(youtube.youtube_embed_url).toBe('https://www.youtube.com/embed/abcdefghijk?start=12&autoplay=1');
    expect(map.map_address).toBe('Revised fictional address');
    expect(requests.length).toBeGreaterThanOrEqual(2);
    expect(requests.every(request => request.headers()['x-csrf-token'] === 'fictional-test-token')).toBe(true);
    // The native editor already supplies these two icon stylesheets. They are mocked here too.
    const existingIconStyles = new Set([
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css'
    ]);
    expect(external.filter(url => !existingIconStyles.has(url))).toEqual([]);
});
