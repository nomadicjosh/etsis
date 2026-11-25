const { test, expect } = require('@playwright/test');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const AxeBuilder = require('@axe-core/playwright').default;
const program = '01K00000000000000000000001', term = '01K00000000000000000000002';
async function accessible(page) {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}
for (const [mode, status] of [['policy-closed', 403], ['policy-domain', 422], ['policy-unavailable', 503], ['target', 429]]) {
    test(`${mode} keeps recovery private and retains only application hints`, async ({ page }) => {
        const result = spawnSync(process.env.ETSIS_TEST_PHP, ['-d', 'memory_limit=64M',
            'tests/Support/RegistrationPauseFixture.php', mode], {
            cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 5000,
            maxBuffer: 256 * 1024, env: { ...process.env, XDEBUG_MODE: 'off' },
        });
        expect(result.status, result.stderr).toBe(0);
        const metadata = JSON.parse(result.stderr);
        await page.route('**/fixture/registration-policy/', route => route.fulfill({ status,
            headers: metadata.headers, body: result.stdout }));
        const response = await page.goto('/fixture/registration-policy/');
        expect(response.status()).toBe(status);
        expect(response.headers()['cache-control']).toContain('private, no-store');
        const login = page.getByRole('link', { name: 'Sign in to your account', exact: true });
        const loginUrl = new URL(await login.getAttribute('href'), page.url());
        expect(loginUrl.pathname).toBe('/staff-access/');
        expect(Object.fromEntries(loginUrl.searchParams)).toEqual({ continue: 'apply', program, term });
        await page.keyboard.press('Tab');
        await expect(page.getByRole('link', { name: 'Skip to account information' })).toBeFocused();
        await page.keyboard.press('Tab'); await expect(login).toBeFocused();
        if (mode === 'policy-closed') {
            await expect(page.getByRole('link', { name: 'Return to account creation', exact: true })).toHaveCount(0);
            await expect(page.getByText(/Public account creation is closed/)).toBeVisible();
        } else {
            const back = page.getByRole('link', { name: 'Return to account creation', exact: true });
            const backUrl = new URL(await back.getAttribute('href'), page.url());
            expect(backUrl.pathname).toBe('/register/');
            expect(Object.fromEntries(backUrl.searchParams)).toEqual({ program, term });
        }
        await accessible(page);
        await page.setViewportSize({ width: 320, height: 812 }); await accessible(page);
        await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
        await accessible(page);
        expect(await page.locator('h1').evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
        if (mode === 'policy-domain') await page.screenshot({ path: '/tmp/etsis-registration-domain-phone.png' });
        for (const value of ['PRIVATE', 'fictional-private', 'Fictional-private-password', '192.0.2.10']) {
            expect(await page.content()).not.toContain(value);
        }
        expect(await page.locator('script, form, input').count()).toBe(0);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    });
}
for (const theme of ['campus-harbor', 'campus-quad', 'campus-horizon']) {
    test(`${theme} explains exact domains without losing form or application context`, async ({ page }) => {
        await page.goto(`/register/?guest=1&domains=1&theme=${theme}&program=${program}&term=${term}`);
        const domains = page.locator('#campus-registration-domains');
        await expect(domains).toContainText('institution.example');
        await expect(page.getByLabel('Email address', { exact: true })).toHaveAttribute('aria-describedby', 'campus-registration-domains');
        await expect(page.locator('input[name="program"]')).toHaveValue(program);
        await expect(page.locator('input[name="term"]')).toHaveValue(term);
        await page.getByLabel('Email address', { exact: true }).fill('fictional@institution.example');
        const login = page.getByRole('link', { name: 'Sign in to apply', exact: true });
        const target = new URL(await login.getAttribute('href'), page.url());
        expect(Object.fromEntries(target.searchParams)).toEqual({ continue: 'apply', program, term });
        await page.setViewportSize({ width: 320, height: 812 }); await accessible(page);
        await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; }); await accessible(page);
        const skip = page.locator('.campus-skip');
        expect(await skip.evaluate(el => el.getBoundingClientRect().bottom <= 0)).toBe(true);
        await skip.focus();
        await expect(skip).toBeFocused();
        await expect(skip).toBeInViewport({ ratio: 1 });
        await page.getByLabel('Email address', { exact: true }).focus();
        await expect.poll(() => skip.evaluate(el => el.getBoundingClientRect().bottom)).toBeLessThanOrEqual(0);
        await expect(skip).not.toBeInViewport();
        await page.evaluate(() => new Promise(requestAnimationFrame));
        if (theme === 'campus-harbor') {
            await domains.evaluate(el => el.scrollIntoView({ block: 'start' }));
            await page.screenshot({ path: '/tmp/etsis-registration-domains-final-phone.png' });
        }
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    });
    test(`${theme} sign-in stays usable when self-signup is closed`, async ({ page }) => {
        await page.goto(`/login/?guest=1&registration=closed&theme=${theme}&continue=apply&program=${program}`);
        await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toBeVisible();
        await expect(page.getByLabel('Email address', { exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Create your campus account', exact: true })).toHaveCount(0);
        await expect(page.locator('input[name="program"]')).toHaveValue(program);
        await page.setViewportSize({ width: 320, height: 812 }); await accessible(page);
    });
}
