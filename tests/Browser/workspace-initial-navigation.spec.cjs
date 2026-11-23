const { test, expect } = require('@playwright/test');

async function fixture(page) {
    const errors = []; let release, blocked = false;
    const pending = new Promise(resolve => { release = resolve; });
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/assets/js/workspace.js', async route => {
        if (blocked) await pending;
        await route.continue();
    });
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        let data = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') data = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/calendar/context/') data = { today: '2026-08-10', timezone: 'UTC' };
        else if (path === '/sis/calendar/view/') data = { view: 'week', from: '2026-08-10', through: '2026-08-16', today: '2026-08-10', timezone: 'UTC',
            previous: '2026-08-03', next: '2026-08-17', totals: { approved: 0, requested: 0 }, items: [],
            days: Array.from({ length: 7 }, (_, n) => ({ date: `2026-08-${10 + n}`, counts: { approved: 0, requested: 0 }, items: [] })) };
        await route.fulfill({ json: data, headers: { 'Cache-Control': 'private, no-store' } });
    });
    return { errors, block: () => { blocked = true; }, release };
}
for (const [section, title] of [['people', 'People'], ['calendar', 'Campus booking calendar'], ['holds', 'Holds & restrictions'], ['access', 'Roles & access']]) {
    test(`a direct ${section} visit never displays Overview while scripts are pending`, async ({ page }) => {
        const f = await fixture(page); f.block();
        await page.goto(`/admin/workspace/${section}/`, { waitUntil: 'commit' });
        await expect(page.locator('#workspace-loading')).toBeVisible();
        await expect(page.locator('[data-panel="overview"]')).toBeHidden();
        await expect(page.locator('#topbar-page')).toHaveText('Workspace');
        f.release(); await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('#topbar-page')).toHaveText(title);
        await expect(page.locator('#workspace-loading')).toBeHidden();
        await expect(page.locator('[data-panel="overview"]')).toBeHidden();
        expect(f.errors).toEqual([]);
    });
}

test('reloading Calendar keeps Overview hidden throughout the deferred startup', async ({ page }) => {
    const f = await fixture(page); await page.goto('/admin/workspace/calendar/');
    await expect(page.locator('.booking-week')).toBeVisible();
    f.block(); await page.reload({ waitUntil: 'commit' });
    await expect(page.locator('#workspace-loading')).toBeVisible();
    await expect(page.locator('[data-panel="overview"]')).toBeHidden();
    await expect(page.locator('#topbar-page')).toHaveText('Workspace');
    f.release(); await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('#directory-heading')).toHaveText('Campus booking calendar');
    await expect(page.locator('.booking-week')).toBeVisible();
    await expect(page.locator('[data-panel="overview"]')).toBeHidden();
    expect(f.errors).toEqual([]);
});

test('an Overview visit retains its initial dashboard instead of a workspace placeholder', async ({ page }) => {
    const f = await fixture(page); f.block(); await page.goto('/admin/', { waitUntil: 'commit' });
    await expect(page.locator('[data-panel="overview"]')).toBeVisible();
    await expect(page.locator('#workspace-loading')).toBeHidden();
    await expect(page.locator('#topbar-page')).toHaveText('Overview');
    f.release(); await page.waitForLoadState('domcontentloaded');
    expect(f.errors).toEqual([]);
});
