const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

async function accessible(page) {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}
for (const theme of ['campus-harbor', 'campus-quad', 'campus-horizon', 'bootstrap-business']) {
    test(`${theme}: native accordions and carousels remain independent and accessible`, async ({ page }) => {
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const unavailable = [];
        page.on('response', response => { if (response.status() >= 400) unavailable.push(new URL(response.url()).pathname); });
        await page.goto(`/fixture/website-blocks/?theme=${theme}`);
        await expect(page.getByRole('heading', { name: 'Fictional interactive information' })).toBeVisible();
        await page.keyboard.press('Tab');
        await expect(page.getByRole('link', { name: 'Skip to main content', exact: true })).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(page.locator('main')).toBeFocused();
        expect(await page.evaluate(() => {
            const ids = Array.from(document.querySelectorAll('[id]'), node => node.id);
            return ids.length === new Set(ids).size;
        })).toBe(true);
        const first = page.getByRole('button', { name: 'Question one A', exact: true });
        await expect(first).toHaveAttribute('aria-expanded', 'false');
        await first.focus(); await page.keyboard.press('Enter');
        await expect(first).toHaveAttribute('aria-expanded', 'true');
        await expect(page.getByText('Answer one A.', { exact: false })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Question two A', exact: true })).toHaveAttribute('aria-expanded', 'false');
        const second = page.getByRole('button', { name: 'Question one B', exact: true });
        await second.focus(); await page.keyboard.press('Space');
        await expect(second).toHaveAttribute('aria-expanded', 'true');
        await expect(first).toHaveAttribute('aria-expanded', 'false');
        const other = page.getByRole('button', { name: 'Question two A', exact: true });
        await other.click();
        await expect(second).toHaveAttribute('aria-expanded', 'true');
        await expect(other).toHaveAttribute('aria-expanded', 'true');

        const carousel = page.getByRole('region', { name: 'Fictional highlights one', exact: true });
        const independent = page.getByRole('region', { name: 'Fictional highlights two', exact: true });
        await expect(carousel.getByRole('heading', { name: 'Highlight one A', exact: true })).toBeVisible();
        await expect(carousel.getByRole('link', { name: 'Explore one B', exact: true })).toHaveCount(0);
        const next = carousel.getByRole('button', { name: 'Next slide', exact: true });
        await next.focus(); await page.keyboard.press('Enter');
        await expect(carousel.getByRole('status')).toHaveText('Slide 2 of 2.');
        await expect(independent.getByRole('status')).toHaveText('Slide 1 of 2.');
        await expect(next).toBeFocused();
        await expect(carousel.getByRole('link', { name: 'Explore one A', exact: true })).toHaveCount(0);
        await page.keyboard.press('ArrowLeft');
        await expect(carousel.getByRole('status')).toHaveText('Slide 1 of 2.');
        expect(await carousel.evaluate(element => {
            const instance = bootstrap.Carousel.getInstance(element);
            return instance._config.interval === false && instance._config.ride === false && instance._interval === null;
        })).toBe(true);
        expect(await carousel.locator('.carousel-item.active').evaluate(element => getComputedStyle(element).transitionDuration)).toBe('0s');
        await accessible(page);
        await page.setViewportSize({ width: 320, height: 1000 });
        await page.addStyleTag({ content: 'html { font-size: 200%; }' });
        await accessible(page);
        const bounds = await next.boundingBox();
        expect(bounds.height).toBeGreaterThanOrEqual(44);
        expect(bounds.x).toBeGreaterThanOrEqual(0);
        expect(bounds.x + bounds.width).toBeLessThanOrEqual(320);
        if (theme === 'campus-harbor') await carousel.screenshot({ path: '/tmp/etsis-block-accessibility-phone.png' });
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
        expect(errors).toEqual([]);
        expect(unavailable).toEqual([]);
        expect(await page.locator('[src], link[href]').evaluateAll(nodes => nodes.some(node => /\/themes\/[^/]+\/public\//.test(node.getAttribute('src') || node.getAttribute('href'))))).toBe(false);
    });
}
test('one-slide native carousels display retained content without redundant controls', async ({ page }) => {
    await page.goto('/fixture/website-blocks/?count=1&theme=bootstrap-business');
    for (const name of ['one', 'two']) {
        const carousel = page.getByRole('region', { name: `Fictional highlights ${name}`, exact: true });
        await expect(carousel.getByRole('heading', { name: `Highlight ${name} A`, exact: true })).toBeVisible();
        await expect(carousel.getByRole('status')).toHaveText('Slide 1 of 1.');
        await expect(carousel.getByRole('button')).toHaveCount(0);
    }
    await accessible(page);
});
test('native block settings stay bounded and accessible labels render as text', async ({ page }) => {
    await page.goto('/fixture/website-blocks/?count=999999&hostile=1');
    await expect(page.locator('.website-carousel').first().locator('.carousel-item')).toHaveCount(20);
    await expect(page.locator('.accordion').first().locator('.accordion-item')).toHaveCount(20);
    await expect(page.locator('.website-carousel').first()).toHaveAttribute('aria-label', 'Fictional <img src=x onerror=alert(1)> one');
    await expect(page.locator('img[onerror]')).toHaveCount(0);
    await expect(page.locator('script:not([src]):not([type="application/json"])')).toHaveCount(0);
});
