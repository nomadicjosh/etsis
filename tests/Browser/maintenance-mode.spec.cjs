const { test, expect } = require('@playwright/test');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const AxeBuilder = require('@axe-core/playwright').default;

test('maintenance response keeps its CSP, configured sign-in link, keyboard focus and narrow reflow', async ({ page }) => {
    const result = spawnSync(process.env.ETSIS_TEST_PHP, [
        '-d', 'memory_limit=96M', 'tests/Support/MaintenanceFrontController.php', 'html',
    ], {
        cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 5000,
        maxBuffer: 256 * 1024, env: { ...process.env, XDEBUG_MODE: 'off' },
    });
    expect(result.status, result.stderr).toBe(0);
    const metadata = JSON.parse(result.stderr);
    expect(metadata.status).toBe(503);
    expect(metadata.booted).toBe(false);
    await page.route('**/*', route => route.fulfill({ status: 503, headers: metadata.headers, body: result.stdout }));
    const response = await page.goto('https://maintenance.example.invalid/');
    expect(response.status()).toBe(503);
    expect(response.headers()['retry-after']).toBe('120');
    expect(response.headers()['cache-control']).toContain('no-store');
    await expect(page.getByRole('heading', { name: 'Temporarily unavailable', exact: true })).toBeVisible();
    const signIn = page.getByRole('link', { name: 'Super administrator sign-in', exact: true });
    await expect(signIn).toHaveAttribute('href', '/staff-access/');
    expect(await page.locator('main').evaluate(el => getComputedStyle(el).borderTopStyle)).toBe('solid');
    await page.keyboard.press('Tab');
    await expect(signIn).toBeFocused();
    expect(await signIn.evaluate(el => getComputedStyle(el).outlineStyle)).toBe('solid');
    const accessible = async () => {
        const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(result.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    };
    await accessible();
    await page.screenshot({ path: '/tmp/etsis-maintenance-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible();
    await page.screenshot({ path: '/tmp/etsis-maintenance-phone.png' });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    expect(await page.locator('html').evaluate(el => getComputedStyle(el).fontSize)).toBe('32px');
    await accessible();
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
