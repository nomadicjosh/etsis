const { test, expect } = require('@playwright/test');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const AxeBuilder = require('@axe-core/playwright').default;

for (const [mode, status, heading] of [
    ['limited', 429, 'Please wait before trying again'],
    ['busy', 503, 'Account creation is temporarily unavailable'],
]) {
    test(`registration ${mode} response has accessible private recovery and phone reflow`, async ({ page }) => {
        const result = spawnSync(process.env.ETSIS_TEST_PHP, [
            '-d', 'memory_limit=64M', 'tests/Support/RegistrationPauseFixture.php', mode,
        ], {
            cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 5000,
            maxBuffer: 256 * 1024, env: { ...process.env, XDEBUG_MODE: 'off' },
        });
        expect(result.status, result.stderr).toBe(0);
        const metadata = JSON.parse(result.stderr);
        expect(metadata.status).toBe(status);
        await page.route('**/fixture/registration-pause/', route => route.fulfill({
            status, headers: metadata.headers, body: result.stdout,
        }));
        const response = await page.goto('/fixture/registration-pause/');
        expect(response.status()).toBe(status);
        expect(response.headers()['cache-control']).toContain('private, no-store');
        expect(Number(response.headers()['retry-after'])).toBeGreaterThan(0);
        expect(response.headers()['content-security-policy']).toContain("script-src 'none'");
        await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible();
        const signIn = page.getByRole('link', { name: 'Sign in to your account', exact: true });
        const back = page.getByRole('link', { name: 'Return to account creation', exact: true });
        await expect(signIn).toHaveAttribute('href', '/staff-access/');
        await expect(back).toHaveAttribute('href', '/register/');
        await page.keyboard.press('Tab');
        await expect(page.getByRole('link', { name: 'Skip to account information' })).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(signIn).toBeFocused();
        expect(await signIn.evaluate(el => getComputedStyle(el).boxShadow !== 'none'
            || getComputedStyle(el).outlineStyle !== 'none')).toBe(true);
        const accessible = async () => {
            const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
            expect(result.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        };
        await accessible();
        if (mode === 'limited') await page.screenshot({ path: '/tmp/etsis-registration-desktop.png' });
        await page.setViewportSize({ width: 320, height: 812 });
        await accessible();
        if (mode === 'busy') await page.screenshot({ path: '/tmp/etsis-registration-phone.png' });
        await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
        await accessible();
        expect(await page.locator('html').evaluate(el => getComputedStyle(el).fontSize)).toBe('32px');
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
        for (const privateValue of ['192.0.2.10', 'fictional-private', 'Fictional-private-password', 'fictional-key']) {
            expect(await page.content()).not.toContain(privateValue);
        }
        expect(await page.locator('script, form, input').count()).toBe(0);
    });
}
