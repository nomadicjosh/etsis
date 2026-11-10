const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function fixture(page) {
    const calls = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : {};
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        if (path.includes('/sis/ui/data/people/') || path.includes('/sis/ui/data/students/')) {
            calls.push({ path, data });
            if (data.search === 'slow') await new Promise(resolve => setTimeout(resolve, 350));
            result = { items: [{ id: id(data.search === 'slow' ? 1 : 2), given_name: data.search, family_name: 'Fictional', institutional_id: 'S100', active: 1 }], next_after: null };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    return { calls, errors };
}
for (const dataset of ['people', 'students']) {
    test(`${dataset} waits for an AJAX search and clears personal results without another query`, async ({ page }) => {
        const f = await fixture(page);
        await page.locator(`.workspace-nav-link[data-workspace="${dataset}"]`).click();
        await expect(page.locator('#directory-results')).toContainText('Search by name or institutional ID');
        expect(f.calls).toEqual([]);
        await page.locator('#directory-search').fill('Jamie');
        await page.locator('#directory-search').press('Enter');
        await expect(page.locator('#directory-results')).toContainText('Jamie Fictional');
        expect(f.calls).toHaveLength(1);
        await page.locator('#directory-search').fill('');
        await page.locator('#directory-search').press('Enter');
        await expect(page.locator('#directory-results')).not.toContainText('Jamie Fictional');
        await expect(page.locator('#directory-results')).toContainText('Search by name or institutional ID');
        expect(f.calls).toHaveLength(1);
        await page.setViewportSize({ width: 375, height: 812 });
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => item.id)).toEqual([]);
        expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
        expect(f.errors).toEqual([]);
    });
}
test('a slower earlier AJAX search cannot replace a newer result', async ({ page }) => {
    const f = await fixture(page);
    await page.locator('.workspace-nav-link[data-workspace="people"]').click();
    await page.locator('#directory-search').fill('slow');
    await page.locator('#directory-search').press('Enter');
    await expect.poll(() => f.calls.length).toBe(1);
    await page.locator('#directory-search').fill('Jamie');
    await page.locator('#directory-search').press('Enter');
    await expect(page.locator('#directory-results')).toContainText('Jamie Fictional');
    await page.waitForTimeout(450);
    await expect(page.locator('#directory-results')).toContainText('Jamie Fictional');
    await expect(page.locator('#directory-results')).not.toContainText('slow Fictional');
    expect(f.errors).toEqual([]);
});
